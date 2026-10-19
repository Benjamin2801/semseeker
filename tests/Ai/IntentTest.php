<?php

namespace App\Tests\Ai;

use App\Ai\Intent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntentTest extends TestCase
{
    /** @param list<string> $keywords */
    #[DataProvider('pages')]
    public function testReadsTheIntentFromTheKeywordsOfAPage(array $keywords, ?Intent $expected): void
    {
        self::assertSame($expected, Intent::fromKeywords($keywords));
    }

    /** @return iterable<string, array{list<string>, ?Intent}> */
    public static function pages(): iterable
    {
        yield 'prix et tarif' => [['porte de garage automatique', 'porte de garage automatique prix', 'porte de garage automatique tarif'], Intent::Commercial];
        yield 'un tiers suffit' => [['four a pizza', 'four à pizza électrique', 'meilleur four à pizza'], Intent::Commercial];
        yield 'achat avant prix' => [['porte de garage devis', 'porte de garage prix'], Intent::Transactional];
        yield 'en anglais' => [['garage door', 'garage door cost', 'best garage door'], Intent::Commercial];
        yield 'un mot isolé ne décide pas' => [['pâte à pizza', 'pâte à pizza rapide', 'pâte à pizza thermomix', 'pâte à pizza prix'], null];
        yield '« installer » est une question' => [['comment installer une porte de garage', 'installer porte de garage'], null];
        yield 'mot pris dans un autre' => [['caprice des dieux'], null];
        yield 'aucun mot-clé' => [[], null];
    }
}
