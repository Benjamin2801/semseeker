<?php

namespace App\Search;

use App\Ai\Intent;
use App\Suggest\Keyword;

final class FieldGroup
{
    public function __construct(
        public readonly string $name,
        /** @var non-empty-list<FieldPage> */
        public readonly array $pages,
    ) {
    }

    /** @return list<Keyword> */
    public function getAllKeywords(): array
    {
        return array_merge(...array_map(static fn (FieldPage $p): array => $p->getAllKeywords(), $this->pages));
    }

    public function getKeywordCount(): int
    {
        return \count($this->getAllKeywords());
    }

    /** @return array<value-of<Intent>, int> */
    public function getIntentShares(): array
    {
        $weights = [];
        foreach ($this->pages as $page) {
            if (null !== $page->intent) {
                $weights[$page->intent->value] = ($weights[$page->intent->value] ?? 0) + $page->getWeight();
            }
        }
        $total = array_sum($weights);
        if (0 === $total) {
            return [];
        }
        arsort($weights);

        return array_map(static fn (int $w): int => (int) round(100 * $w / $total), $weights);
    }
}
