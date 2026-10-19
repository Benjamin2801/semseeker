<?php

namespace App\Search;

use App\Ai\Intent;
use App\Suggest\SuggestionMerger;

final class MotherPage
{
    private const WEIGHTS = ['volume' => 0.35, 'depth' => 0.3, 'commercial' => 0.2, 'rank' => 0.15];

    private const FULL_PAGE = 25;

    private const UNKNOWN = 50;

    public function __construct(
        public readonly string $name,

        public readonly ?string $alternative,

        public readonly ?int $demand,

        public readonly ?int $keywords,
    ) {
    }

    /**
     * @param list<string>         $candidates
     * @param array<string, float> $fills
     */
    public static function choose(array $candidates, string $seed, ?SemanticField $field, array $fills): ?self
    {
        $seedKey = SuggestionMerger::key($seed);
        $seedWords = array_filter(explode(' ', $seedKey));
        $pages = [];
        foreach ($field->groups ?? [] as $group) {
            foreach ($group->pages as $page) {
                $pages[SuggestionMerger::key($page->main->text)] = $page;
            }
        }

        $scored = [];
        foreach ($candidates as $rank => $candidate) {
            $key = SuggestionMerger::key($candidate);
            $words = array_filter(explode(' ', $key));
            $page = $pages[$key] ?? null;

            if ('' === $key || $key === $seedKey || Lexicon::namesNoSubject($candidate) || (null === $page && [] !== array_diff($words, $seedWords))) {
                continue;
            }
            $keywords = null !== $page ? \count($page->getAllKeywords()) + \count($page->sections) : null;
            $demand = isset($fills[$candidate]) ? (int) round(100 * $fills[$candidate]) : null;
            $criteria = [
                'volume' => $demand ?? $page?->main->score ?? self::UNKNOWN,
                'depth' => null !== $keywords ? min(100, 100 * $keywords / self::FULL_PAGE) : self::UNKNOWN,
                'commercial' => null !== $page ? (\in_array($page->intent, [Intent::Commercial, Intent::Transactional], true) ? 100 : 0) : self::UNKNOWN,
                'rank' => 0 === $rank ? 100 : 50,
            ];
            $score = array_sum(array_map(static fn (string $name): float => self::WEIGHTS[$name] * $criteria[$name], array_keys($criteria)));
            $scored[] = [$score, $candidate, $demand, $keywords];
        }
        if ([] === $scored) {
            return null;
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return new self($scored[0][1], $scored[1][1] ?? null, $scored[0][2], $scored[0][3]);
    }
}
