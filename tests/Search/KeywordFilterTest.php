<?php

namespace App\Tests\Search;

use App\Search\KeywordFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeywordFilterTest extends TestCase
{
    #[DataProvider('keywords')]
    public function testSetsAsideLocalAndNavigationalQueries(string $keyword, string $seed, string $lang, bool $excluded): void
    {
        self::assertSame($excluded, (new KeywordFilter())->excludes($keyword, $seed, $lang));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function keywords(): iterable
    {
        yield 'ville' => ['plombier lyon', 'plombier', 'fr', true];
        yield 'ville composée, sans accent ni trait d\'union' => ['plombier Saint-Étienne', 'plombier', 'fr', true];
        yield 'code postal' => ['plombier 75015', 'plombier', 'fr', true];
        yield 'proximité' => ['plombier près de chez moi', 'plombier', 'fr', true];
        yield 'arrondissement' => ['plombier 15e arrondissement', 'plombier', 'fr', true];
        yield 'horaires d\'une enseigne' => ['celio horaires', 'pantalon chino', 'fr', true];
        yield 'service client' => ['assurance auto service client', 'assurance auto', 'fr', true];
        yield 'near me' => ['plumber near me', 'plumber', 'en', true];
        yield 'ville anglaise' => ['plumber new york', 'plumber', 'en', true];

        yield 'mot ordinaire' => ['plombier tarif', 'plombier', 'fr', false];
        yield 'mot qui a aussi un sens de produit' => ['lentilles de contact', 'lentille', 'fr', false];
        yield 'ville prise dans un mot' => ['pantalon parisien', 'pantalon', 'fr', false];
        yield 'villes d\'une autre langue' => ['pizza paris', 'pizza', 'en', false];
        yield 'règle que l\'expression vérifie déjà' => ['plombier paris pas cher', 'plombier paris', 'fr', false];
    }
}
