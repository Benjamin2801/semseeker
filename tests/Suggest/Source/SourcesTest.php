<?php

namespace App\Tests\Suggest\Source;

use App\Suggest\Source\AmazonSource;
use App\Suggest\Source\BingSource;
use App\Suggest\Source\DuckDuckGoSource;
use App\Suggest\Source\EbaySource;
use App\Suggest\Source\GoogleSource;
use App\Suggest\Source\IndeedSource;
use App\Suggest\Source\SuggestSourceInterface;
use App\Suggest\Source\YahooSource;
use App\Suggest\Source\YouTubeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SourcesTest extends TestCase
{
    private const OPEN_SEARCH = '["vélo",["vélo électrique","vélo cargo"],[],{}]';

    #[DataProvider('sources')]
    public function testBuildsTheRequestAndParsesTheAnswer(SuggestSourceInterface $source, string $lang, string $expectedUrl, string $body): void
    {
        $response = new MockResponse($body);
        $client = new MockHttpClient($response);

        $result = $source->parse($source->request($client, 'vélo', $lang));

        self::assertSame($expectedUrl, $response->getRequestUrl());
        self::assertSame(['vélo électrique', 'vélo cargo'], $result);
    }

    /** @return iterable<string, array{SuggestSourceInterface, string, string, string}> */
    public static function sources(): iterable
    {
        yield 'google' => [new GoogleSource(), 'fr',
            'https://suggestqueries.google.com/complete/search?client=firefox&hl=fr&gl=fr&ie=utf-8&oe=utf-8&q=v%C3%A9lo', self::OPEN_SEARCH];
        yield 'youtube' => [new YouTubeSource(), 'en',
            'https://suggestqueries.google.com/complete/search?client=firefox&hl=en&gl=us&ie=utf-8&oe=utf-8&q=v%C3%A9lo&ds=yt', self::OPEN_SEARCH];
        yield 'bing' => [new BingSource(), 'fr',
            'https://api.bing.com/osjson.aspx?query=v%C3%A9lo&market=fr-FR', self::OPEN_SEARCH];
        yield 'duckduckgo' => [new DuckDuckGoSource(), 'en',
            'https://duckduckgo.com/ac/?q=v%C3%A9lo&kl=us-en&type=list', self::OPEN_SEARCH];
        yield 'ebay fr' => [new EbaySource(), 'fr',
            'https://autosug.ebaystatic.com/autosug?kwd=v%C3%A9lo&sId=71&_jgr=1',
            '/**/vjo.darwin.domain.finding.autofill.AutoFill._do({"prefix":"v&eacute;lo","dict":"0","res":{"sug":["vélo électrique","v&eacute;lo cargo"]}})'];
        yield 'ebay en' => [new EbaySource(), 'en',
            'https://autosug.ebaystatic.com/autosug?kwd=v%C3%A9lo&sId=0&_jgr=1',
            '/**/vjo.AutoFill._do({"res":{"sug":["vélo électrique","vélo cargo"]}})'];
        yield 'amazon fr' => [new AmazonSource(), 'fr',
            'https://completion.amazon.fr/api/2017/suggestions?mid=A13V1IB3VIYZZH&alias=aps&prefix=v%C3%A9lo',
            '{"alias":"aps","suggestions":[{"type":"KEYWORD","value":"vélo électrique"},{"type":"WIDGET","value":"pub"},{"type":"KEYWORD","value":"vélo cargo"}]}'];
        yield 'amazon en' => [new AmazonSource(), 'en',
            'https://completion.amazon.com/api/2017/suggestions?mid=ATVPDKIKX0DER&alias=aps&prefix=v%C3%A9lo',
            '{"suggestions":[{"type":"KEYWORD","value":"vélo électrique"},{"type":"KEYWORD","value":"vélo cargo"}]}'];
        yield 'yahoo fr' => [new YahooSource(), 'fr',
            'https://fr.search.yahoo.com/sugg/gossip/gossip-fr-ura/?output=sd1&command=v%C3%A9lo',
            '{"q":"vélo","l":{"gprid":"x"},"r":[{"k":"vélo électrique","m":0},{"k":"vélo cargo","m":6}]}'];
        yield 'yahoo en' => [new YahooSource(), 'en',
            'https://search.yahoo.com/sugg/gossip/gossip-us-ura/?output=sd1&command=v%C3%A9lo',
            '{"q":"vélo","r":[{"k":"vélo électrique"},{"k":"vélo cargo"}]}'];
        yield 'indeed fr' => [new IndeedSource(), 'fr',
            'https://autocomplete.indeed.com/api/v0/suggestions/what?country=FR&language=fr&count=10&query=v%C3%A9lo',
            '["vélo électrique","vélo cargo"]'];
        yield 'indeed en' => [new IndeedSource(), 'en',
            'https://autocomplete.indeed.com/api/v0/suggestions/what?country=US&language=en&count=10&query=v%C3%A9lo',
            '["vélo électrique","vélo cargo"]'];
    }

    public function testEbayWithoutSuggestionReturnsAnEmptyList(): void
    {
        $source = new EbaySource();
        $response = $source->request(new MockHttpClient(new MockResponse('/**/vjo.AutoFill._do({"prefix":"zzqx","dict":"0"})')), 'zzqx', 'fr');

        self::assertSame([], $source->parse($response));
    }

    #[DataProvider('sources')]
    public function testRejectsAnUnexpectedAnswer(SuggestSourceInterface $source, string $lang): void
    {
        $this->expectException(\Throwable::class);

        $source->parse($source->request(new MockHttpClient(new MockResponse('{"error":"blocked"}')), 'vélo', $lang));
    }
}
