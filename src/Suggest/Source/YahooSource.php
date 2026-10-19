<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class YahooSource implements SuggestSourceInterface
{
    private const MARKETS = [
        'fr' => ['fr.search.yahoo.com', 'gossip-fr-ura'],
        'en' => ['search.yahoo.com', 'gossip-us-ura'],
    ];

    public function getName(): string
    {
        return 'yahoo';
    }

    public function getAcronym(): string
    {
        return 'YAH';
    }

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        [$host, $list] = self::MARKETS[$lang] ?? self::MARKETS['en'];

        return $client->request('GET', 'https://'.$host.'/sugg/gossip/'.$list.'/', ['query' => [
            'output' => 'sd1',
            'command' => $query,
        ]]);
    }

    public function parse(ResponseInterface $response): array
    {
        $data = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($data['r'] ?? null)) {
            throw new \UnexpectedValueException('Réponse inattendue de la source « yahoo ».');
        }

        return array_values(array_filter(array_column($data['r'], 'k'), 'is_string'));
    }
}
