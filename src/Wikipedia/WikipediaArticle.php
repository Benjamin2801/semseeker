<?php

namespace App\Wikipedia;

use App\Search\Demand;

final class WikipediaArticle
{
    private const VOLUME_THRESHOLDS = [300, 1500, 6000, 25000];

    public function __construct(
        public readonly string $title,
        public readonly string $lang,

        public readonly int $views,
        /** @var list<int> */
        public readonly array $dailyViews = [],

        public readonly bool $counted = true,

        public readonly bool $fromConcept = false,

        public readonly float $coverage = 1.0,
    ) {
    }

    public function uncounted(): self
    {
        return new self($this->title, $this->lang, $this->views, $this->dailyViews, false, $this->fromConcept, $this->coverage);
    }

    public function getSparkline(int $width = 64, int $height = 16): string
    {
        $days = \count($this->dailyViews);
        $max = max([1, ...$this->dailyViews]);
        if ($days < 2) {
            return '';
        }

        $points = [];
        foreach ($this->dailyViews as $i => $views) {
            $points[] = sprintf('%.1F,%.1F', $i * $width / ($days - 1), $height - $views * $height / $max);
        }

        return implode(' ', $points);
    }

    public function getVolume(): Demand
    {
        $levels = Demand::cases();
        $tier = \count(array_filter(self::VOLUME_THRESHOLDS, fn (int $threshold): bool => $this->views >= $threshold));

        return $levels[$tier];
    }

    public function getUrl(): string
    {
        return sprintf('https://%s.wikipedia.org/wiki/%s', $this->lang, rawurlencode(str_replace(' ', '_', $this->title)));
    }
}
