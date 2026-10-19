<?php

namespace App\Tests\Search;

use App\Search\FieldSection;
use App\Suggest\Keyword;
use PHPUnit\Framework\TestCase;

final class FieldSectionTest extends TestCase
{
    public function testTheKeywordRepeatedByTheTitleIsNotListedAgain(): void
    {
        $title = new Keyword('taille haie électrique filaire', [], 21);
        $other = new Keyword('taille haie filaire', [], 12);
        $section = new FieldSection('Taille-haie électrique filaire', [$title, $other]);

        self::assertSame($title, $section->getTitleKeyword());
        self::assertSame([$other], $section->getOtherKeywords());
    }

    public function testATitleThatRepeatsNoKeywordKeepsThemAll(): void
    {
        $keywords = [new Keyword('porte sectionnelle', [], 40), new Keyword('porte basculante', [], 30)];
        $section = new FieldSection('Types de portes', $keywords);

        self::assertNull($section->getTitleKeyword());
        self::assertSame($keywords, $section->getOtherKeywords());
    }
}
