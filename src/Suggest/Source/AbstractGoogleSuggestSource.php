<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class AbstractGoogleSuggestSource extends AbstractOpenSearchSource
{
    private const ENDPOINT = 'https://suggestqueries.google.com/complete/search';

    private const COUNTRIES = ['fr' => 'fr', 'en' => 'us'];

    abstract protected function getDataset(): ?string;

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        $params = [
            'client' => 'firefox',
            'hl' => $lang,
            'gl' => self::COUNTRIES[$lang] ?? $lang,
            'ie' => 'utf-8',
            'oe' => 'utf-8',
            'q' => $query,
        ];
        if (null !== $dataset = $this->getDataset()) {
            $params['ds'] = $dataset;
        }

        return $client->request('GET', self::ENDPOINT, ['query' => $params]);
    }
}
