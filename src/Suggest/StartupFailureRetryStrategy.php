<?php

namespace App\Suggest;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

// Sur certains hébergements, curl n'arrive pas toujours à lancer la résolution DNS quand toutes les requêtes
// partent ensemble. L'échec est immédiat : on relance, et seulement dans ce cas.
final class StartupFailureRetryStrategy implements RetryStrategyInterface
{
    private const MESSAGES = ['thread failed to start'];

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): bool
    {
        if (null === $exception) {
            return false;
        }

        foreach (self::MESSAGES as $message) {
            if (str_contains($exception->getMessage(), $message)) {
                return true;
            }
        }

        return false;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return 50;
    }
}
