<?php

namespace App\Ai;

interface ConceptFinderInterface
{
    public function concepts(string $seed, string $lang): PendingConcepts;
}
