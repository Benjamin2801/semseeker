<?php

namespace App\Tests\Suggest;

use App\Suggest\CollectResult;
use App\Suggest\RawSuggestion;
use App\Suggest\Source\GoogleSource;
use App\Suggest\Source\YouTubeSource;
use App\Suggest\SourceFailure;
use App\Suggest\SuggestionCollector;
use App\Tests\Http\FakeHttpResponses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SuggestionCollectorTest extends TestCase
{
    private FakeHttpResponses $responses;
    private SuggestionCollector $collector;

    protected function setUp(): void
    {
        $this->responses = new FakeHttpResponses();
        $this->collector = new SuggestionCollector(
            new MockHttpClient($this->responses),
            [new GoogleSource(), new YouTubeSource()],
            new NullLogger(),
        );
    }

    public function testOnlyTheSeedQueryCountsAsSuchWithConcepts(): void
    {
        $result = $this->collector->collect('pain', 'fr', ['levain']);

        $fromSeed = array_values(array_unique(array_map(static fn ($s) => $s->text, array_filter($result->suggestions, static fn ($s) => $s->fromSeed))));
        self::assertSame(['pain commun', 'pain prix', 'pain hors-sujet'], $fromSeed);
        self::assertContains('levain commun', array_map(static fn ($s) => $s->text, $result->suggestions));
    }

    public function testCallsEverySourceForEveryQuery(): void
    {
        $result = $this->collector->collect('pain', 'fr', ['levain', 'farine', 'four']);

        self::assertCount(8, $this->responses->requestedUrls, '4 requêtes x 2 sources');
        self::assertSame([], $result->failedSources);
        self::assertCount(8 * 2, $result->suggestions);
    }

    public function testSendsLanguageAndCountry(): void
    {
        $this->collector->collect('bread', 'en', ['flour', 'yeast', 'oven']);

        $url = $this->responses->requestedUrls[0];
        self::assertStringContainsString('hl=en', $url);
        self::assertStringContainsString('gl=us', $url);
        self::assertStringNotContainsString('ds=yt', $url);
        self::assertStringContainsString('ds=yt', $this->responses->requestedUrls[1], 'L\'expression seule part d\'abord, sur chaque source.');
    }

    public function testKeepsRankAndSeedOrigin(): void
    {
        $result = $this->collector->collect('pain', 'fr', ['levain']);
        $first = $result->suggestions[0];

        self::assertSame('pain commun', $first->text);
        self::assertSame('google', $first->source);
        self::assertSame(0, $first->rank);
        self::assertTrue($first->fromSeed);
        self::assertFalse($result->suggestions[\count($result->suggestions) - 1]->fromSeed, '« levain » n\'est pas l\'expression seule.');
    }

    public function testReusesTheSeedRequestsStartedEarlyAndKeepsAnswersADay(): void
    {
        $collector = new SuggestionCollector(new MockHttpClient($this->responses), [new GoogleSource()], new NullLogger(), new ArrayAdapter());
        $started = $collector->start('pain', 'fr');
        $collector->collect('pain', 'fr', ['levain'], $started);
        $collector->collect('pain', 'fr', ['levain', 'four']);

        self::assertCount(3, $this->responses->requestedUrls, 'Pain et levain une fois chacun, puis four.');
    }

    public function testAFailingSourceDoesNotHideTheOthers(): void
    {
        $result = $this->collector->collect('youtube-down', 'fr');

        self::assertSame([['youtube', 'YTB', SourceFailure::SERVER_ERROR]], self::failures($result));
        self::assertStringContainsString('503', $result->failedSources[0]->detail, 'Le message d\'origine est gardé pour le diagnostic.');
        self::assertNotEmpty($result->suggestions);
        self::assertSame(['google'], array_values(array_unique(array_map(static fn (RawSuggestion $s) => $s->source, $result->suggestions))));
    }

    public function testARequestThatCouldNotStartIsRetriedOnce(): void
    {
        $collector = new SuggestionCollector(
            new MockHttpClient([
                new MockResponse('', ['error' => 'getaddrinfo() thread failed to start']),
                new MockResponse('["pain",["pain commun"],[],{}]'),
            ]),
            [new GoogleSource()],
            new NullLogger(),
        );

        $result = $collector->collect('pain', 'fr');

        self::assertSame([], $result->failedSources);
        self::assertSame(['pain commun'], array_map(static fn (RawSuggestion $s) => $s->text, $result->suggestions));
    }

    public function testATimeoutIsNotRetried(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Operation timed out after 4001 milliseconds']),
            new MockResponse('["pain",["pain commun"],[],{}]'),
        ]);
        $collector = new SuggestionCollector($client, [new GoogleSource()], new NullLogger());

        $result = $collector->collect('pain', 'fr');

        self::assertSame([['google', 'GOO', SourceFailure::TIMEOUT]], self::failures($result));
        self::assertSame([], $result->suggestions, 'Relancer doublerait l\'attente : la seconde réponse n\'est jamais demandée.');
    }

    public function testInvalidPayloadCountsAsFailure(): void
    {
        $collector = new SuggestionCollector(
            new MockHttpClient(static fn () => new MockResponse('<html>captcha</html>')),
            [new GoogleSource()],
            new NullLogger(),
        );

        $result = $collector->collect('pain', 'fr');

        self::assertSame([['google', 'GOO', SourceFailure::INVALID_RESPONSE]], self::failures($result));
        self::assertSame([], $result->suggestions);
    }

    /** @return list<array{string, string, string}> */
    private static function failures(CollectResult $result): array
    {
        return array_map(static fn (SourceFailure $f): array => [$f->source, $f->acronym, $f->reason], $result->failedSources);
    }
}
