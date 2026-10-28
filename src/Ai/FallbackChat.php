<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Un fournisseur principal et un secours : si le principal échoue (saturé, quota atteint, délai dépassé, aucune clé), la
 * même demande part chez le secours avec le temps qui reste.
 *
 * Avec une part de délai, le secours part en même temps que le principal, qui n'a que cette part du délai : une réponse
 * courte (les concepts) ne dépend plus d'un fournisseur qui cale.
 *
 * @phpstan-type Asked array{system: string, message: string, schema: array<string, mixed>, deadline: float, parallel: ?ResponseInterface}
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
        private readonly ?float $primaryShare = null,
    ) {
        $this->asked = new \WeakMap();
    }

    public function request(string $system, string $message, array $schema, float $maxDuration = 15): ResponseInterface
    {
        try {
            $response = $this->primary->request($system, $message, $schema, null !== $this->primaryShare ? $this->primaryShare * $maxDuration : $maxDuration);
        } catch (AiException) {
            return $this->fallback->request($system, $message, $schema, $maxDuration);
        }
        $parallel = null;
        if (null !== $this->primaryShare) {
            try {
                $parallel = $this->fallback->request($system, $message, $schema, $maxDuration);
            } catch (AiException) {
                // Sans secours disponible, le principal reste seul.
            }
        }
        $this->asked[$response] = ['system' => $system, 'message' => $message, 'schema' => $schema, 'deadline' => microtime(true) + $maxDuration, 'parallel' => $parallel];

        return $response;
    }

    public function decode(ResponseInterface $response): array
    {
        $asked = $this->asked[$response] ?? null;
        if (null === $asked) {
            return $this->fallback->decode($response);
        }
        try {
            $json = $this->primary->decode($response);
            $asked['parallel']?->cancel();

            return $json;
        } catch (AiException $e) {
            if (null !== $asked['parallel']) {
                return $this->fallback->decode($asked['parallel']);
            }
            $left = $asked['deadline'] - microtime(true);
            if ($left < self::FALLBACK_MIN_DURATION) {
                throw $e;
            }

            return $this->fallback->decode($this->fallback->request($asked['system'], $asked['message'], $asked['schema'], $left));
        }
    }
}
