<?php

namespace App\Ai;

/**
 * @phpstan-type PlanSection array{title: string, keywords: list<int>}
 * @phpstan-type PlanPage array{title: string, intent: ?Intent, keywords: list<int>, sections: list<PlanSection>}
 * @phpstan-type PlanGroup array{name: string, pages: list<PlanPage>}
 */
interface ContentPlannerInterface
{
    /**
     * @param list<string> $keywords
     * @param list<int>    $scores
     * @param list<string> $types
     * @param list<string> $articles
     */
    public function draft(string $seed, array $keywords, array $scores, string $lang, float $maxDuration, array $types = [], array $articles = []): PlanDraft;

    public function assign(PlanDraft $draft): Plan;
}
