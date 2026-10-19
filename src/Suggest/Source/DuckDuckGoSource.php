<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DuckDuckGoSource extends AbstractOpenSearchSource
{
    private const ENDPOINT = 'https://duckduckgo.com/ac/';

    private const REGIONS = ['fr' => 'fr-fr', 'en' => 'us-en'];

    public function getName(): string
    {
        return 'duckduckgo';
    }

    public function getAcronym(): string
    {
        return 'DDGO';
    }

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        return $client->request('GET', self::ENDPOINT, ['query' => [
            'q' => $query,
            'kl' => self::REGIONS[$lang] ?? 'us-en',

            'type' => 'list',
        ]]);
    }
}
