<?php

namespace App\Suggest;

final class CollectResult
{
    public function __construct(
        /** @var list<RawSuggestion> */
        public readonly array $suggestions,
        /** @var list<SourceFailure> */
        public readonly array $failedSources,
        /** @var list<string> */
        public readonly array $sources = [],
    ) {
    }

    /** @return list<string> */
    public function getRespondingSources(): array
    {
        $failed = array_map(static fn (SourceFailure $f): string => $f->source, $this->failedSources);

        return array_values(array_diff($this->sources, $failed));
    }

    // En voix : Bing et DuckDuckGo, qui se répètent, comptent pour une.
    public function getAnsweringSourceCount(): int
    {
        return \count(array_unique(array_map(static fn (RawSuggestion $s): string => SuggestionMerger::voice($s->source), $this->suggestions)));
    }
}
