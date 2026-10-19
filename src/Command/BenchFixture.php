<?php

namespace App\Command;

use App\Ai\Intent;
use App\Ai\KeywordCategory;
use App\Search\Demand;
use App\Search\KeywordFilter;
use App\Search\KeywordSearch;
use App\Search\SearchResult;
use App\Search\SemanticField;
use App\Suggest\CollectResult;
use App\Suggest\Keyword;
use App\Suggest\RawSuggestion;
use App\Suggest\SourceFailure;
use App\Suggest\SuggestionMerger;
use App\Wikipedia\WikipediaArticle;
use App\Wikipedia\WikipediaExploration;

/**
 * Ce qu'une recherche réelle a reçu des moteurs, de Wikipédia et de Cohere, enregistré par le banc d'essai.
 * Le jeu de calibrage le rejoue sans réseau : fusion, tri, signaux et conseil sont recalculés, les réponses figées.
 *
 * @phpstan-type Fixture array{expression: string, lang: string, recorded: string, sources: list<string>, failed: list<string>,
 *     concepts: list<string>, suggestions: array<string, list<string>>, related: array<string, list<array{int, string}>>,
 *     wikipedia: ?array{articles: list<array{title: string, views: int, counted: bool, fromConcept: bool, coverage: float}>, sections: int, types: list<string>},
 *     pages: list<array{group: string, title: string, intent: ?string, keywords: list<string>}>, categories: array<string, string>}
 */
final class BenchFixture
{
    /** @return Fixture */
    public static function record(string $expression, string $lang, SearchResult $result, CollectResult $collected): array
    {
        $failed = array_map(static fn (SourceFailure $f): string => $f->source, $result->failedSources);
        $suggestions = [];
        $related = [];
        foreach ($collected->suggestions as $suggestion) {
            if (\in_array($suggestion->source, $failed, true)) {
                continue;
            }
            // Les suggestions des concepts sont rangées ensemble, avec leur rang : seul compte qu'elles ne viennent pas de l'expression.
            if ($suggestion->fromSeed) {
                $suggestions[$suggestion->source][] = $suggestion->text;
            } else {
                $related[$suggestion->source][] = [$suggestion->rank, $suggestion->text];
            }
        }

        $categories = [];
        foreach ([...$result->keywords, ...$result->excluded] as $keyword) {
            if (null !== $keyword->category) {
                $categories[$keyword->text] = $keyword->category->value;
            }
        }
        $pages = [];
        foreach ($result->field->groups ?? [] as $group) {
            foreach ($group->pages as $page) {
                $pages[] = ['group' => $group->name, 'title' => $page->title, 'intent' => $page->intent?->value,
                    'keywords' => array_map(static fn (Keyword $k): string => $k->text, $page->getAllKeywords())];
            }
        }

        return [
            'expression' => $expression,
            'lang' => $lang,
            'recorded' => $result->analyzedAt->format('Y-m-d'),
            'sources' => array_values(array_diff($collected->sources, $failed)),
            'failed' => $failed,
            'concepts' => $result->concepts,
            'suggestions' => $suggestions,
            'related' => $related,
            'wikipedia' => null !== $result->wikipedia ? [
                'articles' => array_map(static fn (WikipediaArticle $a): array => ['title' => $a->title, 'views' => $a->views, 'counted' => $a->counted, 'fromConcept' => $a->fromConcept, 'coverage' => round($a->coverage, 3)], $result->wikipedia->articles),
                'sections' => $result->wikipedia->sections,
                'types' => $result->wikipedia->types,
            ] : null,
            'pages' => $pages,
            'categories' => $categories,
        ];
    }

    /** @param Fixture $fixture */
    public static function replay(array $fixture): SearchResult
    {
        $suggestions = [];
        foreach ($fixture['suggestions'] as $source => $texts) {
            foreach ($texts as $rank => $text) {
                $suggestions[] = new RawSuggestion($text, $source, $rank, true);
            }
        }
        foreach ($fixture['related'] as $source => $ranked) {
            foreach ($ranked as [$rank, $text]) {
                $suggestions[] = new RawSuggestion($text, $source, $rank, false);
            }
        }
        $collected = new CollectResult($suggestions, [], $fixture['sources']);

        $wikipedia = null !== $fixture['wikipedia'] ? new WikipediaExploration(
            array_map(static fn (array $a): WikipediaArticle => new WikipediaArticle($a['title'], $fixture['lang'], $a['views'], [], $a['counted'], $a['fromConcept'], $a['coverage']), $fixture['wikipedia']['articles']),
            types: $fixture['wikipedia']['types'],
            sections: $fixture['wikipedia']['sections'],
        ) : null;

        [, $signals] = KeywordSearch::measure($collected, $fixture['expression'], $fixture['lang'], $wikipedia, $fixture['concepts'], new SuggestionMerger(), new KeywordFilter());

        $keywords = [];
        foreach ($fixture['categories'] as $text => $category) {
            $keywords[] = (new Keyword((string) $text, [], 0))->withCategory(KeywordCategory::from($category));
        }

        // Le plan est reconstruit comme en production, pour que le garde-fou d'intention relise les mêmes mots-clés.
        $plan = [];
        $placed = [];
        foreach ($fixture['pages'] as $page) {
            $positions = [];
            foreach ($page['keywords'] as $text) {
                $positions[] = \count($placed);
                $placed[] = new Keyword($text, [], 1);
            }
            $plan[$page['group']][] = ['title' => $page['title'], 'intent' => Intent::tryFrom((string) $page['intent']), 'keywords' => $positions, 'sections' => []];
        }
        $field = SemanticField::build(array_map(static fn (string $name, array $pages): array => ['name' => $name, 'pages' => $pages], array_keys($plan), array_values($plan)), $placed);

        return new SearchResult(
            keywords: $keywords,
            total: 0,
            failedSources: [],
            sourceCount: \count($fixture['sources']),
            demand: Demand::estimate($suggestions, $fixture['sources']),
            field: [] !== $field->groups ? $field : null,
            wikipedia: $wikipedia,
            signals: $signals,
            concepts: $fixture['concepts'],
        );
    }
}
