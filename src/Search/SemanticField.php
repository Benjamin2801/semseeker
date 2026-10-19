<?php

namespace App\Search;

use App\Ai\ContentPlannerInterface;
use App\Ai\Intent;
use App\Suggest\Keyword;
use App\Suggest\SuggestionMerger;

/** @phpstan-import-type PlanGroup from ContentPlannerInterface */
final class SemanticField
{
    private function __construct(
        /** @var list<FieldGroup> */
        public readonly array $groups,
        /** @var list<Keyword> */
        public readonly array $leftovers,
    ) {
    }

    /**
     * Part de chaque intention dans le champ, pages pesées par leurs mots-clés.
     *
     * @return array<string, int>
     */
    public function getIntentMix(): array
    {
        $weights = array_fill_keys(array_map(static fn (Intent $i): string => $i->value, Intent::cases()), 0);
        foreach ($this->groups as $group) {
            foreach ($group->pages as $page) {
                if (null !== $page->intent) {
                    $weights[$page->intent->value] += $page->getWeight();
                }
            }
        }
        $total = array_sum($weights);

        return 0 === $total ? [] : array_map(static fn (int $w): int => (int) round(100 * $w / $total), $weights);
    }

    public function getPageCount(): int
    {
        return array_sum(array_map(static fn (FieldGroup $g): int => \count($g->pages), $this->groups));
    }

    /** @return list<Intent> */
    public function getIntents(): array
    {
        $present = [];
        foreach ($this->groups as $group) {
            foreach ($group->pages as $page) {
                if (null !== $page->intent) {
                    $present[$page->intent->value] = true;
                }
            }
        }

        return array_values(array_filter(Intent::cases(), static fn (Intent $i): bool => isset($present[$i->value])));
    }

    /**
     * @param list<PlanGroup>     $plan
     * @param array<int, Keyword> $keywords
     */
    public static function build(array $plan, array $keywords, int $maxGroups = \PHP_INT_MAX, int $maxKeywordsPerGroup = \PHP_INT_MAX, ?LexicalContext $context = null): self
    {
        $plan = self::withTitlesAtHome($plan, $keywords);
        $placed = [];
        $take = static function (array $positions) use (&$placed, $keywords): array {
            $taken = [];
            foreach ($positions as $position) {
                if (isset($keywords[$position]) && !isset($placed[$position])) {
                    $placed[$position] = true;
                    $taken[] = $position;
                }
            }

            return $taken;
        };

        $drafts = [];
        foreach ($plan as $group) {
            $pages = [];
            foreach ($group['pages'] as $page) {
                $sections = [];
                $pageKeywords = $take($page['keywords']);
                foreach ($page['sections'] as $section) {
                    $sections[] = [$section['title'], $take($section['keywords'])];
                }
                $pages[] = [$page['title'], $pageKeywords, $sections, $page['intent']];
            }
            $positions = [];
            foreach ($pages as [, $pageKeywords, $sections]) {
                $positions = [...$positions, ...$pageKeywords, ...array_merge([], ...array_column($sections, 1))];
            }
            if ('' !== $group['name'] && [] !== $positions) {
                $weight = array_sum(array_map(static fn (int $p): int => $keywords[$p]->score, $positions));
                $drafts[] = ['name' => $group['name'], 'pages' => $pages, 'positions' => $positions, 'weight' => $weight];
            }
        }

        usort($drafts, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $groups = [];
        $placedInGroups = [];
        $pick = static fn (array $positions): array => array_map(static fn (int $p): Keyword => $keywords[$p], $positions);
        foreach (\array_slice($drafts, 0, $maxGroups) as $draft) {
            $detailed = [];
            foreach ($draft['pages'] as [, , $sections]) {
                $detailed = [...$detailed, ...array_merge([], ...array_column($sections, 1))];
            }
            usort($detailed, static fn (int $a, int $b): int => $keywords[$b]->score <=> $keywords[$a]->score);
            $detailed = array_flip(\array_slice($detailed, 0, $maxKeywordsPerGroup));

            $pages = [];
            foreach ($draft['pages'] as [$title, $pagePositions, $sections, $intent]) {
                $fieldSections = [];
                foreach ($sections as [$sectionTitle, $positions]) {
                    $inSection = array_filter($positions, static fn (int $p): bool => isset($detailed[$p]));
                    $pagePositions = [...$pagePositions, ...array_diff($positions, $inSection)];
                    if ([] !== $inSection) {
                        $fieldSections[] = [$sectionTitle, $pick(array_values($inSection))];
                    }
                }
                if (null !== $page = self::page($title, $intent, $pick($pagePositions), $fieldSections, $context)) {
                    $pages[] = $page;
                }
            }
            if ([] === $pages) {
                continue;
            }

            usort($pages, static fn (FieldPage $a, FieldPage $b): int => $b->getWeight() <=> $a->getWeight());
            $groups[] = new FieldGroup($draft['name'], $pages);
            $placedInGroups += array_fill_keys($draft['positions'], true);
        }

        return new self($groups, self::sorted(array_diff_key($keywords, $placedInGroups)));
    }

    /**
     * Un mot-clé qui porte le titre d'une page lui revient, même si le modèle l'a rangé ailleurs : sinon deux pages
     * se disputent la même requête (« pompe à chaleur air-eau » en page et en section d'une autre page).
     *
     * @param list<PlanGroup>     $plan
     * @param array<int, Keyword> $keywords
     *
     * @return list<PlanGroup>
     */
    private static function withTitlesAtHome(array $plan, array $keywords): array
    {
        $home = [];
        foreach ($plan as $g => $group) {
            foreach ($group['pages'] as $p => $page) {
                $home[SuggestionMerger::key($page['title'])] ??= [$g, $p];
            }
        }
        $owner = [];
        foreach ($keywords as $position => $keyword) {
            if (isset($home[$key = SuggestionMerger::key($keyword->text)])) {
                $owner[$position] = $home[$key];
            }
        }
        if ([] === $owner) {
            return $plan;
        }

        foreach ($plan as $g => $group) {
            foreach ($group['pages'] as $p => $page) {
                $elsewhere = static fn (int $position): bool => isset($owner[$position]) && $owner[$position] !== [$g, $p];
                $page['keywords'] = array_values(array_filter($page['keywords'], static fn (int $position): bool => !$elsewhere($position)));
                foreach ($page['sections'] as $s => $section) {
                    $page['sections'][$s]['keywords'] = array_values(array_filter($section['keywords'], static fn (int $position): bool => !$elsewhere($position)));
                }
                foreach (array_keys($owner, [$g, $p], true) as $position) {
                    if (!\in_array($position, $page['keywords'], true)) {
                        array_unshift($page['keywords'], $position);
                    }
                }
                $plan[$g]['pages'][$p] = $page;
            }
        }

        return $plan;
    }

    /**
     * @param array<int, Keyword>                      $keywords
     * @param list<array{string, array<int, Keyword>}> $sections
     */
    private static function page(string $title, ?Intent $intent, array $keywords, array $sections, ?LexicalContext $context = null): ?FieldPage
    {
        $candidates = [] !== $keywords ? $keywords : array_merge(...array_column($sections, 1));
        if ([] === $candidates) {
            return null;
        }
        $all = [...$candidates, ...array_merge([], ...array_column($sections, 1))];
        $titled = array_filter($all, static fn (Keyword $k): bool => SuggestionMerger::key($k->text) === SuggestionMerger::key($title));
        $main = [] !== $titled ? reset($titled) : self::sorted($candidates)[0];

        $fieldSections = [];
        foreach ($sections as [$sectionTitle, $sectionKeywords]) {
            $sectionKeywords = self::sorted(array_filter($sectionKeywords, static fn (Keyword $k): bool => $k !== $main));
            if ([] !== $sectionKeywords) {
                $fieldSections[] = new FieldSection('' !== $sectionTitle ? $sectionTitle : $sectionKeywords[0]->text, $sectionKeywords);
            }
        }
        usort($fieldSections, static fn (FieldSection $a, FieldSection $b): int => $b->getWeight() <=> $a->getWeight());

        $others = array_filter($keywords, static fn (Keyword $k): bool => $k !== $main);
        $page = new FieldPage('' !== $title ? $title : $main->text, $main, self::sorted($others), $fieldSections, $intent);

        $cue = Intent::fromKeywords(array_map(static fn (Keyword $k): string => $k->text, $page->getAllKeywords()), $context);
        if (null !== $cue && Intent::Commercial !== $intent && Intent::Transactional !== $intent) {
            $page = new FieldPage($page->title, $main, $page->keywords, $fieldSections, $cue);
        }

        return $page;
    }

    /**
     * @param array<Keyword> $keywords
     *
     * @return list<Keyword>
     */
    private static function sorted(array $keywords): array
    {
        $keywords = array_values($keywords);
        usort($keywords, static fn (Keyword $a, Keyword $b): int => [$b->score, $a->text] <=> [$a->score, $b->text]);

        return $keywords;
    }
}
