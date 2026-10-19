<?php

namespace App\Command;

use App\Ai\KeywordCategory;
use App\Search\KeywordSearch;
use App\Suggest\Keyword;
use App\Suggest\SuggestionCollector;
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
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'Dossier où enregistrer chaque recherche pour la rejouer sans réseau');
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

        $rows = [];
        $passed = 0;
        foreach ($expressions as $expression) {
            $expression = (string) $expression;
            $expectation = $expectations[$expression] ?? null;
            $lang = $expectation->lang ?? (string) $input->getOption('lang');
            $started = microtime(true);
            $result = $this->search->search($expression, $lang);
            $advice = $result->getAdvice();
            $elapsed = microtime(true) - $started;
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
                BenchExpectation::intentOf($result) ?? '—',
                sprintf('%.1f s', $elapsed),
            ];
            if (null !== $expectation) {
                $check = $expectation->check($result);
                $passed += BenchExpectation::passes($check) ? 1 : 0;
                $row[] = implode(' / ', array_map(static fn ($t): string => $t->value, $expectation->verdicts));
                $row[] = implode(' ', array_map(static fn (string $name, ?bool $ok): string => match ($ok) {
                    true => '✔'.$name,
                    false => '✘'.$name,
                    null => '·'.$name,
                }, array_keys($check), $check));
            }
            $rows[] = $row;
        }
        $headers = ['Expression', 'Verdict', 'Volet', 'Demande', 'Concept', 'Profondeur', 'Achat', 'Thèmes / pages', 'Intention', 'Durée'];
        $io->table([] !== $expectations ? [...$headers, 'Attendu', 'Contrôle'] : $headers, $rows);
        if ([] !== $expectations) {
            $io->writeln(sprintf('%d / %d conformes aux attentes.', $passed, \count($rows)));
        }

        return Command::SUCCESS;
    }
}
