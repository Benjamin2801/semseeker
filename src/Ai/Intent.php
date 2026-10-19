<?php

namespace App\Ai;

use App\Search\Lexicon;
use App\Suggest\SuggestionMerger;

enum Intent: string
{
    case Informational = 'informational';
    case Commercial = 'commercial';
    case Transactional = 'transactional';

    private const CUE_SHARE = 1 / 3;

    /** @param list<string> $keywords */
    public static function fromKeywords(array $keywords): ?self
    {
        if ([] === $keywords) {
            return null;
        }

        foreach (Lexicon::INTENT_CUES as $intent => $cues) {
            $regex = '/\b('.implode('|', array_map(static fn (string $c): string => preg_quote($c, '/'), $cues)).')\b/';
            $matching = array_filter($keywords, static fn (string $k): bool => 1 === preg_match($regex, SuggestionMerger::normalize($k)));
            if (\count($matching) >= self::CUE_SHARE * \count($keywords)) {
                return self::from($intent);
            }
        }

        return null;
    }
}
