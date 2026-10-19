<?php

namespace App\Search;

use App\Suggest\Keyword;

// Popularité estimée d'un terme qu'aucun moteur ne suggère. Elle plafonne à 28, sous les termes vraiment
// recherchés (35 et plus), pour ne jamais passer devant une mesure.
final class Estimate
{
    private const AI = 10;
    private const WIKIPEDIA_LINK = 6;
    private const OWN_ARTICLE = 8;
    private const VIEWS_MAX = 10;
    private const WIKIDATA_TYPE = 6;

    private const VIEWS_FLOOR = 100;
    private const VIEWS_CEILING = 100000;

    public static function of(Keyword $keyword, int $views): int
    {
        $points = match ($keyword->origin) {
            Keyword::ORIGIN_AI => self::AI,
            Keyword::ORIGIN_WIKIDATA => self::WIKIDATA_TYPE,
            default => self::WIKIPEDIA_LINK,
        };
        if ($views > 0) {
            $scale = (log10(max($views, self::VIEWS_FLOOR)) - log10(self::VIEWS_FLOOR)) / (log10(self::VIEWS_CEILING) - log10(self::VIEWS_FLOOR));
            $points += self::OWN_ARTICLE + (int) round(self::VIEWS_MAX * min(1, $scale));
        }

        return max(1, $points);
    }
}
