<?php

namespace App\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Ce que partagent les fournisseurs : clés utilisées à tour de rôle et mises de côté quand elles sont refusées, relance
 * d'un échec et doublon d'un appel qui tarde. Chaque fournisseur ne décrit que sa requête et sa réponse.
 *
 * @phpstan-type Call array{system: string, message: string, schema: array<string, mixed>, deadline: float, hedgeAt: float}
 */
abstract class AbstractChat implements ChatInterface
{
    protected const NAME = '';

    protected const JSON_INSTRUCTION = 'Reply with compact JSON on a single line, without indentation or line breaks.';

    // Température nulle et graine fixe : la même question donne, autant que possible, la même réponse.
    protected const SEED = 42;

    // Une clé refusée 3 fois de suite est mise de côté un jour, 5 fois un mois.
    private const KEY_REST = [5 => 30 * 86400, 3 => 86400];

    private const KEY_ERRORS = [401, 402, 403, 429];

    // Mesuré sur 102 appels Cohere de 5 jetons : la moitié répond en moins de 2 s, 15 % entre 8 et 17 s, 6 % échouent tout
    // de suite. Un échec est relancé sur une autre clé ; une réponse qui tarde au-delà de la moitié de son délai est
    // doublée sur une autre clé, et la première arrivée gagne.
    private const HEDGE_SHARE = 0.5;
    private const HEDGE_MIN_DELAY = 4;
    private const RETRY_MIN_DURATION = 3;
    private const MAX_ATTEMPTS = 3;

    /** @var list<string> */
    private readonly array $apiKeys;

    private int $next;

    /** @var \WeakMap<ResponseInterface, Call> ce qui a été demandé, pour relancer ou doubler l'appel */
    private \WeakMap $calls;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $apiKeys,
        protected readonly string $model,
        private readonly CacheItemPoolInterface $cache = new ArrayAdapter(),
    ) {
        $this->apiKeys = array_values(array_filter(array_map('trim', explode(',', $apiKeys))));
        $this->next = [] !== $this->apiKeys ? random_int(0, \count($this->apiKeys) - 1) : 0;
        $this->calls = new \WeakMap();
    }

    abstract protected function endpoint(): string;

    /**
     * En-têtes et corps de la requête.
     *
     * @param Call $call
     *
     * @return array<string, mixed>
     */
    abstract protected function options(string $apiKey, array $call): array;

    /**
     * Le texte JSON de la réponse, ou null s'il n'y en a pas.
     *
     * @param array<mixed> $data
     */
    abstract protected function text(array $data): ?string;

    public function request(string $system, string $message, array $schema, float $maxDuration = 15): ResponseInterface
    {
        if ([] === $this->apiKeys) {
            throw new AiException(static::NAME.' : aucune clé définie.');
        }
        $now = microtime(true);

        return $this->send([
            'system' => $system,
            'message' => $message,
            'schema' => $schema,
            'deadline' => $now + $maxDuration,
            'hedgeAt' => $now + max(self::HEDGE_MIN_DELAY, self::HEDGE_SHARE * $maxDuration),
        ], $maxDuration);
    }

    public function decode(ResponseInterface $response): array
    {
        $response = $this->settle($response);
        $apiKey = self::keyOf($response);
        try {
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            if ($e instanceof HttpExceptionInterface && \in_array($e->getResponse()->getStatusCode(), self::KEY_ERRORS, true)) {
                $this->keyRefused($apiKey);
            }
            throw new AiException('Appel à '.static::NAME.' impossible : '.$e->getMessage(), previous: $e);
        }
        $this->cache->deleteItem($this->keyState($apiKey));

        $text = $this->text($data);
        if (null === $text) {
            throw new AiException('Réponse de '.static::NAME.' sans texte.');
        }
        try {
            return (array) json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiException('Réponse de '.static::NAME.' illisible.', previous: $e);
        }
    }

    /** @param Call $call */
    private function send(array $call, ?float $duration = null): ResponseInterface
    {
        $apiKey = $this->nextKey();
        $duration ??= max(0.1, $call['deadline'] - microtime(true));

        $response = $this->httpClient->request('POST', $this->endpoint(), ['user_data' => $apiKey, 'timeout' => $duration, 'max_duration' => $duration] + $this->options($apiKey, $call));
        $this->calls[$response] = $call;

        return $response;
    }

    // La réponse retenue : la première réussie parmi l'appel, ses relances et son doublon, ou le dernier échec.
    private function settle(ResponseInterface $first): ResponseInterface
    {
        $call = $this->calls[$first] ?? null;
        if (null === $call) {
            return $first;
        }
        $live = [$first];
        $attempts = 1;
        $hedged = false;
        while (true) {
            $done = $this->firstDone($live, $hedged ? $call['deadline'] : min($call['deadline'], $call['hedgeAt']), $hedged);
            if (null === $done) {
                if (microtime(true) >= $call['deadline']) {
                    foreach (\array_slice($live, 1) as $response) {
                        $response->cancel();
                    }

                    return $live[0];
                }
                if (!$hedged && $this->canRetry($call, $attempts)) {
                    $live[] = $this->send($call);
                    ++$attempts;
                }
                $hedged = true;
                continue;
            }
            $live = array_values(array_filter($live, static fn (ResponseInterface $r): bool => $r !== $done));
            $code = self::statusOf($done);
            if ($code >= 200 && $code < 300) {
                foreach ($live as $response) {
                    $response->cancel();
                }

                return $done;
            }
            if ([] !== $live) {
                $this->discard($done, $code);
                continue;
            }
            if (!$this->canRetry($call, $attempts)) {
                return $done;
            }
            $this->discard($done, $code);
            $live[] = $this->send($call);
            ++$attempts;
        }
    }

    /** @param list<ResponseInterface> $live */
    private function firstDone(array $live, float $until, bool $waitAll): ?ResponseInterface
    {
        foreach ($this->httpClient->stream($live, max(0.01, $until - microtime(true))) as $response => $chunk) {
            try {
                if ($chunk->isTimeout()) {
                    // Une fois doublée, on attend la première réponse jusqu'au bout du délai.
                    if (!$waitAll || microtime(true) >= $until) {
                        return null;
                    }
                    continue;
                }
                // Le code lu dès le premier morceau : un échec se sait sans attendre le corps.
                if ($chunk->isLast() || ($chunk->isFirst() && $response->getStatusCode() >= 300)) {
                    return $response;
                }
            } catch (TransportExceptionInterface) {
                return $response;
            }
        }

        return null;
    }

    /** @param Call $call */
    private function canRetry(array $call, int $attempts): bool
    {
        return $attempts < self::MAX_ATTEMPTS && \count($this->apiKeys) > 1 && $call['deadline'] - microtime(true) >= self::RETRY_MIN_DURATION;
    }

    // Une tentative écartée : sa clé est comptée comme refusée si le fournisseur l'a refusée.
    private function discard(ResponseInterface $response, int $code): void
    {
        if (\in_array($code, self::KEY_ERRORS, true)) {
            $this->keyRefused(self::keyOf($response));
        }
        $response->cancel();
    }

    private static function statusOf(ResponseInterface $response): int
    {
        try {
            return $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            return 0;
        }
    }

    private static function keyOf(ResponseInterface $response): string
    {
        return (string) $response->getInfo('user_data');
    }

    private function nextKey(): string
    {
        $count = \count($this->apiKeys);
        for ($tries = 0; $tries < $count; ++$tries) {
            $apiKey = $this->apiKeys[$this->next++ % $count];
            if (($this->cache->getItem($this->keyState($apiKey))->get()['until'] ?? 0) <= time()) {
                return $apiKey;
            }
        }

        return $this->apiKeys[$this->next++ % $count];
    }

    private function keyRefused(string $apiKey): void
    {
        $item = $this->cache->getItem($this->keyState($apiKey));
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

    private function keyState(string $apiKey): string
    {
        return strtolower(static::NAME).'.key.'.hash('xxh128', $apiKey);
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
