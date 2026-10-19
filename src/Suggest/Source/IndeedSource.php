<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class IndeedSource implements SuggestSourceInterface
{
    private const ENDPOINT = 'https://autocomplete.indeed.com/api/v0/suggestions/what';

    private const MARKETS = [
        'fr' => ['FR', 'fr'],
        'en' => ['US', 'en'],
    ];

    public function getName(): string
    {
        return 'indeed';
    }

    public function getAcronym(): string
    {
        return 'IND';
    }

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        [$country, $language] = self::MARKETS[$lang] ?? self::MARKETS['en'];

        return $client->request('GET', self::ENDPOINT, ['query' => [
            'country' => $country,
            'language' => $language,
            'count' => 10,
            'query' => $query,
        ]]);
    }

    public function parse(ResponseInterface $response): array
    {
        $data = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($data) || !array_is_list($data)) {
            throw new \UnexpectedValueException('Réponse inattendue de la source « indeed ».');
        }

        return array_values(array_filter($data, 'is_string'));
    }
}
