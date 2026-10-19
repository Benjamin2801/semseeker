<?php

namespace App\Suggest;

final class RawSuggestion
{
    public function __construct(
        public readonly string $text,
        public readonly string $source,

        public readonly int $rank,

        public readonly bool $fromSeed,
    ) {
    }
}
