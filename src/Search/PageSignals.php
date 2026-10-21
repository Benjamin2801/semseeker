<?php

namespace App\Search;

use App\Suggest\Keyword;
use App\Suggest\RawSuggestion;
use App\Suggest\SuggestionMerger;

final class PageSignals
{
    private const FACET_SOURCES = 2;
    private const CONCEPT_SUGGESTIONS = 3;
    private const SEARCHED_SCORE = 10;
    private const CONFIRMED_CUE_SHARE = 0.02;
    private const CONFIRMED_JOB_FILL = 0.5;

    public function __construct(
        public readonly int $total,
        /** @var array<string, int> */
        public readonly array $cues,

        public readonly int $local,
        /** @var array<string, float> */
        public readonly array $fills,

        public readonly int $types = 0,

        public readonly bool $question = false,
        /** @var list<string> */
        public readonly array $seedCues = [],

        public readonly bool $model = false,

        public readonly int $words = 0,

        public readonly int $facets = 0,

        public readonly int $seedSuggestions = 0,

        public readonly int $searched = 0,

        public readonly int $confirmedConcepts = 0,

        public readonly int $questions = 0,
        public readonly ?float $commercialShare = null,

        public readonly bool $seedLocal = false,
    ) {
    }

    /**
     * @param list<Keyword>       $merged
     * @param list<RawSuggestion> $suggestions
     * @param list<string>        $responding
     */
    public static function from(string $seed, string $lang, array $merged, array $suggestions, array $responding, KeywordFilter $filter, int $types, ?LexicalContext $context = null): self
    {
        $context ??= LexicalContext::of($seed);
        $cues = array_fill_keys(array_keys(Lexicon::PAGE_CUES), 0);
        $local = 0;
        foreach ($merged as $keyword) {
            if ($filter->isLocal($keyword->text, $seed, $lang, $context)) {
                ++$local;
                continue;
            }
            foreach (self::families($context->read($keyword->text), $context->readSeed()) as $family) {
                ++$cues[$family];
            }
        }

        $fills = Demand::fills($suggestions, $responding);
        $total = \count($merged) - $local;

        // Un indice à double sens (« prix », « test », « top », « formation ») ne décide que si les recherches autour
        // de l'expression parlent de la même chose : « test de grossesse » n'est suivi d'aucun avis de consommateur.
        $seedCues = array_values(array_filter(self::families($context->readSeed(), ''), static fn (string $family): bool => !self::onlyAmbiguous($context->readSeed(), $family)
            || ($total > 0 && $cues[$family] / $total >= self::CONFIRMED_CUE_SHARE)
            || ('job' === $family && ($fills['indeed'] ?? 0) >= self::CONFIRMED_JOB_FILL)));
        // « plombier paris 15 », « plumber near me » : le lieu est dans l'expression elle-même.
        $seedLocal = $filter->isLocal($seed, '', $lang, $context);
        $seedWords = array_filter(explode(' ', SuggestionMerger::key($seed)));
        $facets = [];
        foreach ($merged as $keyword) {
            $words = array_filter(explode(' ', SuggestionMerger::key($keyword->text)));
            $added = array_values(array_diff($words, $seedWords));
            if ([] === array_diff($seedWords, $words) && [] !== $added) {
                $facets[$added[0]] = [...$facets[$added[0]] ?? [], ...array_keys($keyword->positions)];
            }
        }

        // Une facette vue sur un seul moteur n'est souvent que son bruit.
        $facets = array_filter($facets, static fn (array $sources): bool => \count(array_unique($sources)) >= self::FACET_SOURCES);

        $fromSeed = array_filter($suggestions, static fn (RawSuggestion $s): bool => $s->fromSeed);

        return new self(
            total: \count($merged) - $local,
            cues: $cues,
            local: $local,
            fills: $fills,
            types: $types,
            question: \in_array('question', $seedCues, true),
            seedCues: $seedCues,
            model: 1 === preg_match('/\d/', $seed),
            words: \count($seedWords),
            facets: \count($facets),
            seedSuggestions: \count(array_unique(array_map(static fn (RawSuggestion $s): string => SuggestionMerger::key($s->text), $fromSeed))),
            seedLocal: $seedLocal,
        );
    }

    /**
     * @param list<Keyword> $merged
     * @param list<string>  $concepts
     */
    public function withReach(array $merged, array $concepts, ?LexicalContext $context = null): self
    {
        $context ??= LexicalContext::none();
        $keys = array_map(static fn (Keyword $k): string => SuggestionMerger::key($k->text), $merged);
        $confirmed = array_filter($concepts, static function (string $concept) use ($keys): bool {
            $key = SuggestionMerger::key($concept);

            return \count(array_filter($keys, static fn (string $k): bool => str_starts_with($k, $key))) >= self::CONCEPT_SUGGESTIONS;
        });

        return $this->with([
            'searched' => \count(array_filter($merged, static fn (Keyword $k): bool => $k->score >= self::SEARCHED_SCORE)),
            'confirmedConcepts' => \count($confirmed),
            'questions' => \count(array_filter($merged, static fn (Keyword $k): bool => [] !== self::families($k->text, '', ['question']))),
            'commercialShare' => [] !== $merged ? \count(array_filter($merged, static fn (Keyword $k): bool => [] !== Lexicon::commercialWords($context->read($k->text)))) / \count($merged) : null,
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function share(string $family): float
    {
        return $this->total > 0 ? ($this->cues[$family] ?? 0) / $this->total : 0.0;
    }

    public function localShare(): float
    {
        $all = $this->total + $this->local;

        return $all > 0 ? $this->local / $all : 0.0;
    }

    /** @param list<string> $sources */
    public function fill(array $sources): ?float
    {
        $fills = array_intersect_key($this->fills, array_flip($sources));

        return [] !== $fills ? array_sum($fills) / \count($fills) : null;
    }

    /**
     * @param array<string> $cues
     */
    private static function mentions(string $text, array $cues): bool
    {
        foreach ($cues as $cue) {
            if (preg_match('/\b'.str_replace(' ', '[\s\'-]+', $cue).'[sx]?\b/', $text)) {
                return true;
            }
        }

        return false;
    }

    // Tous les mots qui portent cet indice dans le texte ont un autre sens courant.
    private static function onlyAmbiguous(string $text, string $family): bool
    {
        $found = array_filter(Lexicon::PAGE_CUES[$family] ?? [], static fn (string $cue): bool => self::mentions($text, [$cue]));

        return [] !== $found && [] === array_diff($found, Lexicon::AMBIGUOUS_CUES);
    }

    /**
     * @param list<string>|null $only
     *
     * @return list<string>
     */
    private static function families(string $text, string $seed, ?array $only = null): array
    {
        $text = SuggestionMerger::normalize($text);
        $seed = SuggestionMerger::normalize($seed);
        $found = [];
        foreach (Lexicon::PAGE_CUES as $family => $cues) {
            if (null !== $only && !\in_array($family, $only, true)) {
                continue;
            }
            foreach ($cues as $cue) {
                // Une année seule date un classement ou un guide (« tokyo attractions 2026 ») : il faut un vrai mot d'actualité.
                if ('news' === $family && '20\d\d' === $cue && !self::mentions($text, array_diff($cues, [$cue]))) {
                    continue;
                }
                $regex = '/\b'.str_replace(' ', '[\s\'-]+', $cue).'[sx]?\b/';
                if (preg_match($regex, $text) && !preg_match($regex, $seed)) {
                    $found[] = $family;
                    break;
                }
            }
        }

        return $found;
    }
}
