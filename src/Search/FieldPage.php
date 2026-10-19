<?php

namespace App\Search;

use App\Ai\Intent;
use App\Suggest\Keyword;

final class FieldPage
{
    public function __construct(
        public readonly string $title,

        public readonly Keyword $main,
        /** @var list<Keyword> */
        public readonly array $keywords,
        /** @var list<FieldSection> */
        public readonly array $sections,

        public readonly ?Intent $intent = null,
    ) {
    }

    /** @return non-empty-list<Keyword> */
    public function getAllKeywords(): array
    {
        $sectionKeywords = array_map(static fn (FieldSection $s): array => $s->keywords, $this->sections);

        return [$this->main, ...$this->keywords, ...array_merge(...$sectionKeywords)];
    }

    public function getWeight(): int
    {
        return array_sum(array_map(static fn (Keyword $k): int => $k->score, $this->getAllKeywords()));
    }
}
