<?php

namespace App\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @phpstan-import-type Call from AbstractChat */
final class CohereChat extends AbstractChat
{
    protected const NAME = 'Cohere';

    private const MAX_TOKENS = 2000;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'COHERE_API_KEY')]
        string $apiKeys,
        #[Autowire(param: 'app.cohere.model')]
        string $model,
        CacheItemPoolInterface $cache = new ArrayAdapter(),
    ) {
        parent::__construct($httpClient, $apiKeys, $model, $cache);
    }

    protected function endpoint(): string
    {
        return 'https://api.cohere.com/v2/chat';
    }

    protected function options(string $apiKey, array $call): array
    {
        return [
            'auth_bearer' => $apiKey,
            'json' => [
                'model' => $this->model,
                'temperature' => 0,
                'seed' => self::SEED,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => [
                    ['role' => 'system', 'content' => rtrim($call['system'])."\n".self::JSON_INSTRUCTION],
                    ['role' => 'user', 'content' => $call['message']],
                ],
                'response_format' => ['type' => 'json_object', 'schema' => $call['schema']],
            ],
        ];
    }

    protected function text(array $data): ?string
    {
        foreach ($data['message']['content'] ?? [] as $part) {
            if ('text' === ($part['type'] ?? null)) {
                return (string) ($part['text'] ?? '');
            }
        }

        return null;
    }
}
