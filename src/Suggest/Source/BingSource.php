<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BingSource extends AbstractOpenSearchSource
{
    private const ENDPOINT = 'https://api.bing.com/osjson.aspx';

    private const MARKETS = ['fr' => 'fr-FR', 'en' => 'en-US'];

    public function getName(): string
    {
        return 'bing';
    }

    public function getAcronym(): string
    {
        return 'BIN';
    }

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        return $client->request('GET', self::ENDPOINT, ['query' => [
            'query' => $query,
            'market' => self::MARKETS[$lang] ?? 'en-US',
        ]]);
    }
}
