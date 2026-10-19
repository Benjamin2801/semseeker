<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AutocompleteControllerTest extends WebTestCase
{
    public function testReturnsGoogleSuggestions(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/autocomplete', ['q' => 'Pain', 'lang' => 'fr']);

        self::assertResponseIsSuccessful();
        self::assertSame(['pain commun', 'pain prix'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testIgnoresTooShortQueries(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/autocomplete', ['q' => 'p']);

        self::assertSame('[]', $client->getResponse()->getContent());
    }
}
