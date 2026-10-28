<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Un fournisseur principal et un secours : si le principal échoue (saturé, quota atteint, délai dépassé, aucune clé), la
 * même demande part chez le secours avec le temps qui reste.
 *
 * @phpstan-type Asked array{system: string, message: string, schema: array<string, mixed>, deadline: float}
 */
final class FallbackChat implements ChatInterface
{
    // Moins de temps que ça, le secours n'aurait aucune chance de répondre.
    private const FALLBACK_MIN_DURATION = 5;

    /** @var \WeakMap<ResponseInterface, Asked> */
    private \WeakMap $asked;

    public function __construct(
        private readonly ChatInterface $primary,
        private readonly ChatInterface $fallback,
    ) {
        $this->asked = new \WeakMap();
    }

    public function request(string $system, string $message, array $schema, float $maxDuration = 15): ResponseInterface
    {
        try {
            $response = $this->primary->request($system, $message, $schema, $maxDuration);
        } catch (AiException) {
            return $this->fallback->request($system, $message, $schema, $maxDuration);
        }
        $this->asked[$response] = ['system' => $system, 'message' => $message, 'schema' => $schema, 'deadline' => microtime(true) + $maxDuration];

        return $response;
    }

    public function decode(ResponseInterface $response): array
    {
        $asked = $this->asked[$response] ?? null;
        if (null === $asked) {
            return $this->fallback->decode($response);
        }
        try {
            return $this->primary->decode($response);
        } catch (AiException $e) {
            $left = $asked['deadline'] - microtime(true);
            if ($left < self::FALLBACK_MIN_DURATION) {
                throw $e;
            }

            return $this->fallback->decode($this->fallback->request($asked['system'], $asked['message'], $asked['schema'], $left));
        }
    }
}
