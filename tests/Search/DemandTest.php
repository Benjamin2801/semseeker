<?php

namespace App\Tests\Search;

use App\Search\Demand;
use App\Suggest\RawSuggestion;
use PHPUnit\Framework\TestCase;

final class DemandTest extends TestCase
{
    public function testFullListsEverywhereMeanVeryHighDemand(): void
    {
        $suggestions = [...self::seedList('google', 10), ...self::seedList('yahoo', 10), ...self::seedList('youtube', 12)];

        self::assertSame(Demand::VeryHigh, Demand::estimate($suggestions, ['google', 'yahoo', 'youtube'])?->level);
    }

    public function testSilentSourcesCountAsEmptyLists(): void
    {
        $estimate = Demand::estimate(self::seedList('google', 10), ['google', 'yahoo']);
        self::assertSame(Demand::Medium, $estimate?->level);
        self::assertSame(0.5, $estimate->fill, 'Une liste pleine, une vide.');
        self::assertSame(2, $estimate->sourceCount);
    }

    public function testListsThatAreAlwaysFullDoNotMeasureTheDemand(): void
    {
        $suggestions = [...self::seedList('google', 1), ...self::seedList('bing', 12)];

        self::assertSame(Demand::Low, Demand::estimate($suggestions, ['google', 'bing'])?->level);
        self::assertSame(Demand::High, Demand::estimate(self::seedList('amazon', 8), ['amazon'])?->level, 'Sans source de mesure, les autres servent de repli.');
    }

    public function testOnlyTheSeedQueryCounts(): void
    {
        $concepts = array_map(static fn (int $r) => new RawSuggestion('x'.$r, 'google', $r, false), range(0, 9));

        self::assertSame(Demand::VeryLow, Demand::estimate($concepts, ['google'])?->level, 'Les suggestions des concepts ne mesurent pas la demande.');
    }

    public function testNoSourceMeansNoEstimate(): void
    {
        self::assertNull(Demand::estimate([], []));
    }

    /** @return list<RawSuggestion> */
    private static function seedList(string $source, int $length): array
    {
        return array_map(static fn (int $r) => new RawSuggestion($source.' '.$r, $source, $r, true), range(0, $length - 1));
    }
}
