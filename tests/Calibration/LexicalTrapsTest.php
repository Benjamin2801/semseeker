<?php

namespace App\Tests\Calibration;

use App\Ai\Intent;
use App\Ai\KeywordCategory;
use App\Search\Advice;
use App\Search\Demand;
use App\Search\DemandEstimate;
use App\Search\KeywordFilter;
use App\Search\PageSignals;
use App\Search\PageType;
use App\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calibrage des règles sans IA : ce qu'un référenceur attend, cas par cas. Un écart connu porte sa raison et compte
 * comme test incomplet ; s'il disparaît, le test échoue pour qu'on retire la mention.
 */
final class LexicalTrapsTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool, ?string}> */
    public static function filterCases(): iterable
    {
        yield 'code postal' => ['plombier 75015', 'plombier', 'fr', true, null];
        yield 'ville' => ['pompe à chaleur lyon', 'pompe à chaleur', 'fr', true, null];
        yield 'ville de l\'expression' => ['savon de marseille bio', 'savon de marseille', 'fr', false, null];
        yield 'près de chez moi' => ['plumber near me', 'plumber', 'en', true, null];
        yield 'horaires' => ['decathlon horaires', 'decathlon', 'fr', true, null];
        yield 'appellation' => ['savon de marseille', 'savon', 'fr', false, null];
        yield 'moutarde' => ['moutarde de dijon', 'moutarde', 'fr', false, null];
        yield 'vin' => ['vin de bordeaux', 'vin rouge', 'fr', false, null];
        yield 'couleur' => ['chaussures bordeaux femme', 'chaussures', 'fr', false, '« bordeaux » y est une couleur, au milieu de la requête'];
        yield 'pluriel homonyme' => ['cannes à pêche', 'canne à pêche', 'fr', false, null];
        yield 'paris sportifs' => ['paris sportifs', 'pronostic foot', 'fr', false, null];
        yield 'pâtisserie' => ['paris brest', 'pâtisserie', 'fr', false, 'Un gâteau, pas une ville'];
        yield 'histoire' => ['traité de versailles', 'première guerre mondiale', 'fr', false, null];
        yield 'chimie' => ['valence chimie', 'électron', 'fr', false, null];
        yield 'race de chien' => ['boston terrier', 'terrier', 'en', false, null];
        yield 'recette' => ['new york cheesecake', 'cheesecake', 'en', false, null];
        yield 'titre' => ['20000 lieues sous les mers', 'jules verne', 'fr', false, 'Un nombre à cinq chiffres n\'est pas toujours un code postal'];
    }

    #[DataProvider('filterCases')]
    public function testFilterSetsAsideOnlyPlacesAndBusinesses(string $keyword, string $seed, string $lang, bool $excluded, ?string $gap): void
    {
        self::expect($excluded === (new KeywordFilter())->excludes($keyword, $seed, $lang), $gap, sprintf('« %s » pour « %s »', $keyword, $seed));
    }

    /** @return iterable<string, array{string, string, list<PageType>, ?string}> */
    public static function seedCases(): iterable
    {
        yield 'devis' => ['devis toiture', 'fr', [PageType::Service], null];
        yield 'tutoriel' => ['comment détartrer une bouilloire', 'fr', [PageType::HowTo], null];
        yield 'comparatif' => ['meilleur aspirateur balai', 'fr', [PageType::Comparison], null];
        yield 'métier' => ['devenir pilote de ligne', 'fr', [PageType::Job], null];
        yield 'définition' => ['what is kubernetes', 'en', [PageType::Definition], null];
        yield 'tutoriel anglais' => ['how to tie a tie', 'en', [PageType::HowTo], null];
        yield 'aide soignante' => ['aide soignante', 'fr', [PageType::Job, PageType::Pillar], '« aide » y désigne un métier, pas une subvention'];
        yield 'prix littéraire' => ['prix goncourt', 'fr', [PageType::Pillar, PageType::News], '« prix » y est une récompense'];
        yield 'test médical' => ['test de grossesse', 'fr', [PageType::Pillar, PageType::PillarCommercial, PageType::HowTo], '« test » y est un examen, pas un banc d\'essai'];
        yield 'territoire' => ['nouvelle calédonie', 'fr', [PageType::Pillar], '« nouvelle » y fait partie d\'un nom propre'];
        yield 'date' => ['date de péremption', 'fr', [PageType::Pillar, PageType::Definition], '« date » n\'annonce pas une actualité'];
        yield 'vêtement' => ['crop top', 'fr', [PageType::Pillar, PageType::PillarCommercial, PageType::Category], '« top » y est un vêtement'];
        yield 'formation' => ['formation excel', 'fr', [PageType::Pillar, PageType::PillarCommercial, PageType::Service, PageType::Category], 'Une offre de formation, pas un métier'];
        yield 'citations' => ['quotes about love', 'en', [PageType::Pillar], '« quotes » y sont des citations, pas des devis'];
        yield 'revue de littérature' => ['literature review', 'en', [PageType::Pillar, PageType::HowTo, PageType::Definition], 'Un exercice universitaire, pas des avis'];
    }

    // Une expression qui porte elle-même un indice décide du type de page : ces indices doivent être sûrs.
    /** @param list<PageType> $verdicts */
    #[DataProvider('seedCases')]
    public function testCueCarriedByThePhraseChoosesTheRightPage(string $seed, string $lang, array $verdicts, ?string $gap): void
    {
        $signals = PageSignals::from($seed, $lang, [], [], [], new KeywordFilter(), 0);
        $verdict = Advice::of(new SearchResult([], 0, [], 8, new DemandEstimate(Demand::High, 0.9, 3), null, signals: $signals))->verdict;

        self::expect(\in_array($verdict, $verdicts, true), $gap, sprintf('« %s » : %s', $seed, $verdict->value));
    }

    /** @return iterable<string, array{list<string>, ?Intent, ?string}> */
    public static function intentCases(): iterable
    {
        yield 'prix' => [['canne à pêche prix', 'canne à pêche carpe', 'canne à pêche télescopique'], Intent::Commercial, null];
        yield 'achat' => [['acheter canne à pêche', 'canne à pêche pas cher', 'canne à pêche mer'], Intent::Transactional, null];
        yield 'recette' => [['tajine poulet', 'tajine poulet olives', 'tajine poulet citron'], null, null];
        yield 'durée' => [['combien de temps cuire un oeuf', 'oeuf mollet', 'oeuf poché'], null, '« combien de temps » n\'est pas une question de prix'];
        yield 'citation' => [['quote of the day', 'love quote', 'inspirational quote'], null, '« quote » y est une citation'];
        yield 'opérations' => [['order of operations', 'pemdas', 'order of operations worksheet'], null, '« order » y est un ordre de calcul'];
        yield 'prix nobel' => [['prix nobel de la paix', 'prix nobel 2026', 'lauréats'], null, '« prix » y est une récompense'];
    }

    /** @param list<string> $keywords */
    #[DataProvider('intentCases')]
    public function testIntentGuardReadsBuyingWordsOnly(array $keywords, ?Intent $intent, ?string $gap): void
    {
        self::expect($intent === Intent::fromKeywords($keywords), $gap, implode(', ', $keywords));
    }

    /** @return iterable<string, array{string, string, ?KeywordCategory, ?string}> */
    public static function categoryCases(): iterable
    {
        yield 'prix' => ['isolation combles prix', 'isolation combles', KeywordCategory::Commercial, null];
        yield 'enseigne' => ['canne à pêche decathlon', 'canne à pêche', KeywordCategory::Commercial, null];
        yield 'mot de l\'expression' => ['meilleur aspirateur balai sans fil', 'meilleur aspirateur balai', null, null];
        yield 'prix littéraire' => ['prix goncourt 2026', 'goncourt', null, '« prix » y est une récompense'];
        yield 'durée' => ['combien de temps cuire un oeuf', 'oeuf', null, '« combien » n\'y parle pas d\'argent'];
        yield 'électricité' => ['circuit électrique', 'électricité', null, 'Un circuit électrique n\'est pas un voyage organisé'];
        yield 'offre en ligne' => ['cours de piano en ligne', 'cours de piano', KeywordCategory::Commercial, null];
    }

    #[DataProvider('categoryCases')]
    public function testCommercialCategoryNeedsABuyingWord(string $keyword, string $seed, ?KeywordCategory $category, ?string $gap): void
    {
        self::expect($category === KeywordCategory::decide(null, $keyword, false, $seed), $gap, sprintf('« %s » pour « %s »', $keyword, $seed));
    }

    private static function expect(bool $ok, ?string $gap, string $case): void
    {
        if (null !== $gap) {
            self::assertFalse($ok, sprintf('Écart résolu (%s) : retirer sa mention du cas.', $case));
            self::markTestIncomplete($gap);
        }
        self::assertTrue($ok, $case);
    }
}
