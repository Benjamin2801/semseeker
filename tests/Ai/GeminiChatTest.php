<?php

namespace App\Tests\Ai;

use App\Ai\GeminiChat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiChatTest extends TestCase
{
    public function testSendsTheSchemaAndReadsTheAnswerWithoutTheThoughts(): void
    {
        $response = new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [
            ['text' => 'je réfléchis', 'thought' => true],
            ['text' => '{"ok":true}'],
        ]]]]], \JSON_THROW_ON_ERROR));
        $chat = new GeminiChat(new MockHttpClient($response), 'secret-key', 'gemini-3.5-flash-lite');

        self::assertSame(['ok' => true], $chat->decode($chat->request('system', 'message', ['type' => 'object'])));
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent', $response->getRequestUrl());
        self::assertContains('x-goog-api-key: secret-key', $response->getRequestOptions()['headers']);
        $body = json_decode($response->getRequestOptions()['body'], true);
        self::assertSame('system', $body['systemInstruction']['parts'][0]['text']);
        self::assertSame(['type' => 'object'], $body['generationConfig']['responseJsonSchema']);
        self::assertSame(0, $body['generationConfig']['temperature']);
    }
}
