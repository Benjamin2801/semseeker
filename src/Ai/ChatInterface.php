<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\ResponseInterface;

// Un modèle de langage qui répond en JSON : la demande part tout de suite, la réponse se lit plus tard.
interface ChatInterface
{
    /** @param array<string, mixed> $schema */
    public function request(string $system, string $message, array $schema, float $maxDuration = 15): ResponseInterface;

    /** @return array<mixed> */
    public function decode(ResponseInterface $response): array;
}
