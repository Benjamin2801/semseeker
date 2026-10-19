<?php

namespace App\Tests\Suggest;

use App\Suggest\SourceFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SourceFailureTest extends TestCase
{
    #[DataProvider('errors')]
    public function testReasonFromTheError(MockResponse $response, string $expected): void
    {
        try {
            (new MockHttpClient($response))->request('GET', 'https://example.com')->getContent();
            self::fail('Une exception était attendue.');
        } catch (\Throwable $e) {
            self::assertSame($expected, SourceFailure::reasonOf($e));
        }
    }

    /** @return iterable<string, array{MockResponse, string}> */
    public static function errors(): iterable
    {
        yield 'accès refusé' => [new MockResponse('', ['http_code' => 403]), SourceFailure::BLOCKED];
        yield 'trop de requêtes' => [new MockResponse('', ['http_code' => 429]), SourceFailure::RATE_LIMITED];
        yield 'erreur serveur' => [new MockResponse('', ['http_code' => 503]), SourceFailure::SERVER_ERROR];
        yield 'délai dépassé' => [new MockResponse([''], ['timeout' => 0.1]), SourceFailure::TIMEOUT];
        yield 'délai de curl' => [new MockResponse('', ['error' => 'Operation timed out after 4001 milliseconds']), SourceFailure::TIMEOUT];
        yield 'réseau' => [new MockResponse('', ['error' => 'Could not resolve host']), SourceFailure::NETWORK];
    }

    public function testAnUnreadableAnswerIsInvalid(): void
    {
        self::assertSame(SourceFailure::INVALID_RESPONSE, SourceFailure::reasonOf(new \JsonException('Syntax error')));
    }
}
