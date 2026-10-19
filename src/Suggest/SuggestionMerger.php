<?php

namespace App\Suggest;

use function Symfony\Component\String\u;

final class SuggestionMerger
{
    private const POSITIONS = 10;

    // Une suggestion trouvée par un concept compte moins qu'une suggestion de l'expression elle-même.
    private const RELATED_QUERY_WEIGHT = 0.6;

    private const STOP_WORDS = ['a', 'au', 'aux', 'd', 'de', 'des', 'du', 'en', 'et', 'l', 'la', 'le', 'les', 'pour', 'un', 'une', 'an', 'for', 'of', 'the', 'to'];

    /**
     * @param list<RawSuggestion> $suggestions
     *
     * @return list<Keyword>
     */
    public function merge(array $suggestions, string $seed, int $sourceCount): array
    {
        $seedKey = self::key($seed);
        $groups = [];

        foreach ($suggestions as $suggestion) {
            $key = self::key($suggestion->text);
            if ('' === $key || $key === $seedKey) {
                continue;
            }

            $group = $groups[$key] ?? ['text' => '', 'best' => -1, 'points' => [], 'positions' => []];
            $points = self::points($suggestion);

            if ($points > $group['best']) {
                $group['text'] = self::clean($suggestion->text);
                $group['best'] = $points;
            }

            if ($points > ($group['points'][$suggestion->source] ?? -1)) {
                $group['points'][$suggestion->source] = $points;
                $group['positions'][$suggestion->source] = $suggestion->rank + 1;
            }
            $groups[$key] = $group;
        }

        $keywords = [];
        foreach ($groups as $group) {
            $keywords[] = new Keyword($group['text'], $group['positions'], self::score($group['points'], $sourceCount));
        }

        usort($keywords, static fn (Keyword $a, Keyword $b): int => [$b->score, $b->getSourceCount(), $a->text]
            <=> [$a->score, $a->getSourceCount(), $b->text]);

        return $keywords;
    }

    public static function points(RawSuggestion $suggestion): float
    {
        $position = (self::POSITIONS - min($suggestion->rank, self::POSITIONS - 1)) / self::POSITIONS;

        return $position * ($suggestion->fromSeed ? 1.0 : self::RELATED_QUERY_WEIGHT);
    }

    /** @param array<string, float> $points */
    public static function score(array $points, int $sourceCount): int
    {
        return $sourceCount > 0 ? (int) round(100 * array_sum($points) / $sourceCount) : 0;
    }

    public static function normalize(string $text): string
    {
        return u(self::clean($text))->ascii()->lower()->toString();
    }

    public static function key(string $text): string
    {
        $words = preg_split('/[\s\'’-]+/', self::normalize($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_diff($words, self::STOP_WORDS);
        $words = array_map(static fn (string $w): string => \strlen($w) > 3 && preg_match('/[^s][sx]$/', $w) ? substr($w, 0, -1) : $w, $words);
        sort($words);

        return implode(' ', $words);
    }

    private static function clean(string $text): string
    {
        return u($text)->collapseWhitespace()->toString();
    }
}
