<?php

namespace App\Tests\Calibration;

use App\Command\BenchExpectation;
use App\Command\BenchFixture;
use App\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rejoue sans réseau les recherches enregistrées par le banc d'essai (`app:advice:bench --expect --record`) et
 * compare le conseil et le plan aux attentes de `cases.json`. Les réponses des moteurs, de Wikipédia et de Cohere sont
 * figées : seules les règles du projet (fusion, tri, signaux, conseil) sont recalculées.
 *
 * Deux niveaux, comme pour tout système qui varie d'un passage à l'autre : chaque cas échoue seulement s'il est
 * clairement faux (un cas dans la marge d'un seuil est signalé « fragile »), et le tableau de bord échoue si la qualité
 * d'ensemble recule, même quand chaque cas reste dans sa marge.
 */
final class BenchReplayTest extends TestCase
{
    private const CASES = __DIR__.'/cases.json';
    private const FIXTURES = __DIR__.'/fixtures';

    // Planchers du tableau de bord : un peu sous la mesure actuelle, pour qu'un passage moyen ne casse rien.
    private const MIN_ADVICE_OK = 0.8;
    private const MAX_MEAN_NOISE = 0.05;
    private const MIN_MEAN_COVERAGE = 0.8;

    /** @return iterable<string, array{BenchExpectation}> */
    public static function cases(): iterable
    {
        foreach (BenchExpectation::load(self::CASES) as $expectation) {
            if (is_file(self::FIXTURES.'/'.$expectation->slug().'.json')) {
                yield $expectation->expression => [$expectation];
            }
        }
    }

    public function testEveryCaseHasItsFixture(): void
    {
        $missing = array_filter(BenchExpectation::load(self::CASES), static fn (BenchExpectation $e): bool => !is_file(self::FIXTURES.'/'.$e->slug().'.json'));

        self::assertSame([], array_map(static fn (BenchExpectation $e): string => $e->expression, array_values($missing)),
            'Expressions jamais rejouées : lancer app:advice:bench --expect --record.');
    }

    #[DataProvider('cases')]
    public function testAdviceMatchesWhatAnSeoExpertExpects(BenchExpectation $expectation): void
    {
        $result = self::replay($expectation);
        $check = $expectation->check($result);
        $advice = $result->getAdvice();
        $obtained = sprintf('%s : %s, demande %d, intentions %s (%s)', $expectation->expression,
            implode(' ou ', array_map(static fn ($t): string => $t->value, $advice->getOptions())), $advice->demand,
            BenchExpectation::intentSummary($result), json_encode($check, \JSON_THROW_ON_ERROR));

        self::judge(BenchExpectation::passes($check), BenchExpectation::isFragile($check), $expectation->gap, 'gap', $obtained);
    }

    #[DataProvider('cases')]
    public function testPlanIsRelevant(BenchExpectation $expectation): void
    {
        $field = $expectation->checkField(self::replay($expectation));
        if (null === $field) {
            self::markTestSkipped('Pas de plan enregistré pour '.$expectation->expression);
        }
        $obtained = sprintf('%s : bruit %d %% [%s], couverture %d %% (manque %s), doublons [%s], pages d\'un mot-clé %d %%',
            $expectation->expression, round(100 * $field['noiseShare']), implode(', ', $field['noise']), round(100 * $field['coverage']),
            implode(', ', $field['missing']) ?: '—', implode(', ', $field['duplicates']), round(100 * $field['singleShare']));

        self::judge(BenchExpectation::fieldPasses($field), BenchExpectation::fieldIsFragile($field), $expectation->fieldGap, 'fieldGap', $obtained);
    }

    // Tableau de bord : la qualité d'ensemble, que des variations d'un cas à l'autre ne doivent pas faire reculer.
    public function testOverallQualityDoesNotRegress(): void
    {
        $runs = [];
        foreach (self::cases() as [$expectation]) {
            $runs[] = [$expectation, self::replay($expectation)];
        }
        $card = BenchExpectation::scorecard($runs);
        $summary = BenchExpectation::formatScorecard($card);

        self::assertGreaterThanOrEqual(self::MIN_ADVICE_OK, $card['advice'], $summary);
        self::assertLessThanOrEqual(self::MAX_MEAN_NOISE, $card['noise'], $summary);
        self::assertGreaterThanOrEqual(self::MIN_MEAN_COVERAGE, $card['coverage'], $summary);
        self::assertSame([], $card['duplicated'], $summary);
    }

    private static function judge(bool $passes, bool $fragile, ?string $gap, string $field, string $obtained): void
    {
        if (null !== $gap) {
            self::assertFalse($passes, sprintf('Écart résolu : retirer « %s » de %s', $field, $obtained));
            self::markTestIncomplete($gap.' — '.$obtained);
        }
        self::assertTrue($passes, $obtained);
        if ($fragile) {
            self::markTestIncomplete('Fragile, dans la marge d\'un seuil : '.$obtained);
        }
    }

    private static function replay(BenchExpectation $expectation): SearchResult
    {
        return BenchFixture::replay(json_decode((string) file_get_contents(self::FIXTURES.'/'.$expectation->slug().'.json'), true, flags: \JSON_THROW_ON_ERROR));
    }
}
