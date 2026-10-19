<?php

namespace App\Tests\Wikipedia;

use App\Search\Demand;
use App\Search\KeywordFilter;
use App\Tests\Http\FakeHttpResponses;
use App\Wikipedia\WikipediaExplorer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WikipediaExplorerTest extends TestCase
{
    public function testAsksForThePartsOfTheSeedAndSearchesIt(): void
    {
        $queries = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$queries): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
            $queries[] = $params['titles'] ?? 'recherche : '.(\is_string($params['gsrsearch'] ?? null) ? $params['gsrsearch'] : '');

            return self::json(['query' => ['pages' => []]]);
        });

        self::assertNull(self::explorer($client)->explore('devis toiture lyon', 'fr'));

        self::assertSame(['devis toiture|toiture', 'recherche : devis toiture lyon'], $queries);
    }

    public function testRanksTheLinksByViewsAndAddsTheWikidataTypes(): void
    {
        $fake = new FakeHttpResponses();
        $exploration = self::explorer(new MockHttpClient($fake))->explore('pain', 'fr');

        self::assertNotNull($exploration);
        self::assertCount(1, $exploration->articles, '« Pain perdu », trouvé par la recherche, ne couvre aucun mot de plus.');
        $article = $exploration->articles[0];
        self::assertSame('Pain', $article->title);
        self::assertSame(9000, $article->views);
        self::assertSame([8999, 1], $article->dailyViews);
        self::assertSame('0.0,0.0 64.0,16.0', $article->getSparkline(), 'Le jour le plus consulté en haut.');
        self::assertSame('https://fr.wikipedia.org/wiki/Pain', $article->getUrl());
        self::assertSame(Demand::High, $article->getVolume(), '9 000 vues : palier fort, de 6 000 à 25 000.');

        self::assertSame(['farine', 'eau potable', 'levure'], $exploration->introTerms);
        self::assertSame(['baguette', 'boulangerie'], $exploration->relatedTerms);
        self::assertSame(['pain de mie', 'pain complet'], $exploration->types);

        $wikidata = array_values(array_filter($fake->requestedUrls, static fn (string $u): bool => str_contains($u, 'wikidata')));
        self::assertStringContainsString('wd:Q7802', $wikidata[0], 'Sous-classes de l\'élément lié à l\'article.');
    }

    public function testShowsArticlesThatCoverMoreWordsButTakesTheVocabularyOfTheMainOne(): void
    {
        $parsed = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$parsed): MockResponse {
            if (!str_contains($url, 'wikipedia.org/w/api.php')) {
                return new MockResponse('', ['http_code' => 404]);
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);

            if ('parse' === ($params['action'] ?? null)) {
                $parsed[] = $params['page'];

                return self::json(['parse' => ['wikitext' => 'Pantalon' === $params['page'] ? 'Un [[vêtement]].' : 'Un [[pantalon]] en [[sergé]].']]);
            }

            return match (true) {
                isset($params['titles']) && 'pageprops' === ($params['prop'] ?? null) => self::json(['query' => [
                    'normalized' => [['from' => 'pantalon chino', 'to' => 'Pantalon chino'], ['from' => 'pantalon', 'to' => 'Pantalon'], ['from' => 'chino', 'to' => 'Chino']],
                    'pages' => [['title' => 'Pantalon chino', 'missing' => true], ['title' => 'Pantalon'], ['title' => 'Chino', 'pageprops' => ['disambiguation' => '']]],
                ]]),

                isset($params['gsrsearch']) => self::json(['query' => ['pages' => [
                    ['title' => 'Preppy', 'index' => 2],
                    ['title' => 'Chino (vêtement)', 'index' => 1],
                    ['title' => 'Pantalon en jeans', 'index' => 3],
                ]]]),
                default => self::json(['query' => ['pages' => []]]),
            };
        });

        $exploration = self::explorer($client)->explore('pantalon chino', 'fr');

        self::assertNotNull($exploration);
        self::assertSame(['Chino (vêtement)', 'Pantalon'], array_map(static fn ($a) => $a->title, $exploration->articles), 'À couverture égale, le mot le plus spécifique (le complément) l\'emporte.');
        self::assertSame(['Pantalon', 'Chino (vêtement)'], $parsed, 'Les textes partent ensemble ; le vocabulaire ne vient que de l\'article principal.');
        self::assertSame([true, false], array_map(static fn ($a) => $a->counted, $exploration->articles), '« Pantalon » ne couvre que le mot générique : il ne compte pas dans la notoriété.');
        self::assertSame(0, $exploration->articles[0]->views, 'Sans mesure de fréquentation, zéro.');
        self::assertSame(['sergé'], $exploration->introTerms, 'Vocabulaire du seul article principal, sans le titre d\'un article retenu.');
        self::assertSame([], $exploration->types, 'Sans élément Wikidata, pas de types.');
    }

    public function testReturnsNothingWhenWikipediaFails(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        self::assertNull(self::explorer($client)->explore('pain', 'fr'));
    }

    private static function explorer(MockHttpClient $client): WikipediaExplorer
    {
        return new WikipediaExplorer($client, new KeywordFilter(), new NullLogger());
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
