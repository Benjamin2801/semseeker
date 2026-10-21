<?php

namespace App\Controller;

use App\Search\KeywordSearch;
use App\Search\SearchPreparation;
use App\Search\SearchResult;
use App\Suggest\SuggestionMerger;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\String\u;

/**
 * Une analyse en deux requêtes : la page montre tout de suite le conseil et les mots-clés, puis le navigateur demande le
 * plan, qui dispose ainsi de son propre délai d'exécution (environ 30 s sur l'hébergement mutualisé).
 */
final class SearchController extends AbstractController
{
    private const CACHE_TTL = 86400;

    private const DEGRADED_CACHE_TTL = 600;

    // Le temps de charger la page et de demander le plan, avec de la marge.
    private const PREPARATION_TTL = 600;

    public function __construct(
        private readonly KeywordSearch $keywordSearch,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
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
        [$keyword, $lang] = $this->query($request);
        if ('' === $keyword) {
            return $this->redirectToRoute('home', ['_locale' => $request->getLocale()]);
        }

        $done = $this->cache->getItem($this->key('search', $keyword, $lang));
        if ($done->isHit() && $done->get() instanceof SearchResult) {
            return $this->results($keyword, $lang, $done->get());
        }

        // La préparation déjà faite (page rechargée avant le plan) ne coûte pas une seconde recherche.
        $pending = $this->cache->getItem($this->key('preparation', $keyword, $lang));
        if (!$pending->isHit() || !$pending->get() instanceof SearchPreparation) {
            $limit = $this->searchLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = $limit->getRetryAfter();
                $response = $this->render('search/limit.html.twig', ['retry_after' => $retryAfter], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
                $response->headers->set('Retry-After', (string) max(0, $retryAfter->getTimestamp() - time()));

                return $response;
            }
            $this->cache->save($pending->set($this->keywordSearch->prepare($keyword, $lang))->expiresAfter(self::PREPARATION_TTL));
        }
        /** @var SearchPreparation $preparation */
        $preparation = $pending->get();

        return $this->results($keyword, $lang, $preparation->provisional());
    }

    // Le plan, demandé par la page en arrière-plan : la section des résultats, complète.
    #[Route('/{_locale}/search/plan', name: 'search_plan', requirements: ['_locale' => '%app.locales%'], methods: ['GET'])]
    public function plan(Request $request): Response
    {
        [$keyword, $lang] = $this->query($request);
        $done = $this->cache->getItem($this->key('search', $keyword, $lang));
        if (!$done->isHit() || !$done->get() instanceof SearchResult) {
            $pending = $this->cache->getItem($this->key('preparation', $keyword, $lang));
            if (!$pending->isHit() || !$pending->get() instanceof SearchPreparation) {
                // Préparation expirée : la page relance la recherche entière.
                return new Response('', Response::HTTP_GONE);
            }
            $result = $this->keywordSearch->complete($pending->get(), microtime(true));
            $this->cache->save($done->set($result)->expiresAfter($result->isComplete() ? self::CACHE_TTL : self::DEGRADED_CACHE_TTL));
            $this->cache->deleteItem($this->key('preparation', $keyword, $lang));
        }

        return $this->render('search/_results.html.twig', ['keyword' => $keyword, 'lang' => $lang, 'result' => $done->get()]);
    }

    /** @return array{string, string} */
    private function query(Request $request): array
    {
        $keyword = u($request->query->getString('q'))
            ->collapseWhitespace()
            ->truncate($this->maxKeywordLength)
            ->toString();
        $lang = $request->query->getString('lang');

        return [$keyword, \in_array($lang, $this->enabledLocales, true) ? $lang : $request->getLocale()];
    }

    private function key(string $stage, string $keyword, string $lang): string
    {
        return $stage.'.'.SearchResult::format().'.'.hash('xxh128', $lang.'|'.SuggestionMerger::key($keyword));
    }

    private function results(string $keyword, string $lang, SearchResult $result): Response
    {
        return $this->render('search/results.html.twig', ['keyword' => $keyword, 'lang' => $lang, 'result' => $result]);
    }
}
