<?php

namespace App\Ai;

use App\Search\LexicalContext;
use App\Search\Lexicon;

enum Intent: string
{
    case Informational = 'informational';
    case Commercial = 'commercial';
    case Transactional = 'transactional';

    private const CUE_SHARE = 1 / 3;

    /** @param list<string> $keywords */
    public static function fromKeywords(array $keywords, ?LexicalContext $context = null): ?self
    {
        $context ??= LexicalContext::none();
        if ([] === $keywords) {
            return null;
        }

        foreach (Lexicon::INTENT_CUES as $intent => $cues) {
            $regex = '/\b('.implode('|', array_map(static fn (string $c): string => preg_quote($c, '/'), $cues)).')\b/';
            $matching = array_filter($keywords, static fn (string $k): bool => 1 === preg_match($regex, $context->read($k)));
            if (\count($matching) >= self::CUE_SHARE * \count($keywords)) {
                return self::from($intent);
            }
        }

        return null;
    }
}
