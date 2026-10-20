<?php

namespace App\Tests\Calibration;

use App\Ai\Intent;
use App\Ai\KeywordCategory;
use App\Search\Advice;
use App\Search\Demand;
use App\Search\DemandEstimate;
use App\Search\KeywordFilter;
use App\Search\LexicalContext;
use App\Search\PageSignals;
use App\Search\PageType;
use App\Search\SearchResult;
use App\Suggest\Keyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calibrage des règles sans IA (filtre, indices, garde-fou d'intention, catégorie commerciale) : ce qu'un référenceur
 * attend, cas par cas. Un écart connu porte sa raison et compte
 * comme test incomplet ; s'il disparaît, le test échoue pour qu'on retire la mention.
 */
final class LexicalTrapsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: bool, 4: ?string, 5?: list<string>}> */
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
        yield 'pâtisserie' => ['paris brest', 'pâtisserie', 'fr', false, null, ['Paris-Brest']];
        yield 'histoire' => ['traité de versailles', 'première guerre mondiale', 'fr', false, null];
        yield 'chimie' => ['valence chimie', 'électron', 'fr', false, null];
        yield 'race de chien' => ['boston terrier', 'terrier', 'en', false, null];
        yield 'recette' => ['new york cheesecake', 'cheesecake', 'en', false, null];
        yield 'titre' => ['20000 lieues sous les mers', 'jules verne', 'fr', false, 'Un nombre à cinq chiffres n\'est pas toujours un code postal'];
        yield 'ville composée, sans accent ni trait d\'union' => ['plombier Saint-Étienne', 'plombier', 'fr', true, null];
        yield 'proximité' => ['plombier près de chez moi', 'plombier', 'fr', true, null];
        yield 'arrondissement' => ['plombier 15e arrondissement', 'plombier', 'fr', true, null];
        yield 'service client' => ['assurance auto service client', 'assurance auto', 'fr', true, null];
        yield 'ville anglaise' => ['plumber new york', 'plumber', 'en', true, null];
        yield 'mot ordinaire' => ['plombier tarif', 'plombier', 'fr', false, null];
        yield 'mot qui a aussi un sens de produit' => ['lentilles de contact', 'lentille', 'fr', false, null];
        yield 'ville prise dans un mot' => ['pantalon parisien', 'pantalon', 'fr', false, null];
        yield 'villes d\'une autre langue' => ['pizza paris', 'pizza', 'en', false, null];
        yield 'règle que l\'expression vérifie déjà' => ['plombier paris pas cher', 'plombier paris', 'fr', false, null];
    }

    /** @param list<string> $names titres Wikipédia que la recherche trouverait */
    #[DataProvider('filterCases')]
    public function testFilterSetsAsideOnlyPlacesAndBusinesses(string $keyword, string $seed, string $lang, bool $excluded, ?string $gap, array $names = []): void
    {
        self::expect($excluded === (new KeywordFilter())->excludes($keyword, $seed, $lang, LexicalContext::of($seed, $names)), $gap, sprintf('« %s » pour « %s »', $keyword, $seed));
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
        yield 'aide soignante' => ['aide soignante', 'fr', [PageType::Job, PageType::Pillar], null];
        yield 'prix littéraire' => ['prix goncourt', 'fr', [PageType::Pillar, PageType::News], null];
        yield 'test médical' => ['test de grossesse', 'fr', [PageType::Pillar, PageType::PillarCommercial, PageType::HowTo], null];
        yield 'territoire' => ['nouvelle calédonie', 'fr', [PageType::Pillar], null];
        yield 'date' => ['date de péremption', 'fr', [PageType::Pillar, PageType::Definition], null];
        yield 'vêtement' => ['crop top', 'fr', [PageType::Pillar, PageType::PillarCommercial, PageType::Category], null];
        yield 'formation' => ['formation excel', 'fr', [PageType::Pillar, PageType::PillarCommercial, PageType::Service, PageType::Category], null];
        yield 'citations' => ['quotes about love', 'en', [PageType::Pillar], null];
        yield 'revue de littérature' => ['literature review', 'en', [PageType::Pillar, PageType::HowTo, PageType::Definition], null];
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

    // L'autre sens du doute : un « prix » que d'autres mots de prix confirment (tarif, coût) décide toujours.
    public function testAmbiguousCueConfirmedByTheSuggestionsStillDecides(): void
    {
        $merged = array_map(static fn (string $t): Keyword => new Keyword($t, ['google' => 0], 50), ['cout isolation combles perdus', 'isolation combles perdus tarif', 'isolation combles perdus devis', 'isolation combles perdus laine']);
        $confirmed = PageSignals::from('isolation combles perdus prix', 'fr', $merged, [], [], new KeywordFilter(), 0);
        $alone = PageSignals::from('isolation combles perdus prix', 'fr', [], [], [], new KeywordFilter(), 0);

        self::assertContains('price', $confirmed->seedCues);
        self::assertNotContains('price', $alone->seedCues, 'Sans suggestion pour le confirmer, « prix » reste un doute.');
    }

    /** @return iterable<string, array{0: list<string>, 1: ?Intent, 2: ?string, 3?: list<string>}> */
    public static function intentCases(): iterable
    {
        yield 'prix' => [['canne à pêche prix', 'canne à pêche carpe', 'canne à pêche télescopique'], Intent::Commercial, null];
        yield 'achat' => [['acheter canne à pêche', 'canne à pêche pas cher', 'canne à pêche mer'], Intent::Transactional, null];
        yield 'recette' => [['tajine poulet', 'tajine poulet olives', 'tajine poulet citron'], null, null];
        yield 'durée' => [['combien de temps cuire un oeuf', 'oeuf mollet', 'oeuf poché'], null, '« combien de temps » n\'est pas une question de prix'];
        yield 'citation' => [['quote of the day', 'love quote', 'inspirational quote'], null, '« quote » y est une citation'];
        yield 'opérations' => [['order of operations', 'pemdas', 'order of operations worksheet'], null, null, ['Order of operations']];
        yield 'prix nobel' => [['prix nobel de la paix', 'prix nobel 2026', 'lauréats'], null, null, ['Prix Nobel']];
        yield 'prix et tarif' => [['porte de garage automatique', 'porte de garage automatique prix', 'porte de garage automatique tarif'], Intent::Commercial, null];
        yield 'un tiers suffit' => [['four a pizza', 'four à pizza électrique', 'meilleur four à pizza'], Intent::Commercial, null];
        yield 'achat avant prix' => [['porte de garage devis', 'porte de garage prix'], Intent::Transactional, null];
        yield 'en anglais' => [['garage door', 'garage door cost', 'best garage door'], Intent::Commercial, null];
        yield 'un mot isolé ne décide pas' => [['pâte à pizza', 'pâte à pizza rapide', 'pâte à pizza thermomix', 'pâte à pizza prix'], null, null];
        yield '« installer » est une question' => [['comment installer une porte de garage', 'installer porte de garage'], null, null];
        yield 'mot pris dans un autre' => [['caprice des dieux'], null, null];
        yield 'aucun mot-clé' => [[], null, null];
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $names
     */
    #[DataProvider('intentCases')]
    public function testIntentGuardReadsBuyingWordsOnly(array $keywords, ?Intent $intent, ?string $gap, array $names = []): void
    {
        self::expect($intent === Intent::fromKeywords($keywords, LexicalContext::of('', $names)), $gap, implode(', ', $keywords));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: ?KeywordCategory, 3: ?string, 4?: list<string>}> */
    public static function categoryCases(): iterable
    {
        yield 'prix' => ['isolation combles prix', 'isolation combles', KeywordCategory::Commercial, null];
        yield 'enseigne' => ['canne à pêche decathlon', 'canne à pêche', KeywordCategory::Commercial, null];
        yield 'mot de l\'expression' => ['meilleur aspirateur balai sans fil', 'meilleur aspirateur balai', null, null];
        yield 'prix littéraire' => ['prix goncourt 2026', 'goncourt', null, null, ['Prix Goncourt']];
        yield 'durée' => ['combien de temps cuire un oeuf', 'oeuf', null, '« combien » n\'y parle pas d\'argent'];
        yield 'électricité' => ['circuit électrique', 'électricité', null, null, ['Circuit électrique']];
        yield 'offre en ligne' => ['cours de piano en ligne', 'cours de piano', KeywordCategory::Commercial, null];
        yield 'devis' => ['devis pompe à chaleur', '', KeywordCategory::Commercial, null];
        yield 'anglais' => ['heat pump cost', '', KeywordCategory::Commercial, null];
        yield '« installer » est une question' => ['comment installer une pompe à chaleur', '', null, null];
        yield 'mot commercial ajouté à l\'expression' => ['meilleur grille pain prix', 'meilleur grille pain', KeywordCategory::Commercial, null];
    }

    /** @param list<string> $names */
    #[DataProvider('categoryCases')]
    public function testCommercialCategoryNeedsABuyingWord(string $keyword, string $seed, ?KeywordCategory $category, ?string $gap, array $names = []): void
    {
        self::expect($category === KeywordCategory::decide(null, $keyword, false, $seed, LexicalContext::of($seed, $names)), $gap, sprintf('« %s » pour « %s »', $keyword, $seed));
    }

    // Hors mots d'achat, la catégorie reste celle du modèle ; un terme écarté est toujours à vérifier.
    public function testOtherwiseTheModelDecidesAndARejectedKeywordIsToBeChecked(): void
    {
        self::assertSame(KeywordCategory::Complementary, KeywordCategory::decide(KeywordCategory::Complementary, 'comment installer une pompe à chaleur', false));
        self::assertSame(KeywordCategory::Core, KeywordCategory::decide(KeywordCategory::Core, 'meilleur grille pain 4 tranches', false, 'meilleur grille pain'));
        self::assertSame(KeywordCategory::Doubtful, KeywordCategory::decide(KeywordCategory::Core, 'pompe à chaleur', true));
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
