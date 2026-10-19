<?php

namespace App\Command;

use App\Search\PageType;
use App\Search\SearchResult;
use App\Suggest\SuggestionMerger;

/**
 * Ce qu'un référenceur attend pour une expression : les types de page acceptables, page ou simple section, et
 * l'intention dominante du plan. Un écart connu (« gap ») est documenté plutôt que masqué.
 */
final class BenchExpectation
{
    public function __construct(
        public readonly string $expression,
        public readonly string $lang,
        /** @var list<PageType> */
        public readonly array $verdicts,
        public readonly ?bool $page,
        public readonly ?string $intent,
        public readonly string $why,
        public readonly ?string $gap = null,
    ) {
    }

    /** @return list<self> */
    public static function load(string $path): array
    {
        /** @var list<array{expression: string, lang?: string, verdicts: list<string>, page: ?bool, intent?: string, why: string, gap?: string}> $cases */
        $cases = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $c): self => new self(
            $c['expression'],
            $c['lang'] ?? 'fr',
            array_map(PageType::from(...), $c['verdicts']),
            $c['page'],
            $c['intent'] ?? null,
            $c['why'],
            $c['gap'] ?? null,
        ), $cases);
    }

    public function slug(): string
    {
        return $this->lang.'-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', SuggestionMerger::normalize($this->expression)), '-');
    }

    /** @return array{verdict: bool, page: bool, intent: ?bool} */
    public function check(SearchResult $result): array
    {
        $verdict = $result->getAdvice()->verdict;
        $intent = self::intentOf($result);

        return [
            'verdict' => \in_array($verdict, $this->verdicts, true),
            'page' => null === $this->page || $this->page === !$verdict->isSection(),
            'intent' => null !== $this->intent && null !== $intent ? $this->intent === $intent : null,
        ];
    }

    // Intention la plus fréquente parmi les pages du plan : le jugement de Cohere, garde-fou compris.
    public static function intentOf(SearchResult $result): ?string
    {
        $counts = [];
        foreach ($result->field->groups ?? [] as $group) {
            foreach ($group->pages as $page) {
                if (null !== $page->intent) {
                    $counts[$page->intent->value] = ($counts[$page->intent->value] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return [] !== $counts ? (string) array_key_first($counts) : null;
    }

    /** @param array{verdict: bool, page: bool, intent: ?bool} $check */
    public static function passes(array $check): bool
    {
        return $check['verdict'] && $check['page'] && false !== $check['intent'];
    }
}
