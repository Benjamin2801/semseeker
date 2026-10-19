<?php

namespace App\Wikipedia;

final class WikipediaExploration
{
    public function __construct(
        /** @var list<WikipediaArticle> */
        public readonly array $articles,
        /** @var list<string> */
        public readonly array $introTerms = [],
        /** @var list<string> */
        public readonly array $relatedTerms = [],
        /** @var list<string> */
        public readonly array $types = [],

        public readonly int $sections = 0,
    ) {
    }

    /** @return list<WikipediaArticle> */
    public function getCountedArticles(): array
    {
        return array_values(array_filter($this->articles, static fn (WikipediaArticle $a): bool => $a->counted));
    }

    /** @param list<WikipediaArticle> $articles */
    public function withArticles(array $articles): self
    {
        return new self($articles, $this->introTerms, $this->relatedTerms, $this->types, $this->sections);
    }

    /** @param list<string> $onTopic */
    public function judged(array $onTopic): self
    {
        if ([] === $onTopic) {
            return $this;
        }

        return $this->withArticles(array_map(
            static fn (WikipediaArticle $a): WikipediaArticle => $a->fromConcept || \in_array($a->title, $onTopic, true) ? $a : $a->uncounted(),
            $this->articles,
        ));
    }
}
