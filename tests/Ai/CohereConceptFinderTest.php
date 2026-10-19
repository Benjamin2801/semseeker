<?php

namespace App\Tests\Ai;

use App\Ai\AiException;
use App\Ai\CohereChat;
use App\Ai\CohereConceptFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CohereConceptFinderTest extends TestCase
{
    public function testKeepsEightDistinctConceptsOtherThanTheSeed(): void
    {
        $finder = self::finder(['concepts' => ['Pâte à pizza', 'PIZZA', 'pizzeria', 'pâte à pizza', '', 'four à pizza', 'calzone', 'focaccia', 'livraison repas', 'pizza napolitaine', 'mozzarella', 'cuisine italienne']]);

        self::assertSame(['pâte à pizza', 'pizzeria', 'four à pizza', 'calzone', 'focaccia', 'livraison repas', 'mozzarella', 'cuisine italienne'], $finder->concepts('pizza', 'fr')->get(), 'Sans « pizza napolitaine », que les moteurs trouvent déjà.');
    }

    public function testNamesTheSubjectAndTheCoreTerms(): void
    {
        $pending = self::finder(['concepts' => ['boucherie halal'], 'subject' => ' Charcuterie ', 'core' => ['Merguez', 'charcuterie halal', 'merguez', 'certification halal']])->concepts('charcuterie halal', 'fr');

        self::assertSame('charcuterie', $pending->subject());
        self::assertSame(['merguez', 'certification halal'], $pending->coreTerms(), 'Sans doublon ni l\'expression elle-même.');
    }

    public function testSendsTheSeedAndLanguage(): void
    {
        $body = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode($options['body'], true);

            return self::reply(['concepts' => []]);
        });

        (new CohereConceptFinder(new CohereChat($client, 'key', 'model')))->concepts('pizza', 'fr')->get();

        self::assertSame("Seed: pizza\nLanguage: fr", $body['messages'][1]['content']);
    }

    public function testKeepsTheConceptsOfAPhraseForAWeek(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return self::reply(['concepts' => ['pizzeria']]);
        });
        $finder = new CohereConceptFinder(new CohereChat($client, 'key', 'model'), new ArrayAdapter());

        $finder->concepts('pizza', 'fr')->get();

        self::assertSame(['pizzeria'], $finder->concepts('Pizzas', 'fr')->get(), 'Même expression, au pluriel près.');
        self::assertSame(1, $calls);
    }

    public function testFailsOnlyWhenTheConceptsAreRead(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"overloaded"}', ['http_code' => 503]));
        $pending = (new CohereConceptFinder(new CohereChat($client, 'key', 'model')))->concepts('pizza', 'fr');

        $this->expectException(AiException::class);
        $pending->get();
    }

    /** @param array<string, mixed> $json */
    private static function finder(array $json): CohereConceptFinder
    {
        return new CohereConceptFinder(new CohereChat(new MockHttpClient(self::reply($json)), 'key', 'model'));
    }

    /** @param array<string, mixed> $json */
    private static function reply(array $json): MockResponse
    {
        return new MockResponse(json_encode(['message' => ['content' => [['type' => 'text', 'text' => json_encode($json, JSON_THROW_ON_ERROR)]]]], \JSON_THROW_ON_ERROR));
    }
}
