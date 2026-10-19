<?php

namespace App\Tests\Suggest;

use App\Suggest\Keyword;
use App\Suggest\RawSuggestion;
use App\Suggest\SuggestionMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SuggestionMergerTest extends TestCase
{
    public function testMergesCaseAccentAndSpacingVariants(): void
    {
        $keywords = (new SuggestionMerger())->merge([
            new RawSuggestion('gâteau à l\'orange', 'google', 3, false),
            new RawSuggestion('Gateau  à l\'orange', 'youtube', 1, false),
        ], 'gâteau', 5);

        self::assertCount(1, $keywords);
        self::assertSame('Gateau à l\'orange', $keywords[0]->text, 'La forme la plus populaire est conservée.');
        self::assertSame(['google' => 4, 'youtube' => 2], $keywords[0]->positions);
    }

    public function testKeepsTheBestOccurrenceOfEachSource(): void
    {
        $keywords = (new SuggestionMerger())->merge([
            new RawSuggestion('pain au lait', 'google', 4, false),
            new RawSuggestion('pain au lait', 'google', 0, true),
            new RawSuggestion('pain au lait', 'google', 2, false),
        ], 'pain', 1);

        self::assertSame(['google' => 1], $keywords[0]->positions);
        self::assertSame(100, $keywords[0]->score);
    }

    public function testDropsTheSeedItselfAndEmptyStrings(): void
    {
        $keywords = (new SuggestionMerger())->merge([
            new RawSuggestion('Recette Pain', 'google', 0, true),
            new RawSuggestion('   ', 'google', 1, true),
            new RawSuggestion('recette pain maison', 'google', 2, true),
        ], 'recette pain', 1);

        self::assertSame(['recette pain maison'], array_map(static fn (Keyword $k) => $k->text, $keywords));
    }

    #[DataProvider('points')]
    public function testPointsDependOnPositionAndQueryType(int $rank, bool $fromSeed, float $expected): void
    {
        self::assertEqualsWithDelta($expected, SuggestionMerger::points(new RawSuggestion('x', 'google', $rank, $fromSeed)), 0.0001);
    }

    /** @return iterable<string, array{int, bool, float}> */
    public static function points(): iterable
    {
        yield '1re, expression seule' => [0, true, 1.0];
        yield '2e, expression seule' => [1, true, 0.9];
        yield '8e, expression seule' => [7, true, 0.3];
        yield '10e et au-delà' => [14, true, 0.1];
        yield '1re, avec une lettre' => [0, false, 0.6];
    }

    public function testEveryPositionCountsInTheScore(): void
    {
        $keywords = (new SuggestionMerger())->merge([
            new RawSuggestion('pizza lyon', 'google', 0, true),
            new RawSuggestion('pizza lyon', 'amazon', 1, true),
            new RawSuggestion('pizza lyon', 'bing', 7, true),
        ], 'pizza', 5);

        self::assertSame(44, $keywords[0]->score);
    }

    public function testSortsByScoreThenSourceCount(): void
    {
        $keywords = (new SuggestionMerger())->merge([
            new RawSuggestion('first on two engines', 'google', 0, true),
            new RawSuggestion('first on two engines', 'bing', 0, true),
            new RawSuggestion('ninth on three engines', 'google', 8, true),
            new RawSuggestion('ninth on three engines', 'bing', 8, true),
            new RawSuggestion('ninth on three engines', 'amazon', 8, true),
            new RawSuggestion('first on one engine', 'google', 0, true),
            new RawSuggestion('same score, two engines', 'google', 5, true),
            new RawSuggestion('same score, two engines', 'bing', 5, true),
            new RawSuggestion('same score, one engine', 'youtube', 1, false),
            new RawSuggestion('same score, one engine', 'youtube', 2, true),
        ], 'seed', 5);

        self::assertSame([
            'first on two engines',
            'same score, two engines',
            'first on one engine',
            'same score, one engine',
            'ninth on three engines',
        ], array_map(static fn (Keyword $k) => $k->text, $keywords));
    }

    public function testMergesWordOrderPluralAndHyphenVariants(): void
    {
        $keywords = (new SuggestionMerger())->merge([
            new RawSuggestion('taille haie thermique sur perche', 'google', 0, false),
            new RawSuggestion('taille-haies sur perche thermique', 'bing', 2, false),
            new RawSuggestion('installation chauffe eau', 'google', 1, false),
            new RawSuggestion('installation du chauffe-eau', 'bing', 0, false),
        ], 'taille haie', 2);

        self::assertSame(['installation du chauffe-eau', 'taille haie thermique sur perche'], array_map(static fn ($k) => $k->text, $keywords));
        self::assertSame(['google' => 1, 'bing' => 3], $keywords[1]->positions);
    }
}
