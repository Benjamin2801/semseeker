<?php

namespace App\Ai;

/** @phpstan-import-type PlanGroup from ContentPlannerInterface */
final class Plan
{
    public function __construct(
        /** @var list<PlanGroup> */
        public readonly array $groups,
        /** @var list<int> */
        public readonly array $rejected = [],
        /** @var array<int, KeywordCategory> */
        public readonly array $categories = [],
        /** @var list<string> */
        public readonly array $onTopicArticles = [],
    ) {
    }
}
