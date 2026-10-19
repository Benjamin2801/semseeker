<?php

namespace App\Tests\Suggest;

use App\Suggest\Keyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeywordTest extends TestCase
{
    #[DataProvider('tiers')]
    public function testScoreTier(int $score, int $expected): void
    {
        $keyword = new Keyword('x', ['google' => 1], $score);

        self::assertSame($expected, $keyword->getScoreTier());
        self::assertSame($expected <= 2, $keyword->isLow(), 'Faible : les deux premiers paliers.');
    }

    /** @return iterable<array{int, int}> */
    public static function tiers(): iterable
    {
        yield [0, 1];
        yield [9, 1];
        yield [10, 2];
        yield [20, 3];
        yield [34, 3];
        yield [35, 4];
        yield [50, 5];
        yield [100, 5];
    }
}
