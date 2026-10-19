<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class AmazonSource implements SuggestSourceInterface
{
    private const MARKETPLACES = [
        'fr' => ['completion.amazon.fr', 'A13V1IB3VIYZZH'],
        'en' => ['completion.amazon.com', 'ATVPDKIKX0DER'],
    ];

    public function getName(): string
    {
        return 'amazon';
    }

    public function getAcronym(): string
    {
        return 'AMZ';
    }

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        [$host, $marketplace] = self::MARKETPLACES[$lang] ?? self::MARKETPLACES['en'];

        return $client->request('GET', 'https://'.$host.'/api/2017/suggestions', ['query' => [
            'mid' => $marketplace,
            'alias' => 'aps',
            'prefix' => $query,
        ]]);
    }

    public function parse(ResponseInterface $response): array
    {
        $data = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($data['suggestions'] ?? null)) {
            throw new \UnexpectedValueException('Réponse inattendue de la source « amazon ».');
        }

        $suggestions = [];
        foreach ($data['suggestions'] as $suggestion) {
            if ('KEYWORD' === ($suggestion['type'] ?? null) && \is_string($suggestion['value'] ?? null)) {
                $suggestions[] = $suggestion['value'];
            }
        }

        return $suggestions;
    }
}
