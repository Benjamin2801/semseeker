<?php

namespace App\Wikipedia;

use App\Search\KeywordFilter;
use App\Search\Lexicon;
use App\Suggest\SuggestionMerger;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WikipediaExplorer
{
    private const MAX_ARTICLES = 3;

    private const SEARCH_RESULTS = 5;

    private const INTRO_LINKS_READ = 30;
    private const INTRO_TERMS = 12;

    private const RELATED_LINKS_READ = 20;
    private const RELATED_TERMS = 8;

    private const TYPES = 10;

    // Les sous-classes partent après le choix de l'article principal, quand le budget est presque épuisé :
    // avec ce qui reste (souvent 0,3 s), Wikidata ne répondait pas une fois sur deux.
    private const TYPES_MIN_SECONDS = 2;

    private const MAX_CONCEPT_ARTICLES = 2;

    private const VIEWS_DAYS = 30;

    private const BUDGET = 4;

    private float $deadline = 0.0;

    private const RELATED_SECTIONS = ['Articles connexes', 'Voir aussi', 'See also', 'Related articles'];

    private const APPARATUS_SECTIONS = ['notes', 'references', 'notes et references', 'bibliographie', 'liens externes', 'voir aussi',
        'articles connexes', 'annexes', 'sources', 'external links', 'see also', 'further reading', 'bibliography', 'related articles'];

    // Wikimedia demande à chaque outil de s'identifier.
    private const USER_AGENT = 'SemSeeker/1.0 (https://net-labs.fr/semseeker)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly KeywordFilter $filter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param (\Closure(): ?string)|null $subject */
    public function explore(string $seed, string $lang, ?\Closure $subject = null): ?WikipediaExploration
    {
        $this->deadline = microtime(true) + self::BUDGET;
        $types = null;
        $texts = [];
        try {
            $articles = $this->articles($seed, $lang);
            if ([] === $articles) {
                return null;
            }

            $titles = array_column($articles, 0);
            foreach ($titles as $title) {
                $texts[$title] = $this->request($lang, ['action' => 'parse', 'page' => $title, 'prop' => 'wikitext']);
            }
            $daily = $this->dailyViews($titles, $lang);
            $subjectWords = null !== $subject ? $this->significantWords((string) $subject(), $lang) : [];
            $names = fn (array $a): bool => [] !== $subjectWords && [] !== array_intersect($this->significantWords($a[0], $lang), $subjectWords);
            usort($articles, static fn (array $a, array $b): int => [$b[2], $names($b), $b[3], array_sum($daily[$b[0]])] <=> [$a[2], $names($a), $a[3], array_sum($daily[$a[0]])]);
            $titles = array_column($articles, 0);
            [$main, $item] = $articles[0];
            $types = null !== $item ? $this->requestTypes($item, $lang) : null;

            $wikitext = (string) ($texts[$main]->toArray()['parse']['wikitext'] ?? '');
            foreach ($texts as $response) {
                $response->cancel();
            }
            $intro = \array_slice(self::links(self::intro($wikitext)), 0, self::INTRO_LINKS_READ);

            $related = \array_slice(self::links(self::relatedSection($wikitext), keepTemplates: true), 0, self::RELATED_LINKS_READ);

            $resolved = $this->resolve([...$intro, ...$related], $lang);
            $daily += $this->dailyViews(array_values($resolved), $lang);
            $viewsOf = static fn (string $target): int => array_sum($daily[$resolved[$target] ?? $target] ?? []);

            $seen = [SuggestionMerger::key($seed) => true];
            foreach ($titles as $title) {
                $seen[SuggestionMerger::key((string) preg_replace('/\s*\([^)]*\)$/', '', $title))] = true;
            }

            return new WikipediaExploration(
                array_map(static fn (array $a): WikipediaArticle => new WikipediaArticle($a[0], $lang, $viewsOf($a[0]), $daily[$a[0]] ?? [], !$a[4], coverage: $a[5]), $articles),
                self::terms(self::byViews($intro, $viewsOf), self::INTRO_TERMS, $seen),
                self::terms(self::byViews($related, $viewsOf), self::RELATED_TERMS, $seen),
                self::terms(null !== $types ? $this->readTypes($types) : [], self::TYPES, $seen),
                self::sections($wikitext),
            );
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Wikipédia indisponible', ['error' => $e->getMessage()]);

            foreach ([$types, ...array_values($texts)] as $response) {
                $response?->cancel();
            }

            return null;
        }
    }

    /** @return list<array{string, ?string, int, int, bool, float}> */
    private function articles(string $seed, string $lang): array
    {
        $words = $this->significantWords($seed, $lang);
        $parts = $this->parts($seed, $lang);
        if ([] === $words || [] === $parts) {
            return [];
        }

        $properties = ['prop' => 'pageprops', 'ppprop' => 'disambiguation|wikibase_item', 'redirects' => 1];
        $exact = $this->request($lang, ['action' => 'query', 'titles' => implode('|', $parts)] + $properties);
        $search = $this->request($lang, ['action' => 'query', 'generator' => 'search', 'gsrsearch' => $seed, 'gsrlimit' => self::SEARCH_RESULTS] + $properties);

        try {
            $data = $exact->toArray();
            $results = $search->toArray()['query']['pages'] ?? [];
        } finally {
            $exact->cancel();
            $search->cancel();
        }

        $candidates = [];
        $resolved = self::resolved($data);
        $pages = array_column($data['query']['pages'] ?? [], null, 'title');
        foreach ($parts as $part) {
            $page = $pages[$resolved[$part] ?? $part] ?? null;
            if (null !== $page) {
                $candidates[] = [$page, array_intersect($this->significantWords($part, $lang), $words)];
            }
        }
        usort($results, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        foreach ($results as $page) {
            $candidates[] = [$page, array_intersect($this->significantWords($page['title'], $lang), $words)];
        }

        $candidates = array_filter($candidates, static fn (array $c): bool => !isset($c[0]['missing']) && !isset($c[0]['pageprops']['disambiguation']) && [] !== $c[1]);
        usort($candidates, static fn (array $a, array $b): int => \count($b[1]) <=> \count($a[1]));

        $articles = [];
        $covered = [];
        foreach ($candidates as [$page, $matched]) {
            if (\count($articles) < self::MAX_ARTICLES && !isset($articles[$page['title']]) && [] !== array_diff($matched, $covered)) {
                $articles[$page['title']] = [$page['title'], $page['pageprops']['wikibase_item'] ?? null, \count($matched), $this->specificity($matched, $words, $lang), self::generic($matched, $words, $lang), self::coverage($matched, $words)];
                $covered = [...$covered, ...$matched];
            }
        }

        return array_values($articles);
    }

    /** @param list<string> $concepts */
    public function withConcepts(?WikipediaExploration $exploration, array $concepts, string $lang): ?WikipediaExploration
    {
        if ([] === $concepts) {
            return $exploration;
        }
        $this->deadline = microtime(true) + self::BUDGET;
        $known = array_map(static fn (WikipediaArticle $a): string => $a->title, $exploration->articles ?? []);

        try {
            $data = $this->request($lang, ['action' => 'query', 'titles' => implode('|', $concepts), 'prop' => 'pageprops', 'ppprop' => 'disambiguation', 'redirects' => 1])->toArray();
            $titles = [];
            foreach ($data['query']['pages'] ?? [] as $page) {
                if (!isset($page['missing']) && !isset($page['pageprops']['disambiguation']) && !\in_array($page['title'], $known, true)) {
                    $titles[] = (string) $page['title'];
                }
            }
            $daily = $this->dailyViews($titles, $lang);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Articles des concepts indisponibles', ['error' => $e->getMessage()]);

            return $exploration;
        }

        $added = array_map(static fn (string $t): WikipediaArticle => new WikipediaArticle($t, $lang, array_sum($daily[$t]), $daily[$t], true, true), $titles);
        usort($added, static fn (WikipediaArticle $a, WikipediaArticle $b): int => $b->views <=> $a->views);
        $added = \array_slice(array_values(array_filter($added, static fn (WikipediaArticle $a): bool => $a->views > 0)), 0, self::MAX_CONCEPT_ARTICLES);
        if ([] === $added) {
            return $exploration;
        }

        return null !== $exploration ? $exploration->withArticles([...$exploration->articles, ...$added]) : new WikipediaExploration($added);
    }

    /**
     * @param list<string> $terms
     *
     * @return array<string, int>
     */
    public function viewsOf(array $terms, string $lang): array
    {
        if ([] === $terms) {
            return [];
        }
        $this->deadline = microtime(true) + self::BUDGET;
        try {
            $resolved = $this->resolve($terms, $lang);
            $daily = $this->dailyViews(array_map(static fn (string $t): string => $resolved[$t] ?? $t, $terms), $lang);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Vues des termes indisponibles', ['error' => $e->getMessage()]);

            return [];
        }

        $views = [];
        foreach ($terms as $term) {
            $views[$term] = array_sum($daily[$resolved[$term] ?? $term] ?? []);
        }

        return $views;
    }

    /**
     * @param array<string> $matched
     * @param list<string>  $words
     */
    private static function generic(array $matched, array $words, string $lang): bool
    {
        $head = 'fr' === $lang ? ($words[0] ?? null) : ($words[\count($words) - 1] ?? null);

        return \count($words) > 1 && [$head] === array_values(array_unique($matched));
    }

    /**
     * @param array<string> $matched
     * @param list<string>  $words
     */
    // « Musique » ne couvre qu'un quart de « séminaire d'entreprise musique » : sa notoriété ne compte que pour ce quart.
    private static function coverage(array $matched, array $words): float
    {
        $length = static fn (array $list): int => array_sum(array_map('mb_strlen', array_unique($list)));

        return $length($words) > 0 ? $length($matched) / $length($words) : 1.0;
    }

    /**
     * @param non-empty-array<string> $matched
     * @param list<string>            $words
     */
    private function specificity(array $matched, array $words, string $lang): int
    {
        $positions = array_map(static fn (string $w): int => (int) array_search($w, $words, true), $matched);

        return 'fr' === $lang ? max($positions) : -min($positions);
    }

    /** @return list<string> */
    private function significantWords(string $text, string $lang): array
    {
        $modifiers = Lexicon::MODIFIERS[$lang] ?? [];

        $raw = preg_split("/[\\s'’-]+/u", (string) preg_replace('/\s*\([^)]*\)$/', '', $text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(SuggestionMerger::key(...), $raw);

        return array_values(array_unique(array_filter($words, fn (string $w): bool => '' !== $w && !\in_array($w, $modifiers, true) && !$this->filter->excludes($w, '', $lang))));
    }

    /** @return list<string> */
    private function parts(string $seed, string $lang): array
    {
        $words = preg_split('/\s+/', trim($seed), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $modifiers = Lexicon::MODIFIERS[$lang] ?? [];

        $parts = [];
        for ($length = \count($words); $length >= 1; --$length) {
            for ($start = 0; $start + $length <= \count($words); ++$start) {
                $part = \array_slice($words, $start, $length);
                $normalized = array_map(SuggestionMerger::normalize(...), $part);
                if ([] === array_diff($normalized, $modifiers) || $this->filter->excludes(implode(' ', $part), '', $lang)) {
                    continue;
                }
                $parts[] = implode(' ', $part);
            }
        }

        return $parts;
    }

    /**
     * @param list<string> $titles
     *
     * @return array<string, string>
     */
    private function resolve(array $titles, string $lang): array
    {
        $responses = array_map(
            fn (array $chunk): ResponseInterface => $this->request($lang, ['action' => 'query', 'titles' => implode('|', $chunk), 'redirects' => 1]),
            array_chunk(array_values(array_unique($titles)), 50),
        );

        $resolved = [];
        foreach ($responses as $response) {
            $resolved += self::resolved($response->toArray());
        }

        return $resolved;
    }

    /**
     * @param list<string> $titles
     *
     * @return array<string, list<int>>
     */
    private function dailyViews(array $titles, string $lang): array
    {
        $period = sprintf('%s/%s', date('Ymd', strtotime(sprintf('-%d days', self::VIEWS_DAYS))), date('Ymd', strtotime('-1 day')));
        $responses = [];
        foreach (array_unique($titles) as $title) {
            $responses[$title] = $this->httpClient->request('GET', sprintf(
                'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/%s.wikipedia/all-access/user/%s/daily/%s',
                $lang, rawurlencode(str_replace(' ', '_', $title)), $period,
            ), $this->options());
        }

        $views = [];
        foreach ($responses as $title => $response) {
            try {
                $views[$title] = array_map('intval', array_column($response->toArray()['items'] ?? [], 'views'));
            } catch (ExceptionInterface) {
                $views[$title] = [];
            }
        }

        return $views;
    }

    private function requestTypes(string $item, string $lang): ResponseInterface
    {
        $query = sprintf(
            'SELECT ?label WHERE { ?type wdt:P279 wd:%s ; wikibase:sitelinks ?links ; rdfs:label ?label . FILTER(LANG(?label) = "%s") } ORDER BY DESC(?links) LIMIT %d',
            preg_replace('/[^Q0-9]/', '', $item), preg_replace('/[^a-z]/', '', $lang), 2 * self::TYPES,
        );

        return $this->httpClient->request('GET', 'https://query.wikidata.org/sparql', ['query' => ['query' => $query, 'format' => 'json']] + $this->options(self::TYPES_MIN_SECONDS));
    }

    /** @return list<string> */
    private function readTypes(ResponseInterface $response): array
    {
        try {
            $rows = $response->toArray()['results']['bindings'] ?? [];
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Wikidata indisponible', ['error' => $e->getMessage()]);

            return [];
        }

        return array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['label']['value'] ?? ''), $rows)));
    }

    private static function intro(string $wikitext): string
    {
        return preg_split('/^==/m', $wikitext, 2)[0] ?? '';
    }

    private static function sections(string $wikitext): int
    {
        preg_match_all('/^==([^=].*?)==\s*$/m', $wikitext, $titles);

        return \count(array_filter($titles[1], static fn (string $t): bool => !\in_array(SuggestionMerger::normalize(trim($t)), self::APPARATUS_SECTIONS, true)));
    }

    private static function relatedSection(string $wikitext): string
    {
        foreach (self::RELATED_SECTIONS as $name) {
            if (preg_match('/^=+\s*'.preg_quote($name, '/').'\s*=+\s*$(.*?)(?=^=|\z)/msi', $wikitext, $match)) {
                return $match[1];
            }
        }

        return '';
    }

    /** @return list<string> */
    private static function links(string $wikitext, bool $keepTemplates = false): array
    {
        $text = (string) preg_replace('/<!--.*?-->|<ref[^>]*\/>|<ref[^>]*>.*?<\/ref>/s', '', $wikitext);
        if (!$keepTemplates) {
            do {
                $text = (string) preg_replace('/\{\{[^{}]*\}\}|\{\|[^{}]*?\|\}/s', '', $text, -1, $count);
            } while ($count > 0);
        }
        $text = (string) preg_replace('/\[\[[^\[\]|:]+:(?:[^\[\]]|\[\[[^\[\]]*\]\])*\]\]/', '', $text);

        preg_match_all('/\[\[([^\[\]|#]+)(?:#[^\[\]|]*)?(?:\|[^\[\]]*)?\]\]/', $text, $matches);

        $targets = array_filter(array_map('trim', $matches[1]), static fn (string $t): bool => !str_contains($t, ':'));

        return array_values(array_unique($targets));
    }

    /**
     * @param list<string>          $targets
     * @param \Closure(string): int $viewsOf
     *
     * @return list<string>
     */
    private static function byViews(array $targets, \Closure $viewsOf): array
    {
        $order = array_flip($targets);
        usort($targets, static fn (string $a, string $b): int => [$viewsOf($b), $order[$a]] <=> [$viewsOf($a), $order[$b]]);

        return $targets;
    }

    /**
     * @param list<string>        $titles
     * @param array<string, true> $seen
     *
     * @return list<string>
     */
    private static function terms(array $titles, int $max, array &$seen): array
    {
        $terms = [];
        foreach ($titles as $title) {
            $term = mb_strtolower(trim((string) preg_replace('/\s*\([^)]*\)$/', '', $title)));
            $key = SuggestionMerger::key($term);
            if ('' !== $key && !isset($seen[$key]) && \count($terms) < $max) {
                $seen[$key] = true;
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function resolved(array $data): array
    {
        $normalized = array_column($data['query']['normalized'] ?? [], 'to', 'from');
        $redirects = array_column($data['query']['redirects'] ?? [], 'to', 'from');

        $resolved = [];
        foreach ([...array_keys($normalized), ...array_keys($redirects)] as $from) {
            $title = $normalized[$from] ?? $from;
            $resolved[$from] = $redirects[$title] ?? $title;
        }

        return $resolved;
    }

    /** @param array<string, string|int> $query */
    private function request(string $lang, array $query): ResponseInterface
    {
        return $this->httpClient->request('GET', sprintf('https://%s.wikipedia.org/w/api.php', $lang), [
            'query' => $query + ['format' => 'json', 'formatversion' => 2],
        ] + $this->options());
    }

    /** @return array<string, mixed> */
    private function options(float $minimum = 0.3): array
    {
        $remaining = max($minimum, $this->deadline - microtime(true));

        return ['headers' => ['User-Agent' => self::USER_AGENT], 'timeout' => $remaining, 'max_duration' => $remaining];
    }
}
