<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testRootRedirectsToPreferredLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);

        self::assertResponseRedirects('/en');
    }

    public function testRootFallsBackToFrench(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);

        self::assertResponseRedirects('/fr');
    }

    #[DataProvider('localizedHomes')]
    public function testHomeIsTranslated(string $locale, string $title): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $title);
        self::assertSame($locale, $crawler->filter('html')->attr('lang'));
        self::assertCount(3, $crawler->filter('link[rel="alternate"][hreflang]'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function localizedHomes(): iterable
    {
        yield 'fr' => ['fr', 'Trouvez les angles décisifs.'];
        yield 'en' => ['en', 'Find the decisive angles.'];
    }

    public function testUnknownLocaleIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUrlsKeepTheSubfolderPrefix(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/semseeker/en', server: [
            'SCRIPT_NAME' => '/semseeker/index.php',
            'SCRIPT_FILENAME' => '/home/site/semseeker/index.php',
        ]);

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#^/semseeker/css/app\.css\?v=\d+$#', $crawler->filter('link[rel="stylesheet"]')->attr('href') ?? '');
        self::assertSame('/semseeker/fr', $crawler->filter('.lang-switch a[hreflang="fr"]')->attr('href'));
    }
}
