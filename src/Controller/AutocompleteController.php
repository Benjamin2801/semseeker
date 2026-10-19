<?php

namespace App\Controller;

use App\Suggest\Source\GoogleSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

final class AutocompleteController extends AbstractController
{
    private const CACHE_TTL = 86400;

    private const MAX_SUGGESTIONS = 8;

    private const MIN_LENGTH = 2;

    public function __construct(
        private readonly GoogleSource $google,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        /** @var list<string> */
        private readonly array $enabledLocales,
        #[Autowire(param: 'app.search.max_keyword_length')]
        private readonly int $maxKeywordLength,
    ) {
    }

    #[Route('/{_locale}/autocomplete', name: 'autocomplete', requirements: ['_locale' => '%app.locales%'], methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $query = u($request->query->getString('q'))->collapseWhitespace()->truncate($this->maxKeywordLength)->lower()->toString();
        $lang = $request->query->getString('lang');
        if (!\in_array($lang, $this->enabledLocales, true)) {
            $lang = $request->getLocale();
        }
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return new JsonResponse([]);
        }

        $suggestions = $this->cache->get(
            'autocomplete.'.hash('xxh128', $lang.'|'.$query),
            function (ItemInterface $item) use ($query, $lang): array {
                $item->expiresAfter(self::CACHE_TTL);
                try {
                    return \array_slice($this->google->parse($this->google->request($this->httpClient, $query, $lang)), 0, self::MAX_SUGGESTIONS);
                } catch (ExceptionInterface|\UnexpectedValueException|\JsonException) {
                    $item->expiresAfter(60);

                    return [];
                }
            },
        );

        $response = new JsonResponse($suggestions);
        $response->setPublic()->setMaxAge(3600);

        return $response;
    }
}
