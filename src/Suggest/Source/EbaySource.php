<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class EbaySource implements SuggestSourceInterface
{
    private const ENDPOINT = 'https://autosug.ebaystatic.com/autosug';

    private const SITES = ['fr' => 71, 'en' => 0];

    public function getName(): string
    {
        return 'ebay';
    }

    public function getAcronym(): string
    {
        return 'EBY';
    }

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface
    {
        return $client->request('GET', self::ENDPOINT, ['query' => [
            'kwd' => $query,
            'sId' => self::SITES[$lang] ?? 0,
            '_jgr' => 1,
        ]]);
    }

    public function parse(ResponseInterface $response): array
    {
        $content = $response->getContent();
        $start = strpos($content, '(');
        $end = strrpos($content, ')');
        if (false === $start || false === $end || $end <= $start) {
            throw new \UnexpectedValueException('Réponse inattendue de la source « ebay ».');
        }

        $data = json_decode(substr($content, $start + 1, $end - $start - 1), true, flags: \JSON_THROW_ON_ERROR);

        return array_values(array_map(
            static fn (string $s): string => html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            array_filter((array) ($data['res']['sug'] ?? []), 'is_string'),
        ));
    }
}
