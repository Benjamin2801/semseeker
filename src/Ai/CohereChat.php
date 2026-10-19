<?php

namespace App\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CohereChat
{
    private const ENDPOINT = 'https://api.cohere.com/v2/chat';

    private const JSON_INSTRUCTION = 'Reply with compact JSON on a single line, without indentation or line breaks.';

    // Température nulle et graine fixe : la même question donne, autant que possible, la même réponse.
    private const SEED = 42;

    private const MAX_TOKENS = 2000;

    // Une clé refusée 3 fois de suite est mise de côté un jour, 5 fois un mois.
    private const KEY_REST = [5 => 30 * 86400, 3 => 86400];

    private const KEY_ERRORS = [401, 402, 403, 429];

    /** @var list<string> */
    private readonly array $apiKeys;

    private int $next;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'COHERE_API_KEY')]
        string $apiKeys,
        #[Autowire(param: 'app.cohere.model')]
        private readonly string $model,

        private readonly CacheItemPoolInterface $cache = new ArrayAdapter(),
    ) {
        $this->apiKeys = array_values(array_filter(array_map('trim', explode(',', $apiKeys))));
        $this->next = [] !== $this->apiKeys ? random_int(0, \count($this->apiKeys) - 1) : 0;
    }

    /** @param array<string, mixed> $schema */
    public function request(string $system, string $message, array $schema, float $maxDuration = 15): ResponseInterface
    {
        if ([] === $this->apiKeys) {
            throw new AiException('COHERE_API_KEY n\'est pas défini.');
        }
        $apiKey = $this->nextKey();

        return $this->httpClient->request('POST', self::ENDPOINT, [
            'auth_bearer' => $apiKey,

            'user_data' => $apiKey,

            'timeout' => $maxDuration,
            'max_duration' => $maxDuration,
            'json' => [
                'model' => $this->model,
                'temperature' => 0,
                'seed' => self::SEED,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => [
                    ['role' => 'system', 'content' => rtrim($system)."\n".self::JSON_INSTRUCTION],
                    ['role' => 'user', 'content' => $message],
                ],
                'response_format' => ['type' => 'json_object', 'schema' => $schema],
            ],
        ]);
    }

    /** @return array<mixed> */
    public function decode(ResponseInterface $response): array
    {
        $apiKey = (string) $response->getInfo('user_data');
        try {
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            if ($e instanceof HttpExceptionInterface && \in_array($e->getResponse()->getStatusCode(), self::KEY_ERRORS, true)) {
                $this->keyRefused($apiKey);
            }
            throw new AiException('Appel à Cohere impossible : '.$e->getMessage(), previous: $e);
        }
        $this->cache->deleteItem(self::keyState($apiKey));

        foreach ($data['message']['content'] ?? [] as $part) {
            if ('text' === ($part['type'] ?? null)) {
                try {
                    return (array) json_decode((string) ($part['text'] ?? ''), true, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new AiException('Réponse de Cohere illisible.', previous: $e);
                }
            }
        }

        throw new AiException('Réponse de Cohere sans texte.');
    }

    private function nextKey(): string
    {
        $count = \count($this->apiKeys);
        for ($tries = 0; $tries < $count; ++$tries) {
            $apiKey = $this->apiKeys[$this->next++ % $count];
            if (($this->cache->getItem(self::keyState($apiKey))->get()['until'] ?? 0) <= time()) {
                return $apiKey;
            }
        }

        return $this->apiKeys[$this->next++ % $count];
    }

    private function keyRefused(string $apiKey): void
    {
        $item = $this->cache->getItem(self::keyState($apiKey));
        $failures = ($item->get()['failures'] ?? 0) + 1;
        $until = 0;
        foreach (self::KEY_REST as $threshold => $rest) {
            if ($failures >= $threshold) {
                $until = time() + $rest;
                break;
            }
        }
        $this->cache->save($item->set(['failures' => $failures, 'until' => $until])->expiresAfter(max(self::KEY_REST) + 86400));
    }

    private static function keyState(string $apiKey): string
    {
        return 'cohere.key.'.hash('xxh128', $apiKey);
    }

    /** @param list<string> $items */
    public static function numbered(array $items): string
    {
        $list = '';
        foreach ($items as $id => $item) {
            $list .= $id.'. '.$item."\n";
        }

        return $list;
    }

    public static function label(mixed $value, int $maxLength = 80): string
    {
        return mb_substr(trim((string) $value), 0, $maxLength);
    }
}
