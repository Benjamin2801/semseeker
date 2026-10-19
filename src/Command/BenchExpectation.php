<?php

namespace App\Command;

use App\Search\PageType;
use App\Search\SearchResult;
use App\Suggest\Keyword;
use App\Suggest\SuggestionMerger;

/**
 * Ce qu'un référenceur attend pour une expression, jugé comme on juge un système qui varie d'un passage à l'autre :
 * chaque contrôle rend « ok », « fragile » (dans la marge d'un seuil, ou l'attendu n'est que la seconde option
 * proposée) ou « wrong » (clairement faux). Seul le clairement faux échoue ; le fragile se signale sans casser.
 *
 * Conseil : juste (`verdicts`), acceptable (`acceptable`, un choix que d'autres référenceurs défendraient), en option
 * (proposé en second, l'utilisateur le voit) ou faux. Intention : tendance du plan, pas un vote. Plan : bruit
 * (`noise`), sous-sujets couverts (`topics`, écrits d'après le domaine et non d'après un plan), structure.
 * Un écart connu (« gap », « fieldGap ») est documenté plutôt que masqué.
 *
 * @phpstan-type Status 'ok'|'fragile'|'wrong'|'skip'
 * @phpstan-type AdviceCheck array{verdict: 'right'|'acceptable'|'option'|'wrong', page: Status, intent: Status}
 * @phpstan-type FieldCheck array{noise: list<string>, noiseShare: float, missing: list<string>, coverage: float,
 *     duplicates: list<string>, singleShare: float, status: array{noise: Status, coverage: Status, duplicates: Status, singles: Status}}
 */
final class BenchExpectation
{
    // Bandes [fragile, ok] : entre les deux, le résultat tient à une page près ou à un jour de suggestions près.
    // Une intention attendue doit porter 30 % des pages ; à 15-30 %, elle est présente mais minoritaire.
    public const INTENT_SHARE = [0.15, 0.3];
    public const NOISE = [0.10, 0.05];
    public const COVERAGE = [0.5, 0.75];
    public const MAX_SINGLE_PAGES = 0.5;

    private const BUSINESS = ['commercial', 'transactional'];

    public function __construct(
        public readonly string $expression,
        public readonly string $lang,
        /** @var list<PageType> */
        public readonly array $verdicts,
        public readonly ?bool $page,
        /** @var list<string> */
        public readonly array $intents,
        public readonly string $why,
        public readonly ?string $gap = null,
        /** @var list<PageType> */
        public readonly array $acceptable = [],
        /** @var list<string> */
        public readonly array $topics = [],
        /** @var list<string> */
        public readonly array $noise = [],
        public readonly ?string $fieldGap = null,
    ) {
    }

    /** @return list<self> */
    public static function load(string $path): array
    {
        /** @var list<array{expression: string, lang?: string, verdicts: list<string>, acceptable?: list<string>, page: ?bool, intent?: string|list<string>, why: string, gap?: string, topics?: list<string>, noise?: list<string>, fieldGap?: string}> $cases */
        $cases = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $c): self => new self(
            $c['expression'],
            $c['lang'] ?? 'fr',
            array_map(PageType::from(...), $c['verdicts']),
            $c['page'],
            (array) ($c['intent'] ?? []),
            $c['why'],
            $c['gap'] ?? null,
            array_map(PageType::from(...), $c['acceptable'] ?? []),
            $c['topics'] ?? [],
            $c['noise'] ?? [],
            $c['fieldGap'] ?? null,
        ), $cases);
    }

    public function slug(): string
    {
        return $this->lang.'-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', SuggestionMerger::normalize($this->expression)), '-');
    }

    /** @return AdviceCheck */
    public function check(SearchResult $result): array
    {
        $advice = $result->getAdvice();
        $verdict = $advice->verdict;
        $expected = [...$this->verdicts, ...$this->acceptable];
        // L'interface propose deux options (« guide complet ou section importante ») : l'attendu en second est vu.
        $level = match (true) {
            \in_array($verdict, $this->verdicts, true) => 'right',
            \in_array($verdict, $this->acceptable, true) => 'acceptable',
            [] !== array_intersect(array_map(static fn (PageType $t): string => $t->value, $advice->getOptions()), array_map(static fn (PageType $t): string => $t->value, $expected)) => 'option',
            default => 'wrong',
        };
        $page = match (true) {
            null === $this->page, 'acceptable' === $level, $this->page === !$verdict->isSection() => 'ok',
            'option' === $level => 'fragile',
            default => 'wrong',
        };

        return ['verdict' => $level, 'page' => $page, 'intent' => $this->checkIntent($result)];
    }

    /** @param AdviceCheck $check */
    public static function passes(array $check): bool
    {
        return 'wrong' !== $check['verdict'] && 'wrong' !== $check['page'] && 'wrong' !== $check['intent'];
    }

    /** @param AdviceCheck $check */
    public static function isFragile(array $check): bool
    {
        return 'option' === $check['verdict'] || \in_array('fragile', [$check['page'], $check['intent']], true);
    }

    // Les intentions attendues forment deux familles : s'informer, ou acheter (commercial et transactionnel).
    /** @return 'ok'|'fragile'|'wrong'|'skip' */
    private function checkIntent(SearchResult $result): string
    {
        $shares = self::intentShares($result);
        $families = array_unique(array_map(static fn (string $i): bool => \in_array($i, self::BUSINESS, true), $this->intents));
        if ([] === $shares || 1 !== \count($families)) {
            return 'skip';
        }
        $share = reset($families) ? $shares['commercial'] + $shares['transactional'] : $shares['informational'];

        return self::band($share, self::INTENT_SHARE);
    }

    /** @return array{informational: float, commercial: float, transactional: float}|array{} */
    public static function intentShares(SearchResult $result): array
    {
        $counts = ['informational' => 0, 'commercial' => 0, 'transactional' => 0];
        foreach ($result->field->groups ?? [] as $group) {
            foreach ($group->pages as $page) {
                if (null !== $page->intent) {
                    ++$counts[$page->intent->value];
                }
            }
        }
        $total = array_sum($counts);

        return $total > 0 ? array_map(static fn (int $n): float => $n / $total, $counts) : [];
    }

    // Résumé lisible du mélange d'intentions : « info 50 % · comm 33 % · tran 17 % ».
    public static function intentSummary(SearchResult $result): string
    {
        $shares = array_filter(self::intentShares($result));

        return [] !== $shares ? implode(' · ', array_map(static fn (string $i, float $s): string => substr($i, 0, 4).' '.round(100 * $s).' %', array_keys($shares), $shares)) : '—';
    }

    /**
     * Pertinence du plan : part de bruit, sous-sujets couverts, mots-clés rangés deux fois, pages d'un seul mot-clé.
     *
     * @return ?FieldCheck
     */
    public function checkField(SearchResult $result): ?array
    {
        $pages = [];
        foreach ($result->field->groups ?? [] as $group) {
            foreach ($group->pages as $page) {
                // Le titre est le mot-clé que la page porte : il compte comme les autres.
                $pages[] = array_values(array_unique([SuggestionMerger::normalize($page->title),
                    ...array_map(static fn (Keyword $k): string => SuggestionMerger::normalize($k->text), $page->getAllKeywords())]));
            }
        }
        if ([] === $pages) {
            return null;
        }
        $keywords = array_merge(...$pages);

        $noise = array_values(array_filter($keywords, fn (string $k): bool => [] !== array_filter($this->noise, static fn (string $t): bool => self::mentions($k, $t))));
        $missing = array_values(array_filter($this->topics, static fn (string $t): bool => [] === array_filter($keywords, static fn (string $k): bool => self::mentions($k, $t))));
        $duplicates = array_map('strval', array_keys(array_filter(array_count_values($keywords), static fn (int $n): bool => $n > 1)));
        $noiseShare = \count($noise) / \count($keywords);
        $coverage = [] !== $this->topics ? 1 - \count($missing) / \count($this->topics) : 1.0;
        $singleShare = \count(array_filter($pages, static fn (array $p): bool => \count($p) < 2)) / \count($pages);

        return [
            'noise' => $noise,
            'noiseShare' => $noiseShare,
            'missing' => $missing,
            'coverage' => $coverage,
            'duplicates' => $duplicates,
            'singleShare' => $singleShare,
            'status' => [
                'noise' => self::band(-$noiseShare, [-self::NOISE[0], -self::NOISE[1]]),
                'coverage' => self::band($coverage, self::COVERAGE),
                // Deux pages pour une même requête se concurrencent : jamais tolérable.
                'duplicates' => [] === $duplicates ? 'ok' : 'wrong',
                'singles' => $singleShare <= self::MAX_SINGLE_PAGES ? 'ok' : 'fragile',
            ],
        ];
    }

    /** @param FieldCheck $field */
    public static function fieldPasses(array $field): bool
    {
        return !\in_array('wrong', $field['status'], true);
    }

    /** @param FieldCheck $field */
    public static function fieldIsFragile(array $field): bool
    {
        return \in_array('fragile', $field['status'], true);
    }

    /**
     * Tableau de bord : la qualité d'ensemble du jeu, qu'un cas qui bascule dans sa marge ne fait pas bouger.
     *
     * @param list<array{self, SearchResult}> $runs
     *
     * @return array{cases: int, advice: float, fragile: int, noise: float, coverage: float, duplicated: list<string>}
     */
    public static function scorecard(array $runs): array
    {
        $advice = [];
        $fragile = 0;
        $noise = [];
        $coverage = [];
        $duplicated = [];
        foreach ($runs as [$expectation, $result]) {
            $check = $expectation->check($result);
            $advice[] = self::passes($check) ? 1 : 0;
            $field = $expectation->checkField($result);
            $fragile += self::isFragile($check) || (null !== $field && self::fieldIsFragile($field)) ? 1 : 0;
            if (null !== $field) {
                $noise[] = $field['noiseShare'];
                $coverage[] = $field['coverage'];
                if ([] !== $field['duplicates']) {
                    $duplicated[] = $expectation->expression;
                }
            }
        }
        $mean = static fn (array $values): float => [] !== $values ? array_sum($values) / \count($values) : 0.0;

        return ['cases' => \count($runs), 'advice' => $mean($advice), 'fragile' => $fragile, 'noise' => $mean($noise),
            'coverage' => $mean($coverage), 'duplicated' => $duplicated];
    }

    /** @param array{cases: int, advice: float, fragile: int, noise: float, coverage: float, duplicated: list<string>} $card */
    public static function formatScorecard(array $card): string
    {
        return sprintf('%d cas · conseils justes ou défendables %d %% · cas fragiles %d · bruit moyen %.1f %% · couverture moyenne %d %% · plans avec doublons %d',
            $card['cases'], round(100 * $card['advice']), $card['fragile'], 100 * $card['noise'], round(100 * $card['coverage']), \count($card['duplicated']));
    }

    /**
     * @param array{float, float} $band seuils [fragile, ok], la valeur croissant vers le mieux
     *
     * @return 'ok'|'fragile'|'wrong'
     */
    private static function band(float $value, array $band): string
    {
        // Arrondi au point : 29,99 % ne doit pas valoir moins que 30 %.
        $value = round($value, 2);

        return match (true) {
            $value >= $band[1] => 'ok',
            $value >= $band[0] => 'fragile',
            default => 'wrong',
        };
    }

    // Un sous-sujet est couvert si un mot-clé le contient en mots entiers, au pluriel près ; « subvention|aide|prime »
    // accepte l'un de ses synonymes.
    private static function mentions(string $keyword, string $term): bool
    {
        foreach (explode('|', $term) as $synonym) {
            $words = array_map(static fn (string $w): string => preg_quote($w, '/').'[sx]?', explode(' ', SuggestionMerger::normalize($synonym)));
            if (1 === preg_match('/\b'.implode('[\s\'-]+', $words).'\b/u', $keyword)) {
                return true;
            }
        }

        return false;
    }
}
