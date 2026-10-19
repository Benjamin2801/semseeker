<?php

namespace App\Ai;

use App\Search\Lexicon;

enum KeywordCategory: string
{
    case Core = 'core';
    case Commercial = 'commercial';
    case Complementary = 'complementary';
    case Doubtful = 'doubtful';

    public function getIcon(): string
    {
        return match ($this) {
            self::Core => 'target',
            self::Commercial => 'tag',
            self::Complementary => 'layers',
            self::Doubtful => 'help',
        };
    }

    public static function decide(?self $proposed, string $keyword, bool $rejected, string $seed = ''): ?self
    {
        if ($rejected) {
            return self::Doubtful;
        }
        // Pour « meilleur grille pain », le mot « meilleur » ne rend pas commercial chaque suggestion qui le reprend.
        if ([] !== array_diff(Lexicon::commercialWords($keyword), Lexicon::commercialWords($seed))) {
            return self::Commercial;
        }

        return $proposed;
    }
}
