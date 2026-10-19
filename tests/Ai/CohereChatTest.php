<?php

namespace App\Tests\Ai;

use App\Ai\AiException;
use App\Ai\CohereChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CohereChatTest extends TestCase
{
    public function testSendsAJsonSchemaRequest(): void
    {
        $response = new MockResponse('{}');
        $chat = new CohereChat(new MockHttpClient($response), 'secret-key', 'command-a-03-2025');

        $chat->request('system', 'message', ['type' => 'object']);

        self::assertSame('https://api.cohere.com/v2/chat', $response->getRequestUrl());
        self::assertContains('Authorization: Bearer secret-key', $response->getRequestOptions()['headers']);
        $body = json_decode($response->getRequestOptions()['body'], true);
        self::assertSame('command-a-03-2025', $body['model']);
        self::assertSame(0, $body['temperature']);
        self::assertSame(42, $body['seed']);
        self::assertSame(2000, $body['max_tokens']);
        self::assertSame(['type' => 'json_object', 'schema' => ['type' => 'object']], $body['response_format']);
        self::assertSame("system\nReply with compact JSON on a single line, without indentation or line breaks.", $body['messages'][0]['content']);
        self::assertSame(['role' => 'user', 'content' => 'message'], $body['messages'][1]);
    }

    public function testDecodesTheTextPart(): void
    {
        $chat = new CohereChat(new MockHttpClient(new MockResponse(json_encode([
            'message' => ['content' => [['type' => 'thinking', 'thinking' => '…'], ['type' => 'text', 'text' => '{"ok":true}']]],
        ], \JSON_THROW_ON_ERROR))), 'key', 'model');

        self::assertSame(['ok' => true], $chat->decode($chat->request('s', 'm', [])));
    }

    #[DataProvider('failures')]
    public function testFailuresBecomeAnAiException(string $keys, MockResponse $response): void
    {
        $chat = new CohereChat(new MockHttpClient($response), $keys, 'model');

        $this->expectException(AiException::class);
        $chat->decode($chat->request('s', 'm', []));
    }

    /** @return iterable<string, array{string, MockResponse}> */
    public static function failures(): iterable
    {
        yield 'erreur HTTP' => ['key', new MockResponse('', ['http_code' => 429])];
        yield 'réponse illisible' => ['key', new MockResponse(json_encode(['message' => ['content' => [['type' => 'text', 'text' => 'pas du json']]]], \JSON_THROW_ON_ERROR))];
        yield 'aucune clé' => ['', new MockResponse('{}')];
    }

    public function testEachCallTakesTheNextKey(): void
    {
        $keys = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$keys): MockResponse {
            $keys[] = substr($options['normalized_headers']['authorization'][0], \strlen('Authorization: Bearer '));

            return new MockResponse('{}');
        });
        $chat = new CohereChat($client, 'a, b ,c', 'model');

        for ($i = 0; $i < 6; ++$i) {
            $chat->request('s', 'm', []);
        }

        self::assertEqualsCanonicalizing(['a', 'b', 'c'], \array_slice($keys, 0, 3), 'Trois appels, trois clés différentes.');
        self::assertSame(\array_slice($keys, 0, 3), \array_slice($keys, 3), 'Puis le tour recommence.');
    }

    public function testAKeyRefusedThreeTimesInARowRestsForADay(): void
    {
        $cache = new ArrayAdapter();
        $keys = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$keys): MockResponse {
            $key = substr($options['normalized_headers']['authorization'][0], \strlen('Authorization: Bearer '));
            $keys[] = $key;

            return 'bad' === $key ? new MockResponse('{"message":"invalid api token"}', ['http_code' => 401]) : new MockResponse('{"message":{"content":[]}}');
        });

        $bad = new CohereChat($client, 'bad', 'model', $cache);
        for ($i = 0; $i < 3; ++$i) {
            try {
                $bad->decode($bad->request('s', 'm', []));
            } catch (AiException) {
            }
        }
        $state = $cache->getItem('cohere.key.'.hash('xxh128', 'bad'))->get();
        self::assertSame(3, $state['failures']);
        self::assertEqualsWithDelta(time() + 86400, $state['until'], 5);

        $chat = new CohereChat($client, 'bad,good', 'model', $cache);
        for ($i = 0; $i < 3; ++$i) {
            $chat->request('s', 'm', []);
        }
        self::assertSame(['bad', 'bad', 'bad', 'good', 'good', 'good'], $keys);
    }

    public function testFiveRefusalsInARowMeanAMonthAndASuccessResetsTheCount(): void
    {
        $cache = new ArrayAdapter();
        $responses = [
            ...array_fill(0, 5, new MockResponse('', ['http_code' => 429])),
            new MockResponse('', ['http_code' => 500]),
        ];
        $chat = new CohereChat(new MockHttpClient($responses), 'key', 'model', $cache);
        foreach ($responses as $ignored) {
            try {
                $chat->decode($chat->request('s', 'm', []));
            } catch (AiException) {
            }
        }
        $state = $cache->getItem('cohere.key.'.hash('xxh128', 'key'))->get();
        self::assertSame(5, $state['failures'], 'Une panne de Cohere (500) ne compte pas contre la clé.');
        self::assertEqualsWithDelta(time() + 30 * 86400, $state['until'], 5);

        $ok = new CohereChat(new MockHttpClient(new MockResponse('{"message":{"content":[{"type":"text","text":"{}"}]}}')), 'key', 'model', $cache);
        $ok->decode($ok->request('s', 'm', []));
        self::assertFalse($cache->hasItem('cohere.key.'.hash('xxh128', 'key')), 'Une réponse réussie remet le compte à zéro.');
    }
}
