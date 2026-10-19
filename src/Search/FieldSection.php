<?php

namespace App\Search;

use App\Suggest\Keyword;
use App\Suggest\SuggestionMerger;

final class FieldSection
{
    public function __construct(
        public readonly string $title,
        /** @var non-empty-list<Keyword> */
        public readonly array $keywords,
    ) {
    }

    public function getTitleKeyword(): ?Keyword
    {
        $title = SuggestionMerger::key($this->title);
        foreach ($this->keywords as $keyword) {
            if (SuggestionMerger::key($keyword->text) === $title) {
                return $keyword;
            }
        }

        return null;
    }

    /** @return list<Keyword> */
    public function getOtherKeywords(): array
    {
        $titleKeyword = $this->getTitleKeyword();

        return array_values(array_filter($this->keywords, static fn (Keyword $k): bool => $k !== $titleKeyword));
    }

    public function getWeight(): int
    {
        return array_sum(array_map(static fn (Keyword $k): int => $k->score, $this->keywords));
    }
}
