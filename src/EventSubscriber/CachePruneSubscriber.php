<?php

namespace App\EventSubscriber;

use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

// Pas de tâche planifiée sur l'hébergement : on purge le cache de temps en temps, une fois la réponse envoyée.
final class CachePruneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly PruneableInterface $results,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly PruneableInterface $rateLimits,

        private readonly int $oneIn = 100,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'prune'];
    }

    public function prune(): void
    {
        if (1 !== random_int(1, $this->oneIn)) {
            return;
        }

        $this->results->prune();
        $this->rateLimits->prune();
    }
}
