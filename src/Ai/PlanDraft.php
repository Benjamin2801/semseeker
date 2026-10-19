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
    ) {
    }
}
