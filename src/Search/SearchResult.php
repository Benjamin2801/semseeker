<?php

namespace App\Search;

use App\Ai\KeywordCategory;
use App\Suggest\Keyword;
use App\Suggest\SourceFailure;
use App\Wikipedia\WikipediaArticle;
use App\Wikipedia\WikipediaExploration;

final class SearchResult
{
    private const CACHED_CLASSES = [self::class, Keyword::class, SourceFailure::class, DemandEstimate::class, SemanticField::class,
        FieldGroup::class, FieldPage::class, FieldSection::class, WikipediaExploration::class, WikipediaArticle::class, PageSignals::class, SearchPreparation::class];

    public const FIELD_TIMEOUT = 'timeout';
    public const FIELD_UNAVAILABLE = 'unavailable';
    public const FIELD_TOO_FEW_KEYWORDS = 'too_few_keywords';
    public const FIELD_PENDING = 'pending';

    public function __construct(
        /** @var list<Keyword> */
        public readonly array $keywords,

        public readonly int $total,
        /** @var list<SourceFailure> */
        public readonly array $failedSources,

        public readonly int $sourceCount,

        public readonly ?DemandEstimate $demand,

        public readonly ?SemanticField $field,

        public readonly ?string $fieldIssue = null,

        public readonly ?WikipediaExploration $wikipedia = null,
        /** @var list<Keyword> */
        public readonly array $excluded = [],

        public readonly ?PageSignals $signals = null,
        /** @var list<string> */
        public readonly array $concepts = [],

        public readonly \DateTimeImmutable $analyzedAt = new \DateTimeImmutable(),
    ) {
    }

    /** @return array<value-of<KeywordCategory>, list<Keyword>> */
    public function getCategories(): array
    {
        $byCategory = [];
        foreach ([...$this->keywords, ...$this->excluded] as $keyword) {
            if (null !== $keyword->category) {
                $byCategory[$keyword->category->value][] = $keyword;
            }
        }
        if ([] === $byCategory) {
            return [];
        }

        $sorted = [];
        foreach (KeywordCategory::cases() as $category) {
            $keywords = $byCategory[$category->value] ?? [];
            usort($keywords, static fn (Keyword $a, Keyword $b): int => $b->score <=> $a->score);
            $sorted[$category->value] = $keywords;
        }

        return $sorted;
    }

    public function getAdvice(): Advice
    {
        return Advice::of($this);
    }

    public static function format(): string
    {
        $shape = '';
        foreach (self::CACHED_CLASSES as $class) {
            $shape .= $class.':'.implode(',', array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass($class))->getProperties())).';';
        }

        return hash('xxh32', $shape);
    }

    // Sans concepts (Cohere lent ou en panne), le champ est amputé : gardé peu de temps, pour être refait.
    public function isComplete(): bool
    {
        return null !== $this->field && [] !== $this->concepts && [] === $this->failedSources;
    }
}
