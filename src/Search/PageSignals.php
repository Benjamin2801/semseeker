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

        public readonly string $parent = '',

        public readonly int $facets = 0,

        public readonly int $seedSuggestions = 0,

        public readonly int $searched = 0,

        public readonly int $confirmedConcepts = 0,

        public readonly int $questions = 0,
        public readonly ?float $commercialShare = null,
    ) {
    }

    /**
     * @param list<Keyword>       $merged
     * @param list<RawSuggestion> $suggestions
     * @param list<string>        $responding
     * @param list<string>        $topics
     */
    public static function from(string $seed, string $lang, array $merged, array $suggestions, array $responding, KeywordFilter $filter, int $types, array $topics = []): self
    {
        $cues = array_fill_keys(array_keys(Lexicon::PAGE_CUES), 0);
        $local = 0;
        foreach ($merged as $keyword) {
            if ($filter->isLocal($keyword->text, $seed, $lang)) {
                ++$local;
                continue;
            }
            foreach (self::families($keyword->text, $seed) as $family) {
                ++$cues[$family];
            }
        }

        $fills = Demand::fills($suggestions, $responding);

        $seedCues = self::families($seed, '');
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
            parent: self::parent($seed, $topics),
            facets: \count($facets),
            seedSuggestions: \count(array_unique(array_map(static fn (RawSuggestion $s): string => SuggestionMerger::key($s->text), $fromSeed))),
        );
    }

    /**
     * @param list<Keyword> $merged
     * @param list<string>  $concepts
     */
    public function withReach(array $merged, array $concepts): self
    {
        $keys = array_map(static fn (Keyword $k): string => SuggestionMerger::key($k->text), $merged);
        $confirmed = array_filter($concepts, static function (string $concept) use ($keys): bool {
            $key = SuggestionMerger::key($concept);

            return \count(array_filter($keys, static fn (string $k): bool => str_starts_with($k, $key))) >= self::CONCEPT_SUGGESTIONS;
        });

        return $this->with([
            'searched' => \count(array_filter($merged, static fn (Keyword $k): bool => $k->score >= self::SEARCHED_SCORE)),
            'confirmedConcepts' => \count($confirmed),
            'questions' => \count(array_filter($merged, static fn (Keyword $k): bool => [] !== self::families($k->text, '', ['question']))),
            'commercialShare' => [] !== $merged ? \count(array_filter($merged, static fn (Keyword $k): bool => [] !== Lexicon::commercialWords($k->text))) / \count($merged) : null,
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @param list<string> $topics */
    private static function parent(string $seed, array $topics): string
    {
        $words = self::withoutQualifiers(preg_split('/\s+/u', trim($seed), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        $count = \count($words);
        $seedKey = SuggestionMerger::key($seed);
        $cueWords = Lexicon::words(array_merge(...array_values(Lexicon::PAGE_CUES)));
        $actions = [...Lexicon::LINK_WORDS, ...Lexicon::ACTION_WORDS, ...$cueWords];
        $tail = [...Lexicon::LINK_WORDS, ...Lexicon::AUDIENCE_WORDS];

        $subject = self::trim($words, $actions, [...$tail, ...$cueWords]);
        $subjectKey = SuggestionMerger::key(implode(' ', $subject));

        $topicKeys = array_flip(array_map(static fn (string $t): string => SuggestionMerger::key((string) preg_replace('/\s*\([^)]*\)$/', '', $t)), $topics));
        for ($length = $count - 1; $length >= 1; --$length) {
            for ($start = 0; $start + $length <= $count; ++$start) {
                $slice = \array_slice($words, $start, $length);
                $headless = self::trim($slice, Lexicon::LINK_WORDS, []);
                $part = self::trim($headless, [], $tail);

                $offset = $start + \count($slice) - \count($headless);
                if (1 === \count($part) && ($offset === $count - 1 || [] !== self::trim(\array_slice($words, 0, $offset), $actions, $actions))) {
                    continue;
                }
                if ([] !== $part && isset($topicKeys[SuggestionMerger::key(implode(' ', $part))]) && !Lexicon::namesNoSubject(implode(' ', $part))) {
                    return [] === array_diff($part, $subject) && \count($subject) > \count($part) && $subjectKey !== $seedKey ? implode(' ', $subject) : implode(' ', $part);
                }
            }
        }

        $stripped = self::trim($words, $actions, $tail);
        if ([] !== $stripped && SuggestionMerger::key(implode(' ', $stripped)) !== $seedKey) {
            return implode(' ', $stripped);
        }

        return '';
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function withoutQualifiers(array $words): array
    {
        do {
            $removed = false;
            foreach (Lexicon::QUALIFIERS as $qualifier) {
                $length = substr_count($qualifier, ' ') + 1;
                $end = SuggestionMerger::normalize(implode(' ', \array_slice($words, -$length)));
                if (\count($words) > $length && $end === $qualifier) {
                    $words = \array_slice($words, 0, -$length);
                    $removed = true;
                }
            }
        } while ($removed);

        return $words;
    }

    /**
     * @param list<string> $words
     * @param list<string> $head
     * @param list<string> $tail
     *
     * @return list<string>
     */
    private static function trim(array $words, array $head, array $tail): array
    {
        $in = static function (string $word, array $list): bool {
            $normalized = SuggestionMerger::normalize($word);

            return \in_array($normalized, $list, true) || \in_array((string) preg_replace('/[sx]$/', '', $normalized), $list, true);
        };
        while ([] !== $words && $in($words[0], $head)) {
            array_shift($words);
        }
        while ([] !== $words && $in($words[\count($words) - 1], $tail)) {
            array_pop($words);
        }

        return $words;
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
