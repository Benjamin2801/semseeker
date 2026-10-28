<?php

namespace App\Tests\Ai;

use App\Ai\AiException;
use App\Ai\CohereChat;
use App\Ai\FallbackChat;
use App\Ai\GeminiChat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FallbackChatTest extends TestCase
{
    private const COHERE_OK = '{"message":{"content":[{"type":"text","text":"{\"from\":\"cohere\"}"}]}}';

    public function testAsksTheFallbackWhenThePrimaryFails(): void
    {
        $gemini = new GeminiChat(new MockHttpClient(new MockResponse('{"error":{"code":503}}', ['http_code' => 503])), 'g', 'model');
        $cohere = new MockHttpClient(new MockResponse(self::COHERE_OK));
        $chat = new FallbackChat($gemini, new CohereChat($cohere, 'c', 'model'));

        self::assertSame(['from' => 'cohere'], $chat->decode($chat->request('s', 'm', [], 30)));
        self::assertSame(1, $cohere->getRequestsCount());
    }

    public function testGoesStraightToTheFallbackWithoutPrimaryKey(): void
    {
        $chat = new FallbackChat(new GeminiChat(new MockHttpClient(), '', 'model'), new CohereChat(new MockHttpClient(new MockResponse(self::COHERE_OK)), 'c', 'model'));

        self::assertSame(['from' => 'cohere'], $chat->decode($chat->request('s', 'm', [])));
    }

    public function testDoesNotTryTheFallbackWithoutTimeLeft(): void
    {
        $gemini = new GeminiChat(new MockHttpClient(new MockResponse('', ['http_code' => 503])), 'g', 'model');
        $cohere = new MockHttpClient();
        $chat = new FallbackChat($gemini, new CohereChat($cohere, 'c', 'model'));

        $this->expectException(AiException::class);
        try {
            $chat->decode($chat->request('s', 'm', [], 2));
        } finally {
            self::assertSame(0, $cohere->getRequestsCount(), 'Moins de 5 s : le secours n\'aurait pas le temps de répondre.');
        }
    }
}
