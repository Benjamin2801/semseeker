<?php

namespace App\Search;

use App\Suggest\SuggestionMerger;

final class Lexicon
{
    public const PAGE_CUES = [
        'definition' => ['definition', 'c est quoi', 'qu est ce', 'signification', 'definir', 'meaning', 'what is', 'define'],
        'howto' => ['comment', 'tuto', 'tutoriel', 'fabriquer', 'soi meme', 'reparer', 'regler', 'nettoyer', 'recette', 'how to', 'diy', 'tutorial', 'fix', 'recipe'],
        'question' => ['pourquoi', 'quand', 'combien', 'faut il', 'est ce que', 'quel', 'quelle', 'quels', 'quelles', 'why', 'when', 'how much', 'should', 'which'],
        'regulation' => ['obligation', 'obligatoire', 'loi', 'norme', 'normes', 'reglementation', 'decret', 'law', 'regulation', 'mandatory', 'compliance'],
        'news' => ['date', '20\d\d', 'actualite', 'actu', 'nouveau', 'nouvelle', 'nouveaute', 'annonce', 'sortie', 'lancement', 'report', 'news', 'latest', 'release', 'announcement', 'update'],
        'comparison' => ['meilleur', 'meilleure', 'meilleurs', 'meilleures', 'comparatif', 'comparaison', 'vs', 'versus', 'classement', 'top', 'best', 'comparison'],

        'price' => ['prix', 'tarif', 'tarifs', 'cout', 'combien coute', 'pas cher', 'price', 'prices', 'cost', 'cheap',
            'prime', 'primes', 'aide', 'aides', 'subvention', 'credit d impot', 'maprimerenov', 'eco ptz', 'grant', 'rebate', 'tax credit', 'subsidy'],
        'review' => ['avis', 'test', 'fiable', 'fiabilite', 'review', 'reviews', 'rating'],
        'service' => ['devis', 'installateur', 'artisan', 'pose', 'poseur', 'depannage', 'intervention', 'professionnel', 'contractor', 'installer', 'quote', 'hire'],
        'job' => ['salaire', 'formation', 'fiche metier', 'emploi', 'recrutement', 'offre d emploi', 'diplome', 'devenir', 'salary', 'training', 'job', 'jobs', 'career', 'become'],
    ];

    // Indices qui ont un autre sens courant : récompense (prix), métier (aide-soignant), examen (test), vêtement (top),
    // nom propre (Nouvelle-Calédonie), fruit ou échéance (date), offre de cours (formation), citation (quote), ordre (order).
    // Portés par l'expression, ils ne décident du type de page que si les suggestions confirment ce sens.
    public const AMBIGUOUS_CUES = ['prix', 'aide', 'aides', 'prime', 'primes', 'test', 'top', 'nouveau', 'nouvelle', 'date', 'sortie',
        'annonce', 'formation', 'pose', 'training', 'review', 'reviews', 'quote', 'report', 'release', 'update'];

    public const INTENT_CUES = [
        'transactional' => ['acheter', 'achat', 'commander', 'devis', 'installateur', 'poseur', 'buy', 'order', 'quote', 'hire'],
        'commercial' => ['prix', 'tarif', 'tarifs', 'cout', 'combien', 'avis', 'meilleur', 'meilleure', 'meilleurs', 'meilleures',
            'comparatif', 'comparaison', 'pas cher', 'promo', 'price', 'prices', 'cost', 'cheap', 'best', 'review', 'reviews',
            'comparison', 'vs'],
    ];

    public const SERVICE_ORDERS = ['livraison', 'a domicile', 'commander', 'commande en ligne', 'reparation', 'depannage', 'location',
        'delivery', 'repair', 'rental'];

    public const COMMERCIAL = ['prix', 'tarif', 'tarifs', 'cout', 'combien', 'devis', 'acheter', 'achat', 'aides', 'subvention',
        'prime', 'installateur', 'artisan', 'pas cher', 'promo', 'comparatif', 'meilleur', 'meilleure', 'budget', 'circuit', 'circuits',
        'sejour', 'sejours', 'organise', 'reservation', 'reserver', 'billet', 'billets', 'agence', 'tout compris', 'location',
        'en ligne', 'haut de gamme', 'online', 'premium', 'leclerc', 'carrefour', 'lidl', 'auchan', 'intermarche', 'aldi', 'monoprix',
        'franprix', 'amazon', 'cdiscount', 'ikea', 'castorama', 'darty', 'fnac', 'decathlon', 'walmart', 'costco', 'tesco',
        'price', 'cost', 'quote', 'buy', 'cheap', 'grant', 'rebate', 'contractor', 'best', 'booking', 'tickets', 'package', 'rental'];

    public const MODIFIERS = [
        'fr' => ['devis', 'prix', 'tarif', 'tarifs', 'avis', 'pas', 'cher', 'comparatif', 'meilleur', 'meilleure', 'achat',
            'acheter', 'location', 'vente', 'occasion', 'promo', 'gratuit', 'neuf', 'homme', 'femme', 'enfant'],
        'en' => ['price', 'prices', 'cost', 'cheap', 'best', 'review', 'reviews', 'buy', 'quote', 'rental', 'sale', 'free',
            'used', 'new', 'men', 'women', 'kids'],
    ];

    public const LINK_WORDS = ['de', 'du', 'des', 'a', 'au', 'aux', 'en', 'sans', 'avec', 'pour', 'sur', 'sous', 'dans', 'par', 'chez',
        'le', 'la', 'les', 'un', 'une', 'et', 'ou', 'd', 'l', 'of', 'for', 'with', 'without', 'the', 'an', 'to', 'in', 'on', 'and', 'or'];

    /**
     * @return list<string>
     */
    public static function commercialWords(string $text): array
    {
        preg_match_all('/\b('.implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), self::COMMERCIAL)).')\b/', SuggestionMerger::normalize($text), $found);

        return $found[1];
    }

    public static function ordersService(string $keyword): bool
    {
        return 1 === preg_match('/\b('.implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), self::SERVICE_ORDERS)).')\b/', SuggestionMerger::normalize($keyword));
    }
}
