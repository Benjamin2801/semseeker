<?php

namespace App\Tests\Calibration;

use App\Command\BenchExpectation;
use App\Command\BenchFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rejoue sans réseau les recherches enregistrées par le banc d'essai (`app:advice:bench --expect --record`) et
 * compare le conseil aux attentes de `cases.json`. Les réponses des moteurs, de Wikipédia et de Cohere sont figées :
 * seules les règles du projet (fusion, tri, signaux, conseil) sont recalculées.
 */
final class BenchReplayTest extends TestCase
{
    private const CASES = __DIR__.'/cases.json';
    private const FIXTURES = __DIR__.'/fixtures';

    /** @return iterable<string, array{BenchExpectation}> */
    public static function cases(): iterable
    {
        foreach (BenchExpectation::load(self::CASES) as $expectation) {
            if (is_file(self::FIXTURES.'/'.$expectation->slug().'.json')) {
                yield $expectation->expression => [$expectation];
            }
        }
    }

    #[DataProvider('cases')]
    public function testAdviceMatchesWhatAnSeoExpertExpects(BenchExpectation $expectation): void
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURES.'/'.$expectation->slug().'.json'), true, flags: \JSON_THROW_ON_ERROR);
        $result = BenchFixture::replay($fixture);
        $check = $expectation->check($result);
        $advice = $result->getAdvice();
        $obtained = sprintf('%s : %s, demande %d, intention %s (%s)', $expectation->expression, $advice->verdict->value, $advice->demand,
            BenchExpectation::intentOf($result) ?? '—', json_encode($check, \JSON_THROW_ON_ERROR));

        if (null !== $expectation->gap) {
            self::assertFalse(BenchExpectation::passes($check), 'Écart résolu : retirer « gap » de '.$obtained);
            self::markTestIncomplete($expectation->gap.' — '.$obtained);
        }
        self::assertTrue(BenchExpectation::passes($check), $obtained);
    }
}
