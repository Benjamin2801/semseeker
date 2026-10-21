<?php

namespace App\Search;

use App\Suggest\Keyword;
use App\Suggest\SourceFailure;
use App\Wikipedia\WikipediaExploration;

/**
 * Tout ce qu'une recherche a réuni avant le plan : suggestions mesurées, Wikipédia, concepts, candidats. Gardée en cache
 * entre la page de résultats et la requête du plan, pour que celui-ci dispose de son propre délai d'exécution.
 */
final class SearchPreparation
{
    public function __construct(
        public readonly string $keyword,
        public readonly string $lang,
        /** @var list<Keyword> */
        public readonly array $candidates,
        public readonly int $mergedCount,
        /** @var list<SourceFailure> */
        public readonly array $failedSources,
        public readonly int $sourceCount,
        public readonly ?DemandEstimate $demand,
        public readonly ?WikipediaExploration $wikipedia,
        public readonly PageSignals $signals,
        /** @var list<string> */
        public readonly array $concepts,
        /** @var list<string> */
        public readonly array $types,
        public readonly float $startedAt,
    ) {
    }

    // En attendant le plan : les mots-clés mesurés, classés par intérêt, et le conseil, qui n'attend pas l'IA.
    public function provisional(): SearchResult
    {
        return new SearchResult(
            keywords: array_values(array_filter($this->candidates, static fn (Keyword $k): bool => $k->isMeasured())),
            total: $this->mergedCount,
            failedSources: $this->failedSources,
            sourceCount: $this->sourceCount,
            demand: $this->demand,
            field: null,
            fieldIssue: SearchResult::FIELD_PENDING,
            wikipedia: $this->wikipedia,
            signals: $this->signals,
            concepts: $this->concepts,
        );
    }
}
