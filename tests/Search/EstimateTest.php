<?php

namespace App\Tests\Search;

use App\Search\Estimate;
use App\Suggest\Keyword;
use PHPUnit\Framework\TestCase;

final class EstimateTest extends TestCase
{
    public function testWeighsTheAiAndThePresenceOnWikipediaAndWikidata(): void
    {
        self::assertSame(10, Estimate::of(new Keyword('merguez', [], 0, Keyword::ORIGIN_AI), 0), 'Proposé par l\'IA, sans article.');
        self::assertSame(6, Estimate::of(new Keyword('volaille', [], 0, Keyword::ORIGIN_WIKIPEDIA), 0));
        self::assertSame(25, Estimate::of(new Keyword('saucisse', [], 0, Keyword::ORIGIN_AI), 10000), 'Avec son article, consulté : 10 + 8 + 7.');
        self::assertSame(28, Estimate::of(new Keyword('viande', [], 0, Keyword::ORIGIN_AI), 5000000), 'Au plus 28 : jamais au palier des termes recherchés.');
    }

    public function testColoursAnEstimatedTermByItsEstimate(): void
    {
        $keyword = (new Keyword('saucisse', [], 0, Keyword::ORIGIN_AI))->withEstimate(23);

        self::assertFalse($keyword->isMeasured());
        self::assertSame(23, $keyword->getDisplayScore());
        self::assertSame(3, $keyword->getScoreTier());
        self::assertSame(0, $keyword->score, 'Les exports gardent l\'absence de mesure.');
    }
}
