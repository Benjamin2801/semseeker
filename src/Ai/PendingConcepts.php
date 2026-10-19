<?php

namespace App\Ai;

final class PendingConcepts
{
    /** @var array{concepts: list<string>, subject: ?string, core: list<string>}|null */
    private ?array $ideas = null;

    /** @param \Closure(): array{concepts: list<string>, subject: ?string, core: list<string>} $resolve */
    public function __construct(
        private readonly \Closure $resolve,
    ) {
    }

    /** @return list<string> */
    public function get(): array
    {
        return $this->ideas()['concepts'];
    }

    public function subject(): ?string
    {
        try {
            return $this->ideas()['subject'];
        } catch (AiException) {
            return null;
        }
    }

    /** @return list<string> */
    public function coreTerms(): array
    {
        try {
            return $this->ideas()['core'];
        } catch (AiException) {
            return [];
        }
    }

    /** @return array{concepts: list<string>, subject: ?string, core: list<string>} */
    private function ideas(): array
    {
        return $this->ideas ??= ($this->resolve)();
    }
}
