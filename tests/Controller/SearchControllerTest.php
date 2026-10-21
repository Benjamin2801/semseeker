<?php

namespace App\Tests\Controller;

use App\Tests\Http\FakeHttpResponses;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SearchControllerTest extends WebTestCase
{
    public function testShowsTheSemanticField(): void
    {
        $client = static::createClient();
        $crawler = self::analyze($client, 'fr', 'pain', 'fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'mots-clés trouvés pour « pain »');
        self::assertSelectorNotExists('.notice');

        self::assertSame('Demande 12', preg_replace('/\s+/u', ' ', trim($crawler->filter('.radar-legend li')->first()->text())));
        self::assertSelectorExists('.advice .advice-value');
        self::assertSelectorTextContains('.wiki-views', 'Pain 9 000');
        self::assertSelectorExists('.radar .radar-shape');
        self::assertSelectorExists('.wiki-views .sparkline.demand-high polyline');
        self::assertSelectorTextContains('.analysis-meta', '8/8 sources');
        self::assertSelectorTextContains('.analysis-meta', '2 thèmes 2 concepts FR');
        self::assertSame('https://fr.wikipedia.org/wiki/Pain', $crawler->filter('.wiki-views a')->attr('href'));
        self::assertSame('pain', $crawler->filter('#q')->attr('value'));

        self::assertSelectorTextContains('.structure h2', 'Champ sémantique');
        self::assertSelectorTextContains('.tree-root', 'pain');
        self::assertSame(['Recettes', 'Achat'], $crawler->filter('.tree-topic')->each(static fn ($t) => $t->text()));
        $page = $crawler->filter('.tree-page')->first();
        self::assertSame('Recettes maison', $page->filter('.page-title')->text(), 'La page porte le mot-clé choisi par le modèle.');
        self::assertStringStartsWith('pain commun', $page->filter('.page-sections li.is-main')->text(), 'Le mot-clé principal ouvre la liste.');
        self::assertStringEndsWith('Variantes', $page->filter('.page-sections li:not(.is-main) .section-title')->text());
        $plan = $crawler->filter('[data-plan-text]')->html();
        self::assertStringContainsString("[Recettes]\nRecettes maison [Informationnelle]\n  pain commun, ", $plan);
        self::assertStringNotContainsString('H2', $plan, 'Ni H1, ni H2, ni mot-clé principal : seulement les regroupements.');

        self::assertSame('Informationnelle', $page->filter('.page-intent')->text());
        self::assertSame('informational', $page->attr('data-intent'));
        self::assertSame('Transactionnelle 100 %', $crawler->filter('.tree-group')->eq(1)->filter('.intent-bar')->attr('title'));
        self::assertSame(['informational', 'transactional'], $crawler->filter('[data-intent-filter]')->each(static fn ($c) => $c->attr('data-intent-filter')));
        self::assertStringNotContainsString('hors-sujet', (string) $client->getResponse()->getContent());

        self::assertSame(['Logiques', 'Recherches', 'Opportunités', 'Fondamentaux', 'Sujets annexes'], $crawler->filter('.synthesis-name')->each(static fn ($n) => $n->text()), 'Concepts et opportunités en haut, puis fondamentaux et sujets annexes.');
        self::assertCount(5, $crawler->filter('.synthesis-icon'));
        self::assertStringContainsString('pain prix', $crawler->filter('.synthesis-card.category-commercial')->text());

        $origins = array_values(array_unique($crawler->filter('.tree .is-unmeasured abbr')->each(static fn ($a) => $a->text())));
        sort($origins);
        self::assertSame(['D', 'W'], $origins);
        self::assertSame('Intérêt < 10 10–19 20–34 35–49 50+ ≈ estimé', preg_replace('/\s+/u', ' ', $crawler->filter('.score-scale')->text()));
    }

    public function testOffersTheRawKeywordsForCopyAndExport(): void
    {
        $client = static::createClient();
        $crawler = self::analyze($client, 'fr', 'pain', 'fr');

        $keywords = json_decode($crawler->filter('[data-keywords]')->text(), true);
        self::assertIsArray($keywords);

        self::assertSame(['pain commun', 98], $keywords[0], 'Bing et DuckDuckGo, qui se répètent, comptent pour une voix.');
        self::assertContains(['farine', null], $keywords, 'Un terme non mesuré n\x27a pas de score.');

        $csv = json_decode($crawler->filter('[data-csv]')->text(), true);
        self::assertIsArray($csv);
        self::assertSame(['Thème', 'Concept', 'Mot-clé', 'Intérêt (0-100)', 'Catégorie', 'Estimation (non mesuré)'], $csv['header']);
        self::assertSame(['Recettes', 'Recettes maison', 'pain commun', 98, 'Fondamentaux', null], $csv['rows'][0]);

        self::assertStringStartsWith('Fondamentaux
pain commun

', trim($crawler->filter('[data-keywords-text]')->html()));
        self::assertSame(['Plan', 'CSV', 'PDF'], $crawler->filter('.toolbar-end button')->each(static fn ($b) => trim($b->text())), 'Avec un plan, les mots-clés se copient par thème.');
        self::assertStringStartsWith('pain commun
', $crawler->filter('[data-copy-theme]')->first()->attr('data-copy-theme') ?? '');
        foreach (['[data-export]', '[data-copy-plan]', '[data-hide-low]'] as $selector) {
            self::assertSelectorExists($selector);
        }
        self::assertSame(['Collecte des suggestions…', 'Calcul de l\'intérêt…', 'Analyse IA…'], json_decode($crawler->filter('.search-form')->attr('data-loading-stages') ?? '', true));
    }

    public function testShowsOnlyTheStrongestKeywords(): void
    {
        $client = static::createClient();
        $crawler = self::analyze($client, 'fr', 'pain', 'fr');
        $max = static::getContainer()->getParameter('app.ai.max_candidates');
        self::assertIsInt($max);
        self::assertCount($max + 7, (array) json_decode($crawler->filter('[data-keywords]')->text(), true));
        self::assertMatchesRegularExpression('/\d+ expressions → '.($max + 7).' retenues/', $crawler->filter('.analysis-meta')->text());
    }

    public function testWarnsWhenASourceFails(): void
    {
        $client = static::createClient();
        self::analyze($client, 'en', 'youtube-down', 'en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.analysis-meta .is-warning', '7/8 sources (YTB : server error)');
        self::assertStringNotContainsString('YouTube', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('.tree-groups');
    }

    public function testListsTheKeywordsWhenTheAiFails(): void
    {
        $client = static::createClient();
        $crawler = self::analyze($client, 'en', 'cohere-down', 'en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fallback-note', 'No plan for this analysis');
        self::assertSelectorTextContains('.structure h2', 'Keywords');
        self::assertGreaterThan(0, $crawler->filter('.tree .term')->count());
        self::assertSelectorNotExists('.tree-groups');
        self::assertSelectorNotExists('[data-copy-plan]');
        self::assertSelectorExists('[data-export]');
    }

    public function testRepeatedSearchIsServedFromCache(): void
    {
        $client = self::clientKeepingState();
        self::analyze($client, 'fr', 'pain', 'fr');
        $calls = \count(self::fakeHttp()->requestedUrls);

        self::analyze($client, 'fr', '  Pain ', 'fr');

        self::assertResponseIsSuccessful();
        self::assertCount($calls, self::fakeHttp()->requestedUrls, 'Aucun nouvel appel réseau.');
    }

    public function testVariantsOfTheSameSearchShareTheCache(): void
    {
        $client = self::clientKeepingState();
        self::analyze($client, 'fr', 'taille haie', 'fr');
        $calls = \count(self::fakeHttp()->requestedUrls);

        self::analyze($client, 'fr', 'taille-haies', 'fr');

        self::assertResponseIsSuccessful();
        self::assertCount($calls, self::fakeHttp()->requestedUrls, 'Trait d\'union et pluriel : même recherche.');
    }

    public function testLimitsSearchesPerIp(): void
    {
        $client = self::clientKeepingState();
        for ($i = 1; $i <= 10; ++$i) {
            self::analyze($client, 'fr', 'mot '.$i, 'fr');
            self::assertResponseIsSuccessful();
        }

        self::analyze($client, 'fr', 'mot 1', 'fr');
        self::assertResponseIsSuccessful();

        self::analyze($client, 'fr', 'mot 11', 'fr');
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('h1', 'Limite atteinte');
        self::assertResponseHasHeader('Retry-After');
    }

    public function testShowsTheKeywordsWhileThePlanIsPending(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/search', ['q' => 'pain', 'lang' => 'fr']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.advice .advice-value');
        self::assertSelectorNotExists('.tree-groups');
        self::assertSame('/fr/search/plan?q=pain&lang=fr', $crawler->filter('.plan-pending')->attr('data-plan-url'));

        $plan = $client->request('GET', '/fr/search/plan', ['q' => 'pain', 'lang' => 'fr']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $plan->filter('.tree-topic'));

        $client->request('GET', '/fr/search/plan', ['q' => 'inconnu', 'lang' => 'fr']);
        self::assertResponseStatusCodeSame(410, 'Sans préparation, la page relance la recherche.');
    }

    public function testEmptyKeywordGoesBackHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/search', ['q' => '   ']);

        self::assertResponseRedirects('/fr');
    }

    public function testHandlesTheLanguages(): void
    {
        $client = static::createClient();
        $crawler = self::analyze($client, 'en', 'bread', 'de');
        self::assertSame('en', $crawler->filter('#lang option[selected]')->attr('value'), 'Langue inconnue : celle de la page.');

        $crawler = self::analyze($client, 'fr', 'pain', 'fr');

        self::assertSame('/en/search?q=pain&lang=fr', $crawler->filter('.lang-switch a[hreflang="en"]')->attr('href'));
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        self::assertCount(0, $crawler->filter('link[rel="alternate"]'));
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $server
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        $client = parent::createClient($options, $server);
        foreach (['cache.app', 'cache.rate_limiter'] as $pool) {
            $cache = static::getContainer()->get($pool);
            self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
            $cache->clear();
        }

        return $client;
    }

    // Le déroulé du navigateur : la page, le plan demandé en arrière-plan, puis la page complète servie par le cache.
    private static function analyze(KernelBrowser $client, string $locale, string $q, string $lang): Crawler
    {
        $crawler = $client->request('GET', '/'.$locale.'/search', ['q' => $q, 'lang' => $lang]);
        if (!$client->getResponse()->isSuccessful()) {
            return $crawler;
        }
        $client->request('GET', '/'.$locale.'/search/plan', ['q' => $q, 'lang' => $lang]);

        return $client->request('GET', '/'.$locale.'/search', ['q' => $q, 'lang' => $lang]);
    }

    private static function clientKeepingState(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private static function fakeHttp(): FakeHttpResponses
    {
        $fake = static::getContainer()->get(FakeHttpResponses::class);
        self::assertInstanceOf(FakeHttpResponses::class, $fake);

        return $fake;
    }
}
