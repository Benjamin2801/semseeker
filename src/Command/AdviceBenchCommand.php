<?php

namespace App\Command;

use App\Ai\KeywordCategory;
use App\Search\KeywordSearch;
use App\Search\SearchResult;
use App\Suggest\Keyword;
use App\Suggest\SuggestionCollector;
use App\Suggest\SuggestionMerger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:advice:bench', description: 'Analyse des expressions et affiche le conseil de chacune.')]
final class AdviceBenchCommand extends Command
{
    // Juste, défendable, vu en seconde option ou dans la marge d'un seuil, faux, non mesuré.
    private const MARKS = ['right' => '✔', 'ok' => '✔', 'acceptable' => '≈', 'option' => '~', 'fragile' => '~', 'wrong' => '✘', 'skip' => '·'];

    public function __construct(
        private readonly KeywordSearch $search,
        private readonly SuggestionCollector $collector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('expressions', InputArgument::IS_ARRAY, 'Expressions à analyser (toutes celles du fichier d\'attentes si vide)')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Langue des suggestions', 'fr')
            ->addOption('core', null, InputOption::VALUE_NONE, 'Affiche aussi l\'article Wikipédia principal et les fondamentaux')
            ->addOption('expect', null, InputOption::VALUE_REQUIRED, 'Fichier d\'attentes (tests/Calibration/cases.json) : compare attendu et obtenu')
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'Dossier où enregistrer chaque recherche pour la rejouer sans réseau')
            ->addOption('replay', null, InputOption::VALUE_REQUIRED, 'Dossier de recherches enregistrées : rejoue sans réseau ni IA (avec --expect)')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Lance chaque recherche N fois et mesure ce qui varie d\'un passage à l\'autre', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $expectations = [];
        foreach (null !== $input->getOption('expect') ? BenchExpectation::load((string) $input->getOption('expect')) : [] as $expectation) {
            $expectations[$expectation->expression] = $expectation;
        }
        $expressions = (array) $input->getArgument('expressions');
        if ([] === $expressions) {
            $expressions = array_keys($expectations);
        }
        if ([] === $expressions) {
            $io->error('Aucune expression : en donner, ou un fichier d\'attentes.');

            return Command::INVALID;
        }
        $record = $input->getOption('record');
        $replay = $input->getOption('replay');
        $repeat = null !== $replay ? 1 : max(1, (int) $input->getOption('repeat'));

        $rows = [];
        $runs = [];
        foreach ($expressions as $expression) {
            $expression = (string) $expression;
            $expectation = $expectations[$expression] ?? null;
            $lang = $expectation->lang ?? (string) $input->getOption('lang');
            if (null !== $replay) {
                $path = rtrim((string) $replay, '/\\').'/'.($expectation?->slug() ?? '').'.json';
                if (null === $expectation || !is_file($path)) {
                    $io->warning(sprintf('« %s » : aucune recherche enregistrée.', $expression));
                    continue;
                }
                $passes = [[BenchFixture::replay(json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR)), null]];
            } else {
                $passes = [];
                for ($i = 0; $i < $repeat; ++$i) {
                    $started = microtime(true);
                    $passes[] = [$this->search->search($expression, $lang), microtime(true) - $started];
                }
            }
            [$result, $elapsed] = $passes[0];
            $advice = $result->getAdvice();
            if (null !== $record && null !== $expectation) {
                // Les suggestions sont en cache depuis la recherche : les relire ne coûte aucune requête.
                $fixture = BenchFixture::record($expression, $lang, $result, $this->collector->collect($expression, $lang, $result->concepts));
                file_put_contents(rtrim((string) $record, '/\\').'/'.$expectation->slug().'.json', json_encode($fixture, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
            }
            if ($input->getOption('core')) {
                $io->writeln(sprintf('<info>%s</info> · %s · plan %s', $expression, $result->wikipedia->articles[0]->title ?? 'sans Wikipédia', null !== $result->field ? 'oui' : 'non'));
                foreach (KeywordCategory::cases() as $category) {
                    $terms = array_filter([...$result->keywords, ...$result->excluded], static fn (Keyword $k): bool => $category === $k->category);
                    $io->writeln(sprintf('  %s : %s', $category->value, implode(', ', array_map(static fn (Keyword $k): string => $k->text.' ('.($k->isMeasured() ? $k->score : '≈'.$k->estimate.' '.$k->origin).')', $terms))));
                }
            }
            $row = [
                $expression,
                $advice->verdict->value.(null !== $advice->alternative ? ' ('.$advice->alternative->value.')' : ''),
                $advice->isKeyPage() ? ($advice->verdict->isCompetitive() ? 'mère compétitive' : 'mère') : ($advice->parent ?? '—'),
                $advice->demand,
                $advice->notoriety ?? '—',
                $advice->depth,
                $advice->orientation ?? '—',
                null !== $result->field ? \count($result->field->groups).' / '.$result->field->getPageCount() : '—',
                BenchExpectation::intentSummary($result),
                null !== $elapsed ? sprintf('%.1f s', $elapsed) : '—',
            ];
            if ($repeat > 1) {
                $row[] = self::stability(array_column($passes, 0));
            }
            if (null !== $expectation) {
                $check = $expectation->check($result);
                $row[] = implode(' / ', array_map(static fn ($t): string => $t->value, $expectation->verdicts));
                $row[] = implode(' ', array_map(static fn (string $name, string $status): string => self::MARKS[$status].$name, array_keys($check), $check));
                $field = $expectation->checkField($result);
                $row[] = null !== $field ? sprintf('%s bruit %d %%, couverture %d %%',
                    BenchExpectation::fieldPasses($field) ? (BenchExpectation::fieldIsFragile($field) ? '~' : '✔') : '✘',
                    round(100 * $field['noiseShare']), round(100 * $field['coverage'])) : '—';
                $runs[] = [$expectation, $result];
            }
            $rows[] = $row;
        }
        $headers = ['Expression', 'Verdict', 'Volet', 'Demande', 'Concept', 'Profondeur', 'Achat', 'Thèmes / pages', 'Intention', 'Durée'];
        if ($repeat > 1) {
            $headers[] = 'Stabilité';
        }
        $io->table([] !== $expectations ? [...$headers, 'Attendu', 'Contrôle', 'Champ'] : $headers, $rows);
        if ([] !== $runs) {
            $io->writeln('✔ juste · ≈ défendable · ~ fragile (seconde option ou marge d\'un seuil) · ✘ faux · · non mesuré');
            $io->success(BenchExpectation::formatScorecard(BenchExpectation::scorecard($runs)));
        }

        return Command::SUCCESS;
    }

    /**
     * Ce qui varie d'un passage à l'autre : le conseil, la famille d'intention du plan, les pages (recouvrement des
     * mots-clés principaux avec le premier passage).
     *
     * @param list<SearchResult> $results
     */
    private static function stability(array $results): string
    {
        $verdicts = array_map(static fn (SearchResult $r): string => $r->getAdvice()->verdict->value, $results);
        $business = array_map(static function (SearchResult $r): string {
            $shares = BenchExpectation::intentShares($r);

            return [] === $shares ? '—' : ($shares['commercial'] + $shares['transactional'] >= BenchExpectation::INTENT_SHARE[1] ? 'achat' : 'info');
        }, $results);
        $pages = array_map(static function (SearchResult $r): array {
            $titles = [];
            foreach ($r->field->groups ?? [] as $group) {
                foreach ($group->pages as $page) {
                    $titles[] = SuggestionMerger::key($page->main->text);
                }
            }

            return array_unique($titles);
        }, $results);
        $overlap = [];
        foreach (\array_slice($pages, 1) as $other) {
            $union = array_unique([...$pages[0], ...$other]);
            $overlap[] = [] !== $union ? \count(array_intersect($pages[0], $other)) / \count($union) : 1.0;
        }
        $same = static fn (array $values): string => \count(array_filter($values, static fn (string $v): bool => $v === $values[0])).'/'.\count($values);

        return sprintf('conseil %s · intention %s · pages %d %%', $same($verdicts), $same($business), round(100 * array_sum($overlap) / max(1, \count($overlap))));
    }
}
