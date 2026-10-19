<?php

namespace App\Tests\Http;

use Symfony\Component\HttpClient\Response\MockResponse;

final class FakeHttpResponses
{
    public const CONCEPTS = ['b', 'c', 'p', 's'];

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->requestedUrls[] = $url;

        return match (true) {
            str_starts_with($url, 'https://api.cohere.com/') => $this->cohere($options),
            str_contains($url, 'wikipedia.org/'), str_contains($url, 'wikimedia.org/'), str_contains($url, 'wikidata.org/') => self::wikipedia($url),
            default => $this->suggest($url),
        };
    }

    public const VIEWS = ['Pain' => 9000, 'Farine' => 300, 'Eau potable' => 200, 'Levure' => 100, 'Baguette (pain)' => 500, 'Boulangerie' => 50];

    private static function wikipedia(string $url): MockResponse
    {
        if (preg_match('#/per-article/[^/]+/[^/]+/[^/]+/([^/]+)/daily/#', $url, $match)) {
            $views = self::VIEWS[str_replace('_', ' ', rawurldecode($match[1]))] ?? null;

            return null === $views
                ? new MockResponse('{"title":"Not found"}', ['http_code' => 404])
                : new MockResponse(json_encode(['items' => [['views' => $views - 1], ['views' => 1]]], \JSON_THROW_ON_ERROR));
        }

        if (str_starts_with($url, 'https://query.wikidata.org/')) {
            return new MockResponse(json_encode(['results' => ['bindings' => [
                ['label' => ['value' => 'pain de mie']],
                ['label' => ['value' => 'pain complet']],
            ]]], \JSON_THROW_ON_ERROR));
        }

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);

        if ('parse' === ($params['action'] ?? null)) {
            return new MockResponse(json_encode(['parse' => ['title' => 'Pain', 'wikitext' => <<<'WIKI'
                {{Infobox Aliment|[[Ignoré]]}}
                [[Fichier:Pain.jpg|vignette|Un [[ignoré]].]]
                Le '''pain''' est un aliment à base de [[farine]], d'[[Eau potable|eau]] et de [[Levure (biologie)|levure]].<ref>[[Référence]]</ref>
                == Histoire ==
                En [[Mésopotamie]].
                == Voir aussi ==
                === Articles connexes ===
                * [[Baguette (pain)|Baguette]]
                * [[Boulangerie]]
                === Liens externes ===
                * [[Ignoré]]
                [[Catégorie:Pain]]
                WIKI]], \JSON_THROW_ON_ERROR));
        }

        $titles = $params['titles'] ?? '';
        if (!\is_string($titles)) {
            throw new \LogicException('Paramètre titles inattendu.');
        }
        $titles = explode('|', $titles);
        $normalized = array_map(static fn (string $t): array => ['from' => $t, 'to' => ucfirst($t)], $titles);

        if ('search' === ($params['generator'] ?? null)) {
            $pages = 'pain' === ($params['gsrsearch'] ?? null)
                ? [['title' => 'Pain perdu', 'index' => 2], ['title' => 'Pain', 'index' => 1, 'pageprops' => ['wikibase_item' => 'Q7802']]]
                : [];

            return new MockResponse(json_encode(['query' => ['pages' => $pages]], \JSON_THROW_ON_ERROR));
        }

        if ('pageprops' === ($params['prop'] ?? null)) {
            $pages = array_map(static fn (string $t): array => 'pain' === $t
                ? ['title' => 'Pain', 'pageprops' => ['wikibase_item' => 'Q7802']]
                : ['title' => ucfirst($t), 'missing' => true], $titles);

            return new MockResponse(json_encode(['query' => ['normalized' => $normalized, 'pages' => $pages]], \JSON_THROW_ON_ERROR));
        }

        return new MockResponse(json_encode(['query' => [
            'normalized' => $normalized,
            'redirects' => [['from' => 'Levure (biologie)', 'to' => 'Levure']],
            'pages' => array_map(static fn (string $t): array => ['title' => ucfirst($t)], $titles),
        ]], \JSON_THROW_ON_ERROR));
    }

    private function suggest(string $url): MockResponse
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
        $query = $params['q'] ?? $params['query'] ?? $params['prefix'] ?? $params['kwd'] ?? $params['command'] ?? '';
        if (!\is_string($query)) {
            throw new \LogicException('Paramètre de requête inattendu.');
        }
        $youtube = 'yt' === ($params['ds'] ?? null);

        if ($youtube && str_contains($query, 'youtube-down')) {
            return new MockResponse('Service Unavailable', ['http_code' => 503]);
        }

        if (str_contains($url, 'ebaystatic.')) {
            return new MockResponse('/**/vjo.AutoFill._do('.json_encode(['res' => ['sug' => [$query.' commun']]], \JSON_THROW_ON_ERROR).')');
        }

        if (str_contains($url, 'indeed.')) {
            return new MockResponse('[]');
        }

        if (str_contains($url, 'yahoo.')) {
            return new MockResponse(json_encode(['q' => $query, 'r' => [['k' => $query.' commun']]], \JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'amazon.')) {
            return new MockResponse(json_encode(['suggestions' => [['type' => 'KEYWORD', 'value' => $query.' commun']]], \JSON_THROW_ON_ERROR));
        }

        $suggestions = $youtube
            ? [$query.' hors-sujet', $query.' commun']
            : [$query.' commun', $query.' prix'];

        return new MockResponse(json_encode([$query, $suggestions, [], new \stdClass()], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $options */
    private function cohere(array $options): MockResponse
    {
        $body = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);
        $prompt = (string) $body['messages'][1]['content'];

        if (str_starts_with($prompt, 'Seed: cohere-down')) {
            return new MockResponse('{"message":"overloaded"}', ['http_code' => 503]);
        }

        $schema = $body['response_format']['schema'];

        if (isset($schema['properties']['concepts'])) {
            $seed = substr(explode("\n", $prompt)[0], \strlen('Seed: '));

            preg_match_all('/^(\d+)\. (.+)$/m', explode('
Vocabulary:
', $prompt, 2)[1] ?? '', $vocabulary, \PREG_SET_ORDER);

            return self::cohereAnswer([
                'concepts' => array_map(static fn (string $c): string => $c.' '.$seed, self::CONCEPTS),
                'relevant' => array_map('intval', array_column(array_filter($vocabulary, static fn (array $v): bool => 'eau potable' !== $v[2]), 1)),
            ]);
        }

        if (isset($schema['properties']['groups'])) {
            return self::cohereAnswer(['groups' => [
                ['name' => 'Recettes', 'pages' => [['main_keyword' => 'Recettes maison', 'intent' => 'informational', 'sections' => ['Variantes']]]],
                ['name' => 'Achat', 'pages' => [['main_keyword' => 'Où acheter', 'intent' => 'transactional', 'sections' => []]]],
            ]]);
        }

        $results = [];
        $first = true;
        preg_match_all('/^(\d+)\. (.+)$/m', explode("\nKeywords:\n", $prompt, 2)[1] ?? '', $lines, \PREG_SET_ORDER);
        foreach ($lines as [, $id, $keyword]) {
            if (str_contains($keyword, 'hors-sujet')) {
                $results[] = ['id' => (int) $id, 'page' => 'none', 'section' => '', 'category' => 'doubtful'];
            } elseif (str_contains($keyword, 'prix')) {
                $results[] = ['id' => (int) $id, 'page' => 'P2', 'section' => '', 'category' => 'commercial'];
            } else {
                $results[] = ['id' => (int) $id, 'page' => 'P1', 'section' => $first ? '' : 'S1', 'category' => $first ? 'core' : 'complementary'];
                $first = false;
            }
        }

        return self::cohereAnswer(['results' => $results]);
    }

    /** @param array<string, mixed> $json */
    private static function cohereAnswer(array $json): MockResponse
    {
        return new MockResponse(json_encode([
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => json_encode($json, JSON_THROW_ON_ERROR)]]],
            'finish_reason' => 'COMPLETE',
        ], \JSON_THROW_ON_ERROR));
    }
}
