<?php

namespace App\Command;

use App\Ai\KeywordCategory;
use App\Search\KeywordSearch;
use App\Suggest\Keyword;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('expressions', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Expressions à analyser')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Langue des suggestions', 'fr')
            ->addOption('core', null, InputOption::VALUE_NONE, 'Affiche aussi l\'article Wikipédia principal et les fondamentaux');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lang = (string) $input->getOption('lang');
        $rows = [];
        foreach ((array) $input->getArgument('expressions') as $expression) {
            $started = microtime(true);
            $result = $this->search->search((string) $expression, $lang);
            $advice = $result->getAdvice();
            $elapsed = microtime(true) - $started;
            if ($input->getOption('core')) {
                $io->writeln(sprintf('<info>%s</info> · %s · plan %s', $expression, $result->wikipedia->articles[0]->title ?? 'sans Wikipédia', null !== $result->field ? 'oui' : 'non'));
                foreach (KeywordCategory::cases() as $category) {
                    $terms = array_filter([...$result->keywords, ...$result->excluded], static fn (Keyword $k): bool => $category === $k->category);
                    $io->writeln(sprintf('  %s : %s', $category->value, implode(', ', array_map(static fn (Keyword $k): string => $k->text.' ('.($k->isMeasured() ? $k->score : '≈'.$k->estimate.' '.$k->origin).')', $terms))));
                }
            }
            $rows[] = [
                $expression,
                $advice->verdict->value,
                $advice->isKeyPage() ? ($advice->verdict->isCompetitive() ? 'mère compétitive' : 'mère') : ($advice->parent ?? '—'),
                $advice->demand,
                $advice->notoriety ?? '—',
                $advice->depth,
                $advice->orientation ?? '—',
                null !== $result->field ? \count($result->field->groups).' / '.$result->field->getPageCount() : '—',
                sprintf('%.1f s', $elapsed),
            ];
        }
        $io->table(['Expression', 'Verdict', 'Volet', 'Demande', 'Concept', 'Profondeur', 'Achat', 'Thèmes / pages', 'Durée'], $rows);

        return Command::SUCCESS;
    }
}
