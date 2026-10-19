<?php

namespace App\Search;

use App\Ai\Intent;
use App\Ai\KeywordCategory;
use App\Wikipedia\WikipediaArticle;

final class Advice
{
    private const NOTORIETY_FLOOR = 100;
    private const NOTORIETY_CEILING = 50000;
    private const FILL_WEIGHT = 0.6;
    private const SEED_SUGGESTIONS = 60;

    private const DEPTH = [
        'facets' => [20, 0.4],
        'questions' => [10, 0.2],
        'encyclopedic' => [15, 0.15],
        'concepts' => [8, 0.15],
        'searched' => [80, 0.1],
    ];

    private const CUE_FULL_SHARE = 0.25;
    private const CUE_PRESENT_SHARE = 0.02;
    private const ALTERNATIVE_GAP = 15;
    private const ALTERNATIVE_SECTION_DEMAND = 55;
    private const LOCAL_FULL_SHARE = 0.3;
    private const JOB_CUE_FULL = 50;
    private const LOCAL_PARTIAL_MAX = 60;
    private const LOCAL_DOMINANT_SHARE = 0.5;

    private const VARIANT_WORDS = 3;
    private const VARIANT_MAX_DEMAND = 70;
    private const VARIANT_ACTION_SCORE = 90;
    private const MENTION_DEMAND = 15;
    private const SECTION_DEMAND = 40;
    private const SECTION_DEPTH = 40;
    private const QUESTION_DEMAND = 50;

    private const MIN_TYPE_SCORE = 20;
    private const LEAD_MARGIN = 5;
    private const PILLAR_FALLBACK_WEIGHT = 50;
    private const PRODUCT_MARKET = 50;
    private const CATEGORY_ORIENTATION = 40;
    private const PILLAR_OVER_CATEGORY = 70;
    private const PILLAR_COMMERCIAL_SCORE = 50;
    private const COMMERCIAL_ORIENTATION = 45;
    private const COMMERCIAL_CUE_SCORE = 80;
    private const COMMERCIAL_MIN_ORIENTATION = 30;
    private const COMMERCIAL_FULL_SHARE = 0.3;

    private const CUE_OF = [
        'definition' => 'definition', 'howto' => 'howto', 'regulation' => 'regulation', 'news' => 'news',
        'comparison' => 'comparison', 'price' => 'price', 'review' => 'review', 'service' => 'service',
        'job' => 'job',
    ];

    private function __construct(
        public readonly PageType $verdict,
        public readonly int $demand,

        public readonly ?int $notoriety,
        public readonly int $depth,

        public readonly ?int $orientation,

        public readonly bool $variant,

        public readonly ?string $parent,

        public readonly ?PageType $alternative = null,
    ) {
    }

    /** @return list<PageType> */
    public function getOptions(): array
    {
        if (null === $this->alternative) {
            return [$this->verdict];
        }

        return $this->verdict->isSection() && !$this->alternative->isSection() ? [$this->alternative, $this->verdict] : [$this->verdict, $this->alternative];
    }

    // Une page à faible demande ne porte pas un sujet : elle se rattache à sa page parente.
    public function isKeyPage(): bool
    {
        return $this->verdict->isMother() && $this->demand >= self::SECTION_DEMAND;
    }

    public static function of(SearchResult $result): self
    {
        $signals = $result->signals ?? new PageSignals(0, [], 0, []);
        $fill = $result->demand->fill ?? 0;

        $demand = (int) round(100 * ($signals->seedSuggestions > 0 ? self::FILL_WEIGHT * $fill + (1 - self::FILL_WEIGHT) * min(1, $signals->seedSuggestions / self::SEED_SUGGESTIONS) : $fill));

        $articles = $result->wikipedia?->getCountedArticles() ?? [];
        $notoriety = [] !== $articles ? max(array_map(
            static fn (WikipediaArticle $a): int => (int) round($a->coverage * self::scale(log10(max(1, $a->views)), log10(self::NOTORIETY_FLOOR), log10(self::NOTORIETY_CEILING))),
            $articles,
        )) : null;
        $weight = null !== $notoriety ? (int) round(($demand + $notoriety) / 2) : $demand;
        $depth = self::depth($result);
        $orientation = self::orientation($result);

        $scores = self::scores($signals, $demand, $notoriety ?? 0, $depth, $orientation ?? 0, self::intentCount($result));

        uksort($scores, static fn (string $a, string $b): int => [$scores[$b], $signals->share(self::CUE_OF[$b] ?? $b)] <=> [$scores[$a], $signals->share(self::CUE_OF[$a] ?? $a)]);
        // Le lieu est une dimension : sauf s'il est dans l'expression ou s'il domine les recherches (serrurier), les pages
        // par ville déclinent la page principale (« cybersécurité entreprise » puis « … lyon ») au lieu de la remplacer.
        $localFirst = $signals->seedLocal || $signals->localShare() >= self::LOCAL_DOMINANT_SHARE;
        $candidates = $localFirst ? $scores : array_diff_key($scores, ['local' => true]);
        $best = (string) array_key_first($candidates);
        $bestScore = (int) reset($candidates);

        // Une avance de quelques points ne tient pas d'une recherche à l'autre : le guide complet garde la main.
        if ('pillar' !== $best && $bestScore - ($scores['pillar'] ?? 0) < self::LEAD_MARGIN) {
            [$best, $bestScore] = ['pillar', $scores['pillar']];
        }

        $ownIntent = [] !== array_intersect(self::CUE_OF, $signals->seedCues) || $signals->seedLocal;

        $action = max($scores['service'] ?? 0, $scores['local'] ?? 0);
        $variant = $signals->words >= self::VARIANT_WORDS && !$ownIntent && $demand < self::VARIANT_MAX_DEMAND
            && $action < self::VARIANT_ACTION_SCORE;

        if ($signals->question && $demand < self::QUESTION_DEMAND) {
            $verdict = PageType::FaqQuestion;
        } elseif ($variant) {
            $verdict = $demand >= self::SECTION_DEMAND ? PageType::MajorSection : PageType::Mention;
        } elseif (!$ownIntent && $action < self::VARIANT_ACTION_SCORE && $demand < self::MENTION_DEMAND) {
            $verdict = PageType::Mention;
        } elseif (!$ownIntent && $action < self::VARIANT_ACTION_SCORE && $demand < self::SECTION_DEMAND && $depth < self::SECTION_DEPTH) {
            $verdict = PageType::MajorSection;
        } elseif ($bestScore < self::MIN_TYPE_SCORE) {
            $verdict = $weight >= self::PILLAR_FALLBACK_WEIGHT ? self::commercial('pillar', $scores, $demand, $orientation ?? 0) : PageType::MajorSection;
        } else {
            $verdict = self::commercial($best, $scores, $demand, $orientation ?? 0);
        }

        return new self($verdict, $demand, $notoriety, $depth, $orientation, $variant, '' !== $signals->parent ? $signals->parent : null,
            self::alternative($verdict, $scores, $demand, $orientation ?? 0, '' !== $signals->parent));
    }

    /** @param array<string, int> $scores */
    private static function alternative(PageType $verdict, array $scores, int $demand, int $orientation, bool $hasParent): ?PageType
    {
        $top = (int) reset($scores);
        foreach ($scores as $type => $score) {
            if ($score < self::MIN_TYPE_SCORE) {
                break;
            }
            $page = self::commercial((string) $type, $scores, $demand, $orientation);
            if (!$page->isSection() && $page !== $verdict) {
                if ($verdict->isSection() || $score >= $top - self::ALTERNATIVE_GAP) {
                    return $page;
                }
                break;
            }
        }

        return !$verdict->isSection() && $hasParent && $demand < self::ALTERNATIVE_SECTION_DEMAND ? PageType::MajorSection : null;
    }

    /** @param array<string, int> $scores */
    private static function commercial(string $best, array $scores, int $demand, int $orientation): PageType
    {
        $buying = $orientation >= self::COMMERCIAL_ORIENTATION
            || (max($scores['price'] ?? 0, $scores['review'] ?? 0, $scores['comparison'] ?? 0) >= self::COMMERCIAL_CUE_SCORE && $orientation >= self::COMMERCIAL_MIN_ORIENTATION);

        return match (true) {
            \in_array($best, ['price', 'review'], true) => match (true) {
                ($scores['pillar'] ?? 0) >= self::PILLAR_COMMERCIAL_SCORE => PageType::PillarCommercial,
                $demand >= self::SECTION_DEMAND => PageType::CommercialSection,
                default => PageType::MinorCommercialSection,
            },
            'pillar' === $best => $buying ? PageType::PillarCommercial : PageType::Pillar,

            'category' === $best && ($scores['pillar'] ?? 0) >= self::PILLAR_OVER_CATEGORY => PageType::PillarCommercial,
            'regulation' === $best => PageType::RegulationSection,
            default => PageType::from($best),
        };
    }

    /** @return array<string, int> */
    private static function scores(PageSignals $signals, int $demand, int $notoriety, int $depth, int $orientation, int $intents): array
    {
        $cue = static fn (string $family): float => min(100, 100 * $signals->share($family) / self::CUE_FULL_SHARE);

        $families = \count(array_filter(array_keys(Lexicon::PAGE_CUES), static fn (string $f): bool => $signals->share($f) >= self::CUE_PRESENT_SHARE));
        $breadth = min(100, 15 * $families + 20 * $intents + 3 * $signals->types);
        $market = 100 * ($signals->fill(['amazon', 'ebay']) ?? 0);
        $video = 100 * ($signals->fill(['youtube']) ?? 0);
        $jobs = 100 * ($signals->fill(['indeed']) ?? 0);

        $scores = [
            'pillar' => min(100, (0.4 * $demand + 0.3 * $notoriety + 0.3 * $depth) * $breadth / 100 + 0.25 * $cue('question')),

            'definition' => 0.6 * $cue('definition') + 0.25 * $notoriety * (100 - $orientation) / 100,

            // YouTube complète presque tout : il renforce un tutoriel sans suffire à le désigner.
            'howto' => $cue('howto') > 0 ? 0.6 * $cue('howto') + 0.4 * $video : 0.15 * $video,
            'regulation' => $cue('regulation'),
            'news' => $cue('news'),
            'comparison' => $cue('comparison'),
            'price' => $cue('price'),
            'review' => $cue('review'),

            // Amazon et eBay remplissent leur liste pour presque tous les noms communs : ça ne compte que si le sujet
            // est tourné vers l'achat.
            'category' => $signals->model ? 0 : $market * min(1, $orientation / self::CATEGORY_ORIENTATION),
            'product' => $signals->model ? $market : 0,
            'service' => $cue('service'),

            // Sous 30 % de requêtes locales, le lieu se traite dans la page ; au-delà, il faut une page par ville.
            'local' => $signals->seedLocal || $signals->localShare() >= self::LOCAL_FULL_SHARE ? 100 : self::LOCAL_PARTIAL_MAX * $signals->localShare() / self::LOCAL_FULL_SHARE,

            // Indeed suggère des offres pour presque tout (« pizzaiolo » pour « pizza ») : il faut que les suggestions
            // parlent elles-mêmes d'emploi.
            'job' => (0.6 * $jobs + 0.4 * $cue('job')) * min(1, $cue('job') / self::JOB_CUE_FULL),
        ];

        $seedTypes = array_keys(array_intersect(self::CUE_OF, $signals->seedCues));
        if ($signals->model && $market >= self::PRODUCT_MARKET) {
            $seedTypes = ['product'];
        }
        if ($signals->seedLocal) {
            $seedTypes[] = 'local';
        }
        if ([] !== $seedTypes) {
            $scores = array_map(static fn (float $score): float => min(99, $score), $scores);
            foreach ($seedTypes as $type) {
                $scores[$type] = 100;
            }
        }

        return array_map(static fn (float $score): int => (int) round($score), $scores);
    }

    private static function intentCount(SearchResult $result): int
    {
        $intents = [];
        foreach ($result->field->groups ?? [] as $group) {
            foreach ($group->pages as $page) {
                if (null !== $page->intent) {
                    $intents[$page->intent->value] = true;
                }
            }
        }

        return \count($intents);
    }

    private static function depth(SearchResult $result): int
    {
        $signals = $result->signals;
        $counts = [
            'facets' => $signals->facets ?? 0,
            'searched' => $signals->searched ?? 0,
            'encyclopedic' => ($result->wikipedia->sections ?? 0) + \count($result->wikipedia->types ?? []),
            'questions' => $signals->questions ?? 0,
            'concepts' => $signals->confirmedConcepts ?? 0,
        ];

        $depth = 0.0;
        foreach (self::DEPTH as $name => [$cap, $weight]) {
            $depth += $weight * self::scale($counts[$name], 0, $cap);
        }

        return (int) round($depth);
    }

    private static function orientation(SearchResult $result): ?int
    {
        $shares = [];
        $categories = $result->getCategories();
        $categorized = array_sum(array_map('count', $categories)) - \count($categories[KeywordCategory::Doubtful->value] ?? []);
        if ($categorized > 0) {
            $shares[] = \count($categories[KeywordCategory::Commercial->value] ?? []) / $categorized;
        }
        $pages = [];
        foreach ($result->field->groups ?? [] as $group) {
            $pages = [...$pages, ...$group->pages];
        }
        if ([] !== $pages) {
            $shares[] = \count(array_filter($pages, static fn (FieldPage $p): bool => \in_array($p->intent, [Intent::Commercial, Intent::Transactional], true))) / \count($pages);
        }
        // Mesurée sur les suggestions, sans l'IA : elle tempère les deux jugements du modèle.
        $commercialShare = $result->signals?->commercialShare;
        if (null !== $commercialShare) {
            $shares[] = min(1, $commercialShare / self::COMMERCIAL_FULL_SHARE);
        }

        return [] !== $shares ? (int) round(100 * array_sum($shares) / \count($shares)) : null;
    }

    private static function scale(float $value, float $floor, float $ceiling): int
    {
        return (int) round(100 * min(1, max(0, ($value - $floor) / ($ceiling - $floor))));
    }
}
