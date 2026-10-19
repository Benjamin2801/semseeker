<?php

namespace App\Search;

use App\Ai\AiException;
use App\Ai\ConceptFinderInterface;
use App\Ai\ContentPlannerInterface;
use App\Ai\Intent;
use App\Ai\KeywordCategory;
use App\Suggest\CollectResult;
use App\Suggest\Keyword;
use App\Suggest\SuggestionCollector;
use App\Suggest\SuggestionMerger;
use App\Wikipedia\WikipediaExploration;
use App\Wikipedia\WikipediaExplorer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class KeywordSearch
{
    private const FIELD_MAX_GROUPS = 8;
    private const FIELD_MAX_KEYWORDS_PER_GROUP = 10;

    // Le plan (structure puis rangement) doit finir avant ce délai pour tenir dans les 30 s d'exécution PHP.
    private const PLAN_DEADLINE_SECONDS = 29;
    private const CORE_MIN_SCORE = 20;
    private const CORE_CONCEPT_MIN_SCORE = 35;

    // 5 s de structure et 10 s de rangement : en dessous, le plan échouerait de toute façon.
    private const PLAN_MIN_SECONDS = 15;

    private const VOCABULARY_SHARES = ['intro' => 7, 'related' => 6, 'types' => 3];

    public function __construct(
        private readonly SuggestionCollector $collector,
        private readonly SuggestionMerger $merger,
        private readonly KeywordFilter $filter,
        private readonly WikipediaExplorer $wikipedia,
        private readonly ConceptFinderInterface $conceptFinder,
        private readonly ContentPlannerInterface $planner,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.ai.max_candidates')]
        private readonly int $maxCandidates,
    ) {
    }

    public function search(string $keyword, string $lang): SearchResult
    {
        $startedAt = microtime(true);

        $started = $this->collector->start($keyword, $lang);
        $pendingConcepts = $this->conceptFinder->concepts($keyword, $lang);

        $wikipedia = $this->wikipedia->explore($keyword, $lang, $pendingConcepts->subject(...));
        try {
            $concepts = $pendingConcepts->get();
        } catch (AiException $e) {
            $this->logger->warning('Concepts IA indisponibles', ['error' => $e->getMessage()]);
            $concepts = [];
        }

        $collected = $this->collector->collect($keyword, $lang, $concepts, $started);
        [$merged, $signals] = self::measure($collected, $keyword, $lang, $wikipedia, $concepts, $this->merger, $this->filter);
        $types = \array_slice($wikipedia->types ?? [], 0, self::VOCABULARY_SHARES['types']);
        $vocabulary = [
            ...\array_slice($wikipedia->introTerms ?? [], 0, self::VOCABULARY_SHARES['intro']),
            ...\array_slice($wikipedia->relatedTerms ?? [], 0, self::VOCABULARY_SHARES['related']),
            ...$types,
        ];

        $core = $pendingConcepts->coreTerms();
        $candidates = $this->withVocabulary(\array_slice($merged, 0, $this->maxCandidates), $merged, $vocabulary, $types, $core, $keyword, $lang);

        $articles = array_map(static fn ($a): string => $a->title, $wikipedia->articles ?? []);

        $unmeasured = array_values(array_map(static fn (Keyword $k): string => $k->text, array_filter($candidates, static fn (Keyword $k): bool => !$k->isMeasured())));
        $views = [];

        $meanwhile = function () use (&$wikipedia, &$views, $concepts, $unmeasured, $lang): void {
            $wikipedia = $this->wikipedia->withConcepts($wikipedia, $concepts, $lang);
            $views = $this->wikipedia->viewsOf($unmeasured, $lang);
        };

        $estimate = static function (array $keywords) use (&$views): array {
            return array_values(array_map(static fn (Keyword $k): Keyword => $k->isMeasured() ? $k : $k->withEstimate(Estimate::of($k, $views[$k->text] ?? 0)), $keywords));
        };
        [$field, $rejected, $candidates, $onTopic] = $this->field($keyword, $lang, $candidates, $concepts, $types, $articles, $startedAt, $meanwhile, $estimate);
        $wikipedia = $wikipedia?->judged($onTopic);
        $candidates = $estimate($candidates);
        $rejectedSet = array_flip($rejected);
        $excluded = array_values(array_filter(array_intersect_key($candidates, $rejectedSet), static fn (Keyword $k): bool => $k->isMeasured()));
        $kept = array_values(array_diff_key($candidates, $rejectedSet));
        if (!$field instanceof SemanticField) {
            $kept = array_values(array_filter($kept, static fn (Keyword $k): bool => $k->isMeasured()));
        }

        $found = \count($merged) - \count($excluded) + \count(array_filter($kept, static fn (Keyword $k): bool => !$k->isMeasured()));

        return new SearchResult(
            keywords: $kept,
            total: $found,
            failedSources: $collected->failedSources,
            sourceCount: \count($collected->sources),
            demand: Demand::estimate($collected->suggestions, $collected->getRespondingSources()),
            field: $field instanceof SemanticField ? $field : null,
            fieldIssue: \is_string($field) ? $field : null,
            wikipedia: $wikipedia,
            excluded: $excluded,
            signals: $signals,
            concepts: $concepts,
        );
    }

    /**
     * Fusion, tri et signaux du conseil, sans réseau ni IA : le jeu d'essai de calibrage les rejoue tels quels.
     *
     * @param list<string> $concepts
     *
     * @return array{list<Keyword>, PageSignals}
     */
    public static function measure(CollectResult $collected, string $keyword, string $lang, ?WikipediaExploration $wikipedia, array $concepts, SuggestionMerger $merger, KeywordFilter $filter): array
    {
        $all = $merger->merge($collected->suggestions, $keyword, $collected->getAnsweringSourceCount());
        $merged = array_values(array_filter($all, static fn (Keyword $k): bool => !$filter->excludes($k->text, $keyword, $lang)));

        // Le conseil ne lit que les suggestions de l'expression : les concepts varient d'une recherche à l'autre.
        $fromSeed = array_values(array_filter($collected->suggestions, static fn ($s): bool => $s->fromSeed));
        $signals = PageSignals::from(
            $keyword, $lang, $merger->merge($fromSeed, $keyword, $collected->getAnsweringSourceCount()), $collected->suggestions, $collected->getRespondingSources(), $filter, \count($wikipedia->types ?? []),

            array_values(array_map(static fn ($a): string => $a->title, array_filter($wikipedia->articles ?? [], static fn ($a): bool => $a->counted))),
        );

        return [$merged, $signals->withReach($merged, $concepts)];
    }

    /** @param list<string> $concepts */
    private static function balance(Keyword $keyword, bool $rejected, string $seed, array $concepts): ?KeywordCategory
    {
        $category = $keyword->category;
        if ($rejected || KeywordCategory::Commercial === $category) {
            return $category;
        }
        $seedWords = array_values(array_diff(explode(' ', SuggestionMerger::normalize($seed)), Lexicon::LINK_WORDS));
        $words = explode(' ', SuggestionMerger::normalize($keyword->text));
        $shared = array_intersect($seedWords, $words);
        // « boucherie halal » prolonge « charcuterie halal » ; « charcuterie marocaine » est une autre sorte de charcuterie.
        $extendsSubject = [] !== $shared && (\count($shared) === \count($seedWords) || !\in_array($seedWords[0] ?? '', $words, true));
        if ($extendsSubject && Keyword::ORIGIN_AI === $keyword->origin) {
            return KeywordCategory::Core;
        }
        $concept = \in_array(SuggestionMerger::normalize($keyword->text), array_map(SuggestionMerger::normalize(...), $concepts), true);
        if ($extendsSubject && $concept && $keyword->isMeasured() && $keyword->score >= self::CORE_CONCEPT_MIN_SCORE) {
            return KeywordCategory::Core;
        }
        if (KeywordCategory::Core === $category && Keyword::ORIGIN_AI !== $keyword->origin && $keyword->getDisplayScore() < self::CORE_MIN_SCORE) {
            return KeywordCategory::Complementary;
        }

        return $category;
    }

    /**
     * @param list<Keyword> $candidates
     * @param list<Keyword> $merged
     * @param list<string>  $vocabulary
     * @param list<string>  $types
     * @param list<string>  $core
     *
     * @return list<Keyword>
     */
    private function withVocabulary(array $candidates, array $merged, array $vocabulary, array $types, array $core, string $keyword, string $lang): array
    {
        $known = [];
        foreach ($merged as $suggestion) {
            $known[SuggestionMerger::key($suggestion->text)] = $suggestion;
        }
        $taken = array_flip(array_map(static fn (Keyword $k): string => SuggestionMerger::key($k->text), $candidates));
        $seedWords = [...explode(' ', SuggestionMerger::normalize($keyword)), ...Lexicon::LINK_WORDS];
        // « pain » ou « gratuit » seuls n'apprennent rien sur « pain au levain » ou « logiciel gratuit ».
        $core = array_values(array_filter($core, static fn (string $term): bool => [] !== array_diff(explode(' ', SuggestionMerger::normalize($term)), $seedWords)));

        foreach ([...$core, ...$vocabulary] as $term) {
            $key = SuggestionMerger::key($term);
            if (isset($taken[$key]) || $this->filter->excludes($term, $keyword, $lang)) {
                continue;
            }
            $taken[$key] = true;
            $origin = match (true) {
                \in_array($term, $core, true) => Keyword::ORIGIN_AI,
                \in_array($term, $types, true) => Keyword::ORIGIN_WIKIDATA,
                default => Keyword::ORIGIN_WIKIPEDIA,
            };
            $candidates[] = $known[$key] ?? new Keyword($term, [], 0, $origin);
        }

        return $candidates;
    }

    /**
     * @param list<Keyword>                          $candidates
     * @param list<string>                           $types
     * @param list<string>                           $articles
     * @param \Closure(): void                       $meanwhile
     * @param list<string>                           $concepts
     * @param \Closure(list<Keyword>): list<Keyword> $estimate
     *
     * @return array{SemanticField|string, list<int>, list<Keyword>, list<string>}
     */
    private function field(string $keyword, string $lang, array $candidates, array $concepts, array $types, array $articles, float $startedAt, \Closure $meanwhile, \Closure $estimate): array
    {
        if (\count($candidates) < 2) {
            $meanwhile();

            return [SearchResult::FIELD_TOO_FEW_KEYWORDS, [], $candidates, []];
        }

        $remaining = self::PLAN_DEADLINE_SECONDS - (microtime(true) - $startedAt);
        if ($remaining < self::PLAN_MIN_SECONDS) {
            $this->logger->warning('Plan abandonné : délai dépassé');
            $meanwhile();

            return [SearchResult::FIELD_TIMEOUT, [], $candidates, []];
        }

        $draft = $this->planner->draft(
            $keyword,
            array_map(static fn (Keyword $k): string => $k->text, $candidates),
            array_map(static fn (Keyword $k): int => $k->score, $candidates),
            $lang,
            $remaining,
            $types,
            $articles,
        );
        $meanwhile();

        try {
            $plan = $this->planner->assign($draft);
        } catch (AiException $e) {
            $this->logger->warning('Plan IA indisponible', ['error' => $e->getMessage()]);

            return [SearchResult::FIELD_UNAVAILABLE, [], $candidates, []];
        }

        // Le modèle écarte parfois « livraison pizza » comme une entreprise à joindre : on le garde.
        $rejected = array_filter($plan->rejected, static fn (int $position): bool => !Lexicon::ordersService($candidates[$position]->text ?? ''));
        $rejectedSet = array_flip($rejected);
        foreach ($candidates as $position => $candidate) {
            $category = Lexicon::ordersService($candidate->text) ? KeywordCategory::Commercial : $plan->categories[$position] ?? null;
            $candidates[$position] = $candidate->withCategory(KeywordCategory::decide($category, $candidate->text, isset($rejectedSet[$position]), $keyword));
        }
        $rejected = array_values($rejected);
        // Les vues sont lues pendant la structure : les estimations entrent dans le champ.
        $candidates = $estimate($candidates);
        foreach ($candidates as $position => $candidate) {
            $candidates[$position] = $candidate->withCategory(self::balance($candidate, isset($rejectedSet[$position]), $keyword, $concepts));
        }
        // Un terme rangé dans un concept transactionnel (acheter, commander, faire poser) est une opportunité.
        foreach ($plan->groups as $group) {
            foreach ($group['pages'] as $page) {
                if (Intent::Transactional !== $page['intent']) {
                    continue;
                }
                foreach ([...$page['keywords'], ...array_merge([], ...array_column($page['sections'], 'keywords'))] as $position) {
                    if (\in_array(($candidates[$position] ?? null)?->category, [KeywordCategory::Core, KeywordCategory::Complementary], true)) {
                        $candidates[$position] = $candidates[$position]->withCategory(KeywordCategory::Commercial);
                    }
                }
            }
        }
        // Un terme « à vérifier » reste dans la liste mais ne structure pas le plan.
        $kept = array_filter(array_diff_key($candidates, $rejectedSet), static fn (Keyword $k): bool => KeywordCategory::Doubtful !== $k->category);
        if (\count($kept) < 2) {
            return [SearchResult::FIELD_TOO_FEW_KEYWORDS, $rejected, $candidates, $plan->onTopicArticles];
        }

        $field = SemanticField::build($plan->groups, $kept, self::FIELD_MAX_GROUPS, self::FIELD_MAX_KEYWORDS_PER_GROUP);

        return [[] !== $field->groups ? $field : SearchResult::FIELD_UNAVAILABLE, $rejected, $candidates, $plan->onTopicArticles];
    }
}
