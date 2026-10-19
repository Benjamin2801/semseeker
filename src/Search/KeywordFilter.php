<?php

namespace App\Search;

use App\Suggest\SuggestionMerger;

final class KeywordFilter
{
    private const LOCAL_PATTERNS = [
        '\d{5}', 'pres de (chez )?moi', 'autour de moi', 'a proximite', '\d+(e|eme|er) arrondissement', 'near me', 'nearby',
    ];

    private const PATTERNS = [
        'horaires?', 'service client', 'numero de telephone', 'espace client', 'mon compte', 'se connecter',
        'opening hours', 'store hours', 'customer service', 'phone number', 'my account', 'sign in',
        'log in', 'login', 'en anglais', 'traduction', '\d+ lettres', 'mots? (fleches|croises)', 'in english', 'translation',
        'crossword',
    ];

    private const CITIES = [
        'fr' => [
            'paris', 'marseille', 'lyon', 'toulouse', 'nice', 'nantes', 'montpellier', 'strasbourg', 'bordeaux', 'lille',
            'rennes', 'reims', 'toulon', 'saint etienne', 'le havre', 'grenoble', 'dijon', 'angers', 'nimes',
            'villeurbanne', 'clermont ferrand', 'aix en provence', 'le mans', 'brest', 'limoges', 'amiens', 'perpignan',
            'metz', 'besancon', 'boulogne billancourt', 'orleans', 'mulhouse', 'rouen', 'caen', 'nancy', 'argenteuil',
            'montreuil', 'roubaix', 'tourcoing', 'avignon', 'nanterre', 'poitiers', 'versailles', 'creteil', 'pau',
            'la rochelle', 'calais', 'cannes', 'antibes', 'dunkerque', 'ajaccio', 'bastia', 'annecy', 'bayonne',
            'chambery', 'valence', 'lorient', 'vannes', 'quimper', 'troyes', 'colmar', 'bruxelles', 'geneve',
            'lausanne', 'montreal', 'quebec',
        ],
        'en' => [
            'new york', 'nyc', 'los angeles', 'chicago', 'houston', 'philadelphia', 'san antonio', 'san diego', 'dallas',
            'san jose', 'jacksonville', 'columbus', 'indianapolis', 'san francisco', 'seattle', 'denver',
            'washington dc', 'boston', 'nashville', 'detroit', 'las vegas', 'memphis', 'louisville',
            'baltimore', 'milwaukee', 'albuquerque', 'tucson', 'sacramento', 'atlanta', 'miami', 'minneapolis',
            'new orleans', 'cleveland', 'tampa', 'orlando', 'pittsburgh', 'cincinnati', 'st louis', 'london',
            'manchester', 'birmingham', 'liverpool', 'glasgow', 'edinburgh', 'toronto', 'vancouver', 'sydney',
            'melbourne',
        ],
    ];

    public function excludes(string $keyword, string $seed, string $lang): bool
    {
        return $this->matches([...self::LOCAL_PATTERNS, ...self::PATTERNS, ...self::CITIES[$lang] ?? []], $keyword, $seed);
    }

    public function isLocal(string $keyword, string $seed, string $lang): bool
    {
        return $this->matches([...self::LOCAL_PATTERNS, ...self::CITIES[$lang] ?? []], $keyword, $seed);
    }

    /** @param list<string> $patterns */
    private function matches(array $patterns, string $keyword, string $seed): bool
    {
        $text = SuggestionMerger::normalize($keyword);
        $seed = SuggestionMerger::normalize($seed);
        foreach ($patterns as $pattern) {
            $regex = '/\b'.str_replace(' ', '[\s-]+', $pattern).'\b/';
            if (preg_match($regex, $text) && !preg_match($regex, $seed)) {
                return true;
            }
        }

        return false;
    }
}
