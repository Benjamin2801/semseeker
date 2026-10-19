<?php

namespace App\Suggest;

use App\Ai\KeywordCategory;

final class Keyword
{
    public const ORIGIN_WIKIPEDIA = 'wikipedia';
    public const ORIGIN_WIKIDATA = 'wikidata';
    public const ORIGIN_AI = 'ai';

    public const TIER_THRESHOLDS = [10, 20, 35, 50];

    public function __construct(
        public readonly string $text,
        /** @var array<string, int> */
        public readonly array $positions,

        public readonly int $score,

        public readonly ?string $origin = null,

        public readonly ?KeywordCategory $category = null,

        public readonly ?int $estimate = null,
    ) {
    }

    public function withCategory(?KeywordCategory $category): self
    {
        return new self($this->text, $this->positions, $this->score, $this->origin, $category, $this->estimate);
    }

    public function withEstimate(int $estimate): self
    {
        return new self($this->text, $this->positions, $this->score, $this->origin, $this->category, $estimate);
    }

    public function getDisplayScore(): int
    {
        return $this->isMeasured() ? $this->score : ($this->estimate ?? 0);
    }

    public function isMeasured(): bool
    {
        return [] !== $this->positions;
    }

    public function getSourceCount(): int
    {
        return \count($this->positions);
    }

    // Faible : mesuré dans les deux premiers paliers de l'échelle (moins de 20), ceux que l'on peut masquer.
    public function isLow(): bool
    {
        return $this->isMeasured() && $this->getScoreTier() <= 2;
    }

    /** @return int<1, 5> */
    public function getScoreTier(): int
    {
        $tier = 1;
        foreach (self::TIER_THRESHOLDS as $threshold) {
            if ($this->getDisplayScore() >= $threshold) {
                ++$tier;
            }
        }

        return $tier;
    }
}
