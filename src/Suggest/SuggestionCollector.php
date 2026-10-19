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

    private const CACHE_TTL = 86400;

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
    ) {
    }

    /** @return list<Pending> */
    public function start(string $keyword, string $lang): array
    {
        return $this->request([$keyword], $lang);
    }

    /**
     * @param list<string>  $concepts
     * @param list<Pending> $started
     */
    public function collect(string $keyword, string $lang, array $concepts = [], array $started = []): CollectResult
    {
        $pending = [...([] !== $started ? $started : $this->request([$keyword], $lang)), ...$this->request($concepts, $lang)];

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
     * @param list<string> $queries
     *
     * @return list<Pending>
     */
    private function request(array $queries, string $lang): array
    {
        $this->client ??= new RetryableHttpClient($this->httpClient->withOptions(self::REQUEST_OPTIONS), new StartupFailureRetryStrategy(), 1);

        $cached = [];
        foreach ($queries as $query) {
            $item = $this->cache->getItem(self::cacheKey($query, $lang));
            $cached[$query] = $item->isHit() ? (array) $item->get() : [];
        }

        $pending = [];
        foreach ($this->sources as $source) {
            foreach ($queries as $query) {
                $pending[] = [$source, $query, $cached[$query][$source->getName()] ?? $source->request($this->client, $query, $lang)];
            }
        }

        return $pending;
    }

    private static function cacheKey(string $query, string $lang): string
    {
        return 'suggest.'.hash('xxh128', $lang.'|'.$query);
    }
}
