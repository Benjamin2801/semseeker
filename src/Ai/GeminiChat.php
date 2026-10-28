<?php

namespace App\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Gemini (Google AI Studio) : mesuré en octobre 2026, le plan complet en 24 à 55 s quand Cohere en mettait 50 à 76.
final class GeminiChat extends AbstractChat
{
    protected const NAME = 'Gemini';

    // Large : les modèles récents peuvent compter leur réflexion dans les jetons de sortie.
    private const MAX_TOKENS = 4000;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        string $apiKeys,
        #[Autowire(param: 'app.gemini.model')]
        string $model,
        CacheItemPoolInterface $cache = new ArrayAdapter(),
    ) {
        parent::__construct($httpClient, $apiKeys, $model, $cache);
    }

    protected function endpoint(): string
    {
        return sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', rawurlencode($this->model));
    }

    protected function options(string $apiKey, array $call): array
    {
        return [
            'headers' => ['x-goog-api-key' => $apiKey],
            'json' => [
                'systemInstruction' => ['parts' => [['text' => $call['system']]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $call['message']]]]],
                'generationConfig' => [
                    'temperature' => 0,
                    'seed' => self::SEED,
                    'maxOutputTokens' => self::MAX_TOKENS,
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $call['schema'],
                ],
            ],
        ];
    }

    protected function text(array $data): ?string
    {
        $text = null;
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            // Les morceaux de réflexion ne font pas partie de la réponse.
            if (isset($part['text']) && true !== ($part['thought'] ?? false)) {
                $text = ($text ?? '').$part['text'];
            }
        }

        return $text;
    }
}
