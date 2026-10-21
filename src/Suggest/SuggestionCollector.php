<?php

namespace App\Suggest;

use App\Suggest\Source\SuggestSourceInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @phpstan-type Pending array{SuggestSourceInterface, string, ResponseInterface|list<string>} */
final class SuggestionCollector
{
    private const REQUEST_OPTIONS = ['timeout' => 3, 'max_duration' => 4];

    // Les concepts arrivent tard et la structure attend leurs suggestions : un moteur lent (Indeed jusqu'à 4 s)
    // retardait tout le plan. Au-delà de 2 s, on se passe de lui pour ce concept.
    private const CONCEPT_REQUEST_OPTIONS = ['timeout' => 2, 'max_duration' => 2];

    private const CACHE_TTL = 86400;

    // L'autocomplétion ne propose « quel panneau solaire choisir » ou « aide pour panneau solaire » qu'à qui tape le
    // début : on interroge aussi ces variantes, sur deux moteurs seulement pour ne pas se faire bloquer. Le choix, l'usage
    // et l'alternative seulement : « comment » et « pourquoi » tiraient les plans d'achat vers l'informationnel.
    private const VARIANT_SOURCES = ['google', 'bing'];
    private const VARIANTS = [
        'fr' => ['quel %s', '%s pour', '%s ou'],
        'en' => ['which %s', '%s for', '%s vs'],
    ];
    private const QUESTION_START = '/^(comment|quel|quelle|quels|quelles|pourquoi|combien|how|what|why|which)\b/u';

    // Les suggestions de l'expression portent le conseil : on les garde une semaine pour qu'il reste stable.
    private const SEED_CACHE_TTL = 7 * 86400;

    private ?HttpClientInterface $client = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        /** @var iterable<SuggestSourceInterface> */
        #[AutowireIterator(SuggestSourceInterface::TAG)]
        private readonly iterable $sources,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache = new ArrayAdapter(),
        /** @var list<string> */
        private readonly array $variantSources = self::VARIANT_SOURCES,
    ) {
    }

    /** @return list<Pending> */
    public function start(string $keyword, string $lang): array
    {
        return [...$this->request([$keyword], $lang), ...$this->request(self::variants($keyword, $lang), $lang, sources: $this->variantSources)];
    }

    /** @return list<string> */
    public static function variants(string $keyword, string $lang): array
    {
        $question = 1 === preg_match(self::QUESTION_START, SuggestionMerger::normalize($keyword));

        return array_values(array_map(
            static fn (string $pattern): string => sprintf($pattern, $keyword),
            array_filter(self::VARIANTS[$lang] ?? [], static fn (string $pattern): bool => !$question || str_starts_with($pattern, '%s')),
        ));
    }

    /**
     * @param list<string>  $concepts
     * @param list<Pending> $started
     */
    public function collect(string $keyword, string $lang, array $concepts = [], array $started = []): CollectResult
    {
        $pending = [...([] !== $started ? $started : $this->start($keyword, $lang)), ...$this->request($concepts, $lang, self::CONCEPT_REQUEST_OPTIONS)];

        $answers = [];
        $suggestions = [];
        $succeeded = [];
        $failures = [];
        $names = [];
        foreach ($pending as [$source, $query, $response]) {
            $names[$source->getName()] = true;
            try {
                if ($response instanceof ResponseInterface) {
                    $texts = $source->parse($response);
                } else {
                    $texts = $response;
                }
                $answers[$query][$source->getName()] = $texts;
                foreach ($texts as $rank => $text) {
                    $suggestions[] = new RawSuggestion($text, $source->getName(), $rank, $query === $keyword);
                }
                $succeeded[$source->getName()] = true;
            } catch (\Throwable $e) {
                if ($response instanceof ResponseInterface) {
                    $response->cancel();
                }
                $failures[$source->getName()] = new SourceFailure($source->getName(), $source->getAcronym(), SourceFailure::reasonOf($e), mb_substr($e->getMessage(), 0, 200));
                $this->logger->warning('Source d\'autocomplétion en échec', [
                    'source' => $source->getName(),
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        foreach ($answers as $query => $bySource) {
            $this->cache->saveDeferred($this->cache->getItem(self::cacheKey((string) $query, $lang))->set($bySource)->expiresAfter((string) $query === $keyword ? self::SEED_CACHE_TTL : self::CACHE_TTL));
        }
        $this->cache->commit();

        return new CollectResult($suggestions, array_values(array_diff_key($failures, $succeeded)), array_keys($names));
    }

    /**
     * @param list<string>         $queries
     * @param array<string, mixed> $options
     * @param list<string>|null    $sources moteurs à interroger, tous par défaut
     *
     * @return list<Pending>
     */
    private function request(array $queries, string $lang, array $options = self::REQUEST_OPTIONS, ?array $sources = null): array
    {
        $this->client ??= new RetryableHttpClient($this->httpClient, new StartupFailureRetryStrategy(), 1);
        $client = $this->client->withOptions($options);

        $cached = [];
        foreach ($queries as $query) {
            $item = $this->cache->getItem(self::cacheKey($query, $lang));
            $cached[$query] = $item->isHit() ? (array) $item->get() : [];
        }

        $pending = [];
        foreach ($this->sources as $source) {
            if (null !== $sources && !\in_array($source->getName(), $sources, true)) {
                continue;
            }
            foreach ($queries as $query) {
                $pending[] = [$source, $query, $cached[$query][$source->getName()] ?? $source->request($client, $query, $lang)];
            }
        }

        return $pending;
    }

    private static function cacheKey(string $query, string $lang): string
    {
        return 'suggest.'.hash('xxh128', $lang.'|'.$query);
    }
}
