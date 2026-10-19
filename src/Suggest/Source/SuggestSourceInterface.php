<?php

namespace App\Suggest\Source;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AutoconfigureTag(self::TAG)]
interface SuggestSourceInterface
{
    public const TAG = 'app.suggest_source';

    public function getName(): string;

    public function getAcronym(): string;

    public function request(HttpClientInterface $client, string $query, string $lang): ResponseInterface;

    /** @return list<string> */
    public function parse(ResponseInterface $response): array;
}
