<?php

namespace App\Controller;

use App\Search\KeywordSearch;
use App\Search\SearchResult;
use App\Search\TooManySearchesException;
use App\Suggest\SuggestionMerger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function Symfony\Component\String\u;

final class SearchController extends AbstractController
{
    private const CACHE_TTL = 86400;

    private const DEGRADED_CACHE_TTL = 600;

    public function __construct(
        private readonly KeywordSearch $keywordSearch,
        private readonly CacheInterface $cache,
        private readonly RateLimiterFactoryInterface $searchLimiter,
        /** @var list<string> */
        private readonly array $enabledLocales,
        #[Autowire(param: 'app.search.max_keyword_length')]
        private readonly int $maxKeywordLength,
    ) {
    }

    #[Route('/{_locale}/search', name: 'search', requirements: ['_locale' => '%app.locales%'], methods: ['GET'])]
    public function search(Request $request): Response
    {
        $keyword = u($request->query->getString('q'))
            ->collapseWhitespace()
            ->truncate($this->maxKeywordLength)
            ->toString();
        if ('' === $keyword) {
            return $this->redirectToRoute('home', ['_locale' => $request->getLocale()]);
        }

        $lang = $request->query->getString('lang');
        if (!\in_array($lang, $this->enabledLocales, true)) {
            $lang = $request->getLocale();
        }

        try {
            $result = $this->cache->get(
                'search.'.SearchResult::format().'.'.hash('xxh128', $lang.'|'.SuggestionMerger::key($keyword)),
                fn (ItemInterface $item): SearchResult => $this->runSearch($item, $request, $keyword, $lang),
            );
        } catch (TooManySearchesException $e) {
            $response = $this->render(
                'search/limit.html.twig',
                ['retry_after' => $e->retryAfter],
                new Response(status: Response::HTTP_TOO_MANY_REQUESTS),
            );
            $response->headers->set('Retry-After', (string) max(0, $e->retryAfter->getTimestamp() - time()));

            return $response;
        }

        return $this->render('search/results.html.twig', [
            'keyword' => $keyword,
            'lang' => $lang,
            'result' => $result,
        ]);
    }

    private function runSearch(ItemInterface $item, Request $request, string $keyword, string $lang): SearchResult
    {
        $limit = $this->searchLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManySearchesException($limit->getRetryAfter());
        }

        $result = $this->keywordSearch->search($keyword, $lang);
        $item->expiresAfter($result->isComplete() ? self::CACHE_TTL : self::DEGRADED_CACHE_TTL);

        return $result;
    }
}
