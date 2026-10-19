<?php

namespace App\Suggest\Source;

use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class AbstractOpenSearchSource implements SuggestSourceInterface
{
    public function parse(ResponseInterface $response): array
    {
        $data = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($data) || !\is_array($data[1] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Réponse inattendue de la source « %s ».', $this->getName()));
        }

        return array_values(array_filter($data[1], 'is_string'));
    }
}
