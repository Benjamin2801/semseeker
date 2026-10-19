<?php

namespace App\Ai;

final class PlanDraft
{
    public function __construct(
        public readonly string $seed,
        public readonly string $lang,
        /** @var list<string> */
        public readonly array $keywords,

        public readonly mixed $pending,
        /** @var list<string> */
        public readonly array $articles = [],

        // Heure (microtime) à laquelle le plan entier doit être fini.
        public readonly float $deadline = 0.0,
    ) {
    }
}
