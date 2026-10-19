<?php

namespace App\Tests\Search;

use App\Ai\ContentPlannerInterface;
use App\Ai\Intent;
use App\Search\FieldPage;
use App\Search\SemanticField;
use App\Suggest\Keyword;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type PlanGroup from ContentPlannerInterface
 * @phpstan-import-type PlanPage from ContentPlannerInterface
 */
final class SemanticFieldTest extends TestCase
{
    public function testTheStrongestKeywordOfAPageIsItsMainKeyword(): void
    {
        $field = SemanticField::build([self::group('Portes de garage', [self::page('Installation de porte de garage', [1, 0], [
            ['Porte coulissante', [2]],
        ])])], self::keywords(['pose porte garage' => 20, 'installation porte de garage' => 48, 'porte garage coulissante' => 31]));

        $page = $field->groups[0]->pages[0];
        self::assertSame('installation porte de garage', $page->main->text);
        self::assertSame(['pose porte garage'], self::texts($page->keywords));
        self::assertSame('Porte coulissante', $page->sections[0]->title);
        self::assertSame(99, $page->getWeight());
    }

    public function testRemovesDuplicatesAndKeywordsThatWereNotKept(): void
    {
        $keywords = self::keywords(['a' => 50, 'b' => 40, 'c' => 30]);
        $field = SemanticField::build([
            self::group('G1', [self::page('P1', [0, 3], [['S1', [1, 0]]])]),
            self::group('G2', [self::page('P2', [1, 2], [])]),
        ], $keywords);

        self::assertSame(['a', 'b'], self::texts($field->groups[0]->pages[0]->getAllKeywords()));
        self::assertSame(['c'], self::texts($field->groups[1]->pages[0]->getAllKeywords()), '« b » reste dans sa première page.');
    }

    public function testAKeywordNamedLikeAPageGoesToThatPage(): void
    {
        $field = SemanticField::build([
            self::group('Fonctionnement', [self::page('comment fonctionne une pompe à chaleur', [0], [['Types', [1, 2]]])]),
            self::group('Types', [self::page('pompe à chaleur air-eau', [3], [])]),
        ], self::keywords(['comment fonctionne une pompe à chaleur' => 60, 'pompe à chaleur air eau' => 50, 'pompe à chaleur air air' => 30, 'pompe à chaleur air eau prix' => 20]));

        $pages = array_merge(...array_map(static fn ($g) => $g->pages, $field->groups));
        $byTitle = array_combine(array_map(static fn (FieldPage $p) => $p->title, $pages), $pages);
        self::assertSame(['comment fonctionne une pompe à chaleur', 'pompe à chaleur air air'], self::texts($byTitle['comment fonctionne une pompe à chaleur']->getAllKeywords()));
        self::assertSame('pompe à chaleur air eau', $byTitle['pompe à chaleur air-eau']->main->text, 'Le mot-clé quitte la section de l\'autre page pour celle qui porte son nom.');
    }

    public function testUsesTheBestSectionKeywordWhenThePageHasNone(): void
    {
        $field = SemanticField::build([self::group('G', [self::page('P', [], [['S', [0, 1]]])])], self::keywords(['fort' => 40, 'faible' => 10]));

        $page = $field->groups[0]->pages[0];
        self::assertSame('fort', $page->main->text);
        self::assertSame(['faible'], self::texts($page->sections[0]->keywords));
    }

    public function testDropsEmptyPagesAndGroupsAndSortsByWeight(): void
    {
        $field = SemanticField::build([
            self::group('Léger', [self::page('L', [0], [])]),
            self::group('Vide', [self::page('V', [9], [])]),
            self::group('Lourd', [self::page('Petite', [1], []), self::page('Grande', [2, 3], [])]),
        ], self::keywords(['l' => 10, 'p' => 20, 'g1' => 30, 'g2' => 30]));

        self::assertSame(['Lourd', 'Léger'], array_map(static fn ($g) => $g->name, $field->groups));
        self::assertSame(['Grande', 'Petite'], array_map(static fn (FieldPage $p) => $p->title, $field->groups[0]->pages));
        self::assertSame(3, $field->groups[0]->getKeywordCount());
    }

    public function testKeepsOnlyTheHeaviestGroupsAndDetailsTheirStrongestKeywords(): void
    {
        $field = SemanticField::build([
            self::group('Léger', [self::page('L', [0], [])]),
            self::group('Lourd', [self::page('Grande', [1], [['A', [2]], ['B', [3]]])]),
        ], self::keywords(['léger' => 10, 'fort' => 50, 'moyen' => 30, 'faible' => 20]), maxGroups: 1, maxKeywordsPerGroup: 1);

        $page = $field->groups[0]->pages[0];
        self::assertSame(['Lourd'], array_map(static fn ($g) => $g->name, $field->groups));
        self::assertSame(['A'], array_map(static fn ($s) => $s->title, $page->sections), 'Une section vidée par la limite disparaît.');
        self::assertSame(['faible'], self::texts($page->keywords), 'Son mot-clé reste dans la page, à inclure.');
        self::assertSame(['léger'], self::texts($field->leftovers), 'Les groupes écartés restent visibles.');
    }

    public function testWeighsTheIntentsOfAThemeByItsPages(): void
    {
        $field = SemanticField::build([self::group('G', [
            self::page('Recette', [0], [], Intent::Informational),
            self::page('Achat', [1], [], Intent::Transactional),
            self::page('Sans intention', [2], []),
        ])], self::keywords(['recette' => 30, 'acheter' => 10, 'autre' => 50]));

        self::assertSame(Intent::Informational, $field->groups[0]->pages[1]->intent);
        self::assertSame(['informational' => 75, 'transactional' => 25], $field->groups[0]->getIntentShares());
    }

    public function testAPageWhoseKeywordsTalkAboutPricesIsNotInformational(): void
    {
        $keywords = self::keywords(['porte automatique' => 40, 'porte automatique prix' => 20, 'porte automatique tarif' => 10, 'moteur somfy' => 30]);
        $field = SemanticField::build([self::group('G', [
            self::page('Porte automatique : fonctionnement et prix', [0, 1, 2], [], Intent::Informational),
            self::page('Moteur', [3], [], Intent::Transactional),
        ])], $keywords);

        $pages = $field->groups[0]->pages;
        self::assertSame(Intent::Commercial, $pages[0]->intent, 'Le titre « fonctionnement » ne l\x27emporte pas sur les mots-clés.');
        self::assertSame(Intent::Transactional, $pages[1]->intent, 'Une intention commerciale ou transactionnelle du modèle est gardée.');
    }

    public function testKeepsKeywordsTheAiDidNotPlace(): void
    {
        $field = SemanticField::build([self::group('G', [self::page('P', [0], [])])], self::keywords(['placé' => 30, 'oublié' => 20]));

        self::assertSame(['oublié'], self::texts($field->leftovers));
    }

    public function testFallsBackToTheMainKeywordWhenATitleIsEmpty(): void
    {
        $field = SemanticField::build([self::group('G', [self::page('', [0], [['', [1]]])])], self::keywords(['principal' => 30, 'section' => 20]));

        self::assertSame('principal', $field->groups[0]->pages[0]->title);
        self::assertSame('section', $field->groups[0]->pages[0]->sections[0]->title);
    }

    /**
     * @param list<PlanPage> $pages
     *
     * @return PlanGroup
     */
    private static function group(string $name, array $pages): array
    {
        return ['name' => $name, 'pages' => $pages];
    }

    /**
     * @param list<int>                      $keywords
     * @param list<array{string, list<int>}> $sections
     *
     * @return PlanPage
     */
    private static function page(string $title, array $keywords, array $sections, ?Intent $intent = null): array
    {
        return ['title' => $title, 'intent' => $intent, 'keywords' => $keywords, 'sections' => array_map(static fn (array $s) => ['title' => $s[0], 'keywords' => $s[1]], $sections)];
    }

    /**
     * @param array<string, int> $scores
     *
     * @return array<int, Keyword>
     */
    private static function keywords(array $scores): array
    {
        $keywords = [];
        foreach ($scores as $text => $score) {
            $keywords[] = new Keyword((string) $text, ['google' => 1], $score);
        }

        return $keywords;
    }

    /**
     * @param list<Keyword> $keywords
     *
     * @return list<string>
     */
    private static function texts(array $keywords): array
    {
        return array_map(static fn (Keyword $k) => $k->text, $keywords);
    }
}
