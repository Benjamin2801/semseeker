<?php

namespace App\Tests\Search;

use App\Ai\KeywordCategory;
use App\Search\Advice;
use App\Search\Demand;
use App\Search\DemandEstimate;
use App\Search\KeywordFilter;
use App\Search\PageSignals;
use App\Search\PageType;
use App\Search\SearchResult;
use App\Suggest\Keyword;
use App\Suggest\RawSuggestion;
use App\Wikipedia\WikipediaArticle;
use App\Wikipedia\WikipediaExploration;
use PHPUnit\Framework\TestCase;

final class AdviceTest extends TestCase
{
    public function testReadsTheCuesTheLocalQueriesAndTheFillOfEachEngine(): void
    {
        $merged = array_map(static fn (string $t): Keyword => new Keyword($t, ['google' => 1], 50), [
            'pompe à chaleur prix', 'prix pompe à chaleur air eau', 'pompe à chaleur avis', 'pompe à chaleur lyon', 'pompe à chaleur 2026',
            'meilleure pompe à chaleur 2026',
        ]);
        $raw = [new RawSuggestion('pompe à chaleur prix', 'amazon', 2, true), new RawSuggestion('pompe à chaleur', 'indeed', 0, false)];

        $signals = PageSignals::from('pompe à chaleur', 'fr', $merged, $raw, ['amazon', 'indeed'], new KeywordFilter(), 4);

        self::assertSame(5, $signals->total, 'La suggestion lyonnaise est locale.');
        self::assertSame(1, $signals->local);
        self::assertSame(['comparison' => 1, 'price' => 2, 'review' => 1], array_filter($signals->cues), 'Une année seule n\'est pas une actualité.');
        self::assertSame(['amazon' => 0.3, 'indeed' => 0.0], $signals->fills, 'Indeed n\'a rien suggéré pour l\'expression seule.');
        self::assertFalse($signals->question);
        self::assertSame(2, $signals->words, '« pompe » et « chaleur » ; « à » est un mot outil.');
        self::assertSame('', $signals->parent, 'Une expression de tête n\'a pas de sujet parent.');
    }

    public function testFindsTheParentTopicAnywhereInThePhrase(): void
    {
        self::assertSame('pompe à chaleur', self::parent('prix installation pompe à chaleur', ['Pompe à chaleur']), 'Un article du sujet retrouvé dans l\'expression.');
        self::assertSame('chaudière gaz', self::parent('entretien chaudière gaz', ['Chaudière']), 'Un article d\'un seul mot, tête du sujet.');
        self::assertSame('', self::parent('pâte à pizza sans levure', ['Pizza']), 'Pas « pizza », précédé d\'autre chose qu\'une action : l\'IA tranchera.');
        self::assertSame('', self::parent('chino homme beige', ['Beige']), 'Pas l\'article du dernier mot, un qualificatif.');
        self::assertSame('panneau solaire', self::parent('installation panneau solaire'), 'Sans ce qu\'on fait du sujet.');
        self::assertSame('toiture', self::parent('devis toiture'));
        self::assertSame('', self::parent('carte grise délai'), 'Sans règle sûre, le parent revient à l\'IA.');
        self::assertSame('pâte à pizza', self::parent('comment faire une pâte à pizza', ['Faire']), 'Un article qui ne nomme qu\'une action ne compte pas.');
        self::assertSame('crêpe', self::parent('recette crêpe facile'), 'Sans qualificatif ni mot de tutoriel, comme « comment faire ».');
    }

    public function testChoosesThePageTypeTheSuggestionsPointTo(): void
    {
        $advice = Advice::of(self::searchResult(0.8, new PageSignals(20, ['price' => 8, 'review' => 6], 0, ['amazon' => 0.1])));

        self::assertSame(PageType::CommercialSection, $advice->verdict, '8 suggestions sur 20 parlent de prix : une section commerciale majeure.');
        self::assertNull($advice->parent, 'Sans sujet parent déterminé, pas de volet.');
    }

    public function testMakesABroadSubjectTurnedTowardsBuyingACommercialCompleteGuide(): void
    {
        $signals = new PageSignals(20, ['definition' => 1, 'price' => 1, 'review' => 1], 0, [], types: 20);
        $buying = array_map(static fn (string $t): Keyword => (new Keyword($t, ['google' => 1], 40))->withCategory(KeywordCategory::Commercial), ['a', 'b', 'c']);

        self::assertSame(PageType::PillarCommercial, Advice::of(self::searchResult(1.0, $signals, $buying))->verdict);
        self::assertSame(PageType::Pillar, Advice::of(self::searchResult(1.0, $signals))->verdict, 'Sans intention d\x27achat : guide complet.');
    }

    public function testTurnsObligationsIntoASectionOfTheirParent(): void
    {
        $advice = Advice::of(self::searchResult(0.6, new PageSignals(20, [], 0, [], seedCues: ['regulation'])));

        self::assertSame(PageType::RegulationSection, $advice->verdict, '« facture électronique obligatoire » : une section de la page parente.');
        self::assertTrue(PageType::Pillar->isMother());
        self::assertFalse(PageType::Pillar->isCompetitive(), 'Un guide complet n\x27est pas tourné vers l\x27achat.');
        self::assertTrue(PageType::Service->isCompetitive());
        self::assertFalse(PageType::Definition->isMother(), 'Une définition se rattache à une page mère.');
    }

    public function testFollowsTheIntentThePhraseCarriesItself(): void
    {
        $advice = Advice::of(self::searchResult(0.8, new PageSignals(20, ['price' => 8], 0, [], seedCues: ['service'])));

        self::assertSame(PageType::Service, $advice->verdict, '« devis toiture » : la page service l\'emporte sur les suggestions de prix.');
        self::assertFalse($advice->variant);
    }

    public function testSendsAProductFamilyToACategoryPageOnlyWhenTheFieldIsAboutBuying(): void
    {
        $marketplaces = new PageSignals(20, [], 0, ['amazon' => 1.0, 'ebay' => 1.0]);
        $buying = array_map(static fn (string $t): Keyword => (new Keyword($t, ['google' => 1], 40))->withCategory(KeywordCategory::Commercial), ['a', 'b', 'c']);

        self::assertSame(PageType::Category, Advice::of(self::searchResult(0.9, $marketplaces, $buying))->verdict);
        self::assertNotSame(PageType::Category, Advice::of(self::searchResult(0.9, $marketplaces))->verdict, 'Amazon remplit sa liste pour presque tout nom commun.');
    }

    public function testSendsAVariantOfABroaderTopicToASectionOfItsPage(): void
    {
        $variant = Advice::of(self::searchResult(0.6, new PageSignals(40, [], 0, [], words: 3, parent: 'pompe à chaleur')));

        self::assertSame(PageType::MajorSection, $variant->verdict, '« pompe à chaleur bruit » : une section de la page « pompe à chaleur ».');
        self::assertSame('pompe à chaleur', $variant->parent);
        self::assertTrue($variant->variant);
        self::assertSame(PageType::Mention, Advice::of(self::searchResult(0.3, new PageSignals(40, [], 0, [], words: 3, parent: 'chino homme')))->verdict);

        $ownIntent = Advice::of(self::searchResult(0.6, new PageSignals(40, [], 0, [], seedCues: ['price'], words: 3, parent: 'prix carte')));
        self::assertSame(PageType::CommercialSection, $ownIntent->verdict, '« prix carte grise » : une section commerciale de la page « carte grise ».');
        self::assertSame('prix carte', $ownIntent->parent, 'Une page se rattache aussi à son sujet parent.');
    }

    public function testKeepsTheLocalPageForMostlyLocalSearches(): void
    {
        $service = Advice::of(self::searchResult(0.8, new PageSignals(75, ['service' => 10], 25, [])));
        self::assertSame([PageType::Service, PageType::Local], $service->getOptions(), 'La page locale, proche, reste proposée en second.');
        self::assertSame(PageType::Local, Advice::of(self::searchResult(0.8, new PageSignals(50, ['service' => 10], 50, [])))->verdict, 'La moitié : une page locale.');
    }

    public function testCountsTheNotorietyOfAnArticleForThePartOfThePhraseItCovers(): void
    {
        $of = static fn (float $coverage): ?int => Advice::of(new SearchResult([], 0, [], 8, null, null,
            wikipedia: new WikipediaExploration([new WikipediaArticle('Musique', 'fr', 50000, coverage: $coverage)]),
            signals: new PageSignals(10, [], 0, [])))->notoriety;

        self::assertSame(100, $of(1.0));
        self::assertSame(27, $of(0.27), '« Musique » ne couvre qu\'un quart de « séminaire d\'entreprise musique ».');
    }

    public function testOnlyCallsAPageImportantWhenItIsSearched(): void
    {
        $quote = new PageSignals(40, [], 0, [], seedCues: ['service'], words: 3, parent: 'maison écologique');

        self::assertFalse(Advice::of(self::searchResult(0.1, $quote))->isKeyPage(), 'Peu recherchée, elle se rattache à sa page parente.');
        self::assertTrue(Advice::of(self::searchResult(0.8, $quote))->isKeyPage());
    }

    public function testKeepsAThinPhraseForASectionOrAMention(): void
    {
        self::assertSame(PageType::MajorSection, Advice::of(self::searchResult(0.2, new PageSignals(5, [], 0, [])))->verdict);
        self::assertSame(PageType::Mention, Advice::of(self::searchResult(0.1, new PageSignals(2, [], 0, [])))->verdict);
        self::assertSame(PageType::FaqQuestion, Advice::of(self::searchResult(0.3, new PageSignals(3, [], 0, [], question: true)))->verdict);
    }

    /** @param list<string> $topics */
    private static function parent(string $seed, array $topics = []): string
    {
        return PageSignals::from($seed, 'fr', [], [], [], new KeywordFilter(), 0, $topics)->parent;
    }

    /** @param list<Keyword> $keywords */
    private static function searchResult(float $fill, PageSignals $signals, array $keywords = []): SearchResult
    {
        return new SearchResult($keywords, 0, [], 8, new DemandEstimate(Demand::fromFill($fill), $fill, 3), null, signals: $signals);
    }
}
