<?php

namespace App\Tests\Search;

use App\Ai\AiException;
use App\Ai\ConceptFinderInterface;
use App\Ai\ContentPlannerInterface;
use App\Ai\KeywordCategory;
use App\Ai\PendingConcepts;
use App\Ai\Plan;
use App\Ai\PlanDraft;
use App\Search\Demand;
use App\Search\KeywordFilter;
use App\Search\KeywordSearch;
use App\Search\SearchResult;
use App\Suggest\Keyword;
use App\Suggest\Source\GoogleSource;
use App\Suggest\SuggestionCollector;
use App\Suggest\SuggestionMerger;
use App\Tests\Http\FakeHttpResponses;
use App\Wikipedia\WikipediaExplorer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KeywordSearchTest extends TestCase
{
    /** @var list<string> */
    private array $planned = [];

    /** @var list<string> */
    private array $types = [];

    public function testPlansEveryCandidateAndSetsAsideWhatThePlannerRejects(): void
    {
        $result = $this->search(reject: static fn (string $k): bool => str_contains($k, 'prix'))->search('pain', 'fr');

        self::assertSame(['pain commun', 'pain prix', 'pain b commun', 'pain b prix'], $this->planned);
        self::assertSame(['pain commun', 'pain b commun'], self::texts($result->keywords));
        self::assertSame(2, $result->total);
        self::assertNotNull($result->field);
        self::assertSame('Thème', $result->field->groups[0]->name);
        self::assertSame(['pain commun', 'pain b commun'], self::texts($result->field->groups[0]->getAllKeywords()));
        self::assertTrue($result->isComplete());
    }

    public function testNeverSetsAsideAServiceToOrder(): void
    {
        $result = $this->search(reject: static fn (string $k): bool => str_contains($k, 'livraison'), concepts: static fn (): array => ['livraison pain'])->search('pain', 'fr');

        self::assertContains('livraison pain commun', self::texts($result->keywords), 'Écartée par le modèle, la livraison reste.');
        $delivery = array_values(array_filter($result->keywords, static fn ($k): bool => 'livraison pain commun' === $k->text))[0];
        self::assertSame(KeywordCategory::Commercial, $delivery->category);
    }

    public function testExplainsWhyThereIsNoPlanWhenAlmostEverythingIsRejected(): void
    {
        $result = $this->search(reject: static fn (string $k): bool => 'pain commun' !== $k)->search('pain', 'fr');

        self::assertNull($result->field);
        self::assertSame(SearchResult::FIELD_TOO_FEW_KEYWORDS, $result->fieldIssue);
        self::assertSame(['pain commun'], self::texts($result->keywords));
    }

    public function testEstimatesTheDemandForTheSeed(): void
    {
        self::assertSame(Demand::Low, $this->search()->search('pain', 'fr')->demand?->level);
    }

    public function testShowsTheKeywordsWithoutPlanWhenThePlannerFails(): void
    {
        $result = $this->search(planFails: true)->search('pain', 'fr');

        self::assertNull($result->field);
        self::assertSame(SearchResult::FIELD_UNAVAILABLE, $result->fieldIssue);
        self::assertCount(4, $result->keywords);
        self::assertFalse($result->isComplete());
    }

    public function testExploresTheConcepts(): void
    {
        $result = $this->search(concepts: static fn (): array => ['levain'])->search('pain', 'fr');

        self::assertSame(['pain commun', 'pain prix', 'levain commun', 'levain prix'], self::texts($result->keywords));
    }

    public function testAddsTheCoreTermsOfTheAi(): void
    {
        $result = $this->search(core: ['pain b commun', 'pain de mie'])->search('pain', 'fr');

        self::assertSame(['pain commun', 'pain prix', 'pain b commun', 'pain b prix', 'pain de mie'], $this->planned, 'Déjà suggéré, un terme garde sa place.');
        self::assertSame(Keyword::ORIGIN_AI, $result->keywords[4]->origin);
        self::assertFalse($result->keywords[4]->isMeasured());
        self::assertSame(KeywordCategory::Core, $result->keywords[4]->category, 'Un terme fondamental du modèle reste un fondamental.');
    }

    public function testSetsAsideLocalQueriesBeforeTheAi(): void
    {
        $result = $this->search(concepts: static fn (): array => ['pain lyon'])->search('pain', 'fr');

        self::assertSame(['pain commun', 'pain prix'], $this->planned, 'Les suggestions lyonnaises ne sont pas envoyées à l\x27IA.');
        self::assertSame(2, $result->total);
    }

    public function testAddsTheWikipediaVocabularyToTheCandidates(): void
    {
        $result = $this->search(vocabulary: true)->search('pain', 'fr');

        $vocabulary = ['farine', 'eau potable', 'levure', 'baguette', 'boulangerie', 'pain de mie', 'pain complet'];
        self::assertSame(['pain commun', 'pain prix', 'pain b commun', 'pain b prix', ...$vocabulary], $this->planned);
        self::assertSame(0, $result->keywords[4]->score, 'Sans suggestion, un terme de Wikipédia n\'a pas de score.');
        self::assertSame(11, $result->total);
        self::assertSame(['pain de mie', 'pain complet'], $this->types, 'Les types gardés guident la structure.');
        self::assertSame(9000, $result->wikipedia?->articles[0]->views);
    }

    public function testQueriesOnlyTheSeedWhenTheConceptsFail(): void
    {
        $result = $this->search(concepts: static fn () => throw new AiException('panne'))->search('pain', 'fr');

        self::assertSame(['pain commun', 'pain prix'], self::texts($result->keywords));
    }

    public function testKeepsOnlyTheBestCandidates(): void
    {
        $result = $this->search(maxCandidates: 3)->search('pain', 'fr');

        self::assertCount(3, $this->planned);
        self::assertCount(3, $result->keywords);
        self::assertSame(4, $result->total);
    }

    /**
     * @param \Closure(string): bool|null $reject
     * @param list<string>                $core
     */
    private function search(?\Closure $reject = null, int $maxCandidates = 100, bool $planFails = false, ?\Closure $concepts = null, bool $vocabulary = false, array $core = []): KeywordSearch
    {
        $conceptFinder = new class($concepts ?? static fn (): array => ['pain b'], $core) implements ConceptFinderInterface {
            /** @param list<string> $core */
            public function __construct(private readonly \Closure $concepts, private readonly array $core)
            {
            }

            public function concepts(string $seed, string $lang): PendingConcepts
            {
                [$concepts, $core] = [$this->concepts, $this->core];

                return new PendingConcepts(static fn (): array => ['concepts' => $concepts(), 'subject' => null, 'core' => $core]);
            }
        };

        $planner = new class(function (array $types): void {
            $this->types = $types;
        }, function (array $keywords): void {
            $this->planned = $keywords;
        }, $reject ?? static fn (): bool => false, $planFails) implements ContentPlannerInterface {
            /**
             * @param \Closure(list<string>): void $onDraft
             * @param \Closure(list<string>): void $onAssign
             * @param \Closure(string): bool       $reject
             */
            public function __construct(
                private readonly \Closure $onDraft,
                private readonly \Closure $onAssign,
                private readonly \Closure $reject,
                private readonly bool $fails,
            ) {
            }

            public function draft(string $seed, array $keywords, array $scores, string $lang, float $maxDuration, array $types = [], array $articles = []): PlanDraft
            {
                ($this->onDraft)($types);

                return new PlanDraft($seed, $lang, $keywords, null);
            }

            public function assign(PlanDraft $draft): Plan
            {
                ($this->onAssign)($draft->keywords);
                if ($this->fails) {
                    throw new AiException('panne');
                }
                $rejected = array_keys(array_filter($draft->keywords, $this->reject));
                $placed = array_values(array_diff(array_keys($draft->keywords), $rejected));

                return new Plan([['name' => 'Thème', 'pages' => [['title' => 'Page', 'intent' => null, 'keywords' => $placed, 'sections' => []]]]], $rejected);
            }
        };

        $http = new MockHttpClient(new FakeHttpResponses());
        $collector = new SuggestionCollector($http, [new GoogleSource()], new NullLogger(), variantSources: []);

        $wikipedia = new WikipediaExplorer($vocabulary ? $http : new MockHttpClient(new MockResponse('', ['http_code' => 503])), new KeywordFilter(), new NullLogger());

        return new KeywordSearch($collector, new SuggestionMerger(), new KeywordFilter(), $wikipedia, $conceptFinder, $planner, new NullLogger(), $maxCandidates);
    }

    /**
     * @param list<Keyword> $keywords
     *
     * @return list<string>
     */
    private static function texts(array $keywords): array
    {
        return array_map(static fn (Keyword $k) => $k->text, $keywords);
    }
}
