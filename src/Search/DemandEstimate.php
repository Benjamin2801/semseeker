<?php

namespace App\Search;

final class DemandEstimate
{
    public function __construct(
        public readonly Demand $level,

        public readonly float $fill,

        public readonly int $sourceCount,
    ) {
    }
}
