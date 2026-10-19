<?php

namespace App\Search;

use App\Suggest\RawSuggestion;

enum Demand: string
{
    case VeryLow = 'very_low';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case VeryHigh = 'very_high';

    private const FULL_LIST = 10;

    public const MEASURING_SOURCES = ['google', 'yahoo', 'youtube'];

    /**
     * @param list<RawSuggestion> $suggestions
     * @param list<string>        $sources
     */
    public static function estimate(array $suggestions, array $sources): ?DemandEstimate
    {
        $measuring = array_values(array_intersect($sources, self::MEASURING_SOURCES));
        $fills = self::fills($suggestions, [] !== $measuring ? $measuring : $sources);
        if ([] === $fills) {
            return null;
        }

        $fill = array_sum($fills) / \count($fills);

        return new DemandEstimate(self::fromFill($fill), $fill, \count($fills));
    }

    /**
     * @param list<RawSuggestion> $suggestions
     * @param list<string>        $sources
     *
     * @return array<string, float>
     */
    public static function fills(array $suggestions, array $sources): array
    {
        $lengths = array_fill_keys($sources, 0);
        foreach ($suggestions as $suggestion) {
            if ($suggestion->fromSeed && isset($lengths[$suggestion->source])) {
                $lengths[$suggestion->source] = max($lengths[$suggestion->source], $suggestion->rank + 1);
            }
        }

        return array_map(static fn (int $n): float => min($n, self::FULL_LIST) / self::FULL_LIST, $lengths);
    }

    public static function fromFill(float $fill): self
    {
        return match (true) {
            $fill >= 0.97 => self::VeryHigh,
            $fill >= 0.75 => self::High,
            $fill >= 0.45 => self::Medium,
            $fill >= 0.1 => self::Low,
            default => self::VeryLow,
        };
    }
}
