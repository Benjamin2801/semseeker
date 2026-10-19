<?php

namespace App\Tests\Calibration;

use App\Command\BenchExpectation;
use App\Command\BenchFixture;
use App\Search\PageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

/**
 * Tests de comportement (méthode CheckList, Ribeiro et al., ACL 2020) sur les recherches enregistrées : au lieu
 * d'une réponse exacte, une relation entre deux réponses. Invariance : une variante sans changement de sens (casse,
 * accents, espaces) garde le même conseil. Direction : un mot qui annonce une intention sans ambiguïté (« devis »,
 * « meilleur », « comment », une ville) fait apparaître le type de page attendu parmi les options proposées.
 *
 * @phpstan-import-type Fixture from BenchFixture
 */
final class MetamorphicTest extends TestCase
{
    private const FIXTURES = __DIR__.'/fixtures';

    /** @return iterable<string, array{string, \Closure(string): string}> */
    public static function invariances(): iterable
    {
        $variants = [
            'majuscules' => static fn (string $s): string => mb_strtoupper($s),
            'sans accents' => static fn (string $s): string => u($s)->ascii()->toString(),
            'espaces' => static fn (string $s): string => '  '.str_replace(' ', '  ', $s).' ',
        ];
        foreach (BenchExpectation::load(__DIR__.'/cases.json') as $expectation) {
            foreach ($variants as $name => $variant) {
                yield $expectation->expression.' · '.$name => [$expectation->slug(), $variant];
            }
        }
    }

    /** @param \Closure(string): string $variant */
    #[DataProvider('invariances')]
    public function testAVariantWithTheSameMeaningKeepsTheSameAdvice(string $slug, \Closure $variant): void
    {
        $fixture = self::fixture($slug);
        $original = BenchFixture::replay($fixture)->getAdvice();
        $varied = BenchFixture::replay(['expression' => $variant($fixture['expression'])] + $fixture)->getAdvice();

        self::assertSame($original->verdict, $varied->verdict, sprintf('« %s » : %s devient %s', $variant($fixture['expression']), $original->verdict->value, $varied->verdict->value));
    }

    /** @return iterable<string, array{string, string, string, list<PageType>}> */
    public static function directions(): iterable
    {
        yield 'devis → service' => ['fr-isolation-thermique-maison', 'devis ', '', [PageType::Service]];
        yield 'meilleur → comparatif' => ['fr-canne-a-peche', 'meilleur ', '', [PageType::Comparison]];
        yield 'comment → tutoriel' => ['fr-voiture-electrique', 'comment recharger une ', '', [PageType::HowTo]];
        yield 'ville → locale' => ['fr-isolation-thermique-maison', '', ' lyon', [PageType::Local]];
        yield 'best → comparison' => ['en-plumber-near-me', 'best ', '', [PageType::Comparison]];
        // « prix » est à double sens : il ne décide que si les suggestions parlent aussi d'argent, comme pour la voiture.
        yield 'prix confirmé → commercial' => ['fr-voiture-electrique', 'prix ', '', [PageType::PillarCommercial, PageType::CommercialSection, PageType::MinorCommercialSection]];
    }

    /** @param list<PageType> $expected */
    #[DataProvider('directions')]
    public function testAWordThatAnnouncesAnIntentBringsItsPage(string $slug, string $before, string $after, array $expected): void
    {
        $fixture = self::fixture($slug);
        $seed = $before.$fixture['expression'].$after;
        $options = BenchFixture::replay(['expression' => $seed] + $fixture)->getAdvice()->getOptions();

        self::assertNotEmpty(array_intersect(array_map(static fn (PageType $t): string => $t->value, $options), array_map(static fn (PageType $t): string => $t->value, $expected)),
            sprintf('« %s » : %s', $seed, implode(' ou ', array_map(static fn (PageType $t): string => $t->value, $options))));
    }

    /** @return Fixture */
    private static function fixture(string $slug): array
    {
        $path = self::FIXTURES.'/'.$slug.'.json';
        if (!is_file($path)) {
            self::markTestSkipped('Recherche non enregistrée : '.$slug);
        }

        return json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
    }
}
