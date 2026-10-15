<?php

declare(strict_types=1);

namespace Rpo\Command;

use Rpo\Auth\TokenRepository;
use Rpo\Config;
use Rpo\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpo:token:usage', description: 'Shows a token\'s request count per day')]
final class TokenUsageCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Token number (from rpo:token:list)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('id');
        $tokens = new TokenRepository(Db::connect($this->config));
        if (!$tokens->exists($id)) {
            $output->writeln("<error>Token #{$id} does not exist</error>");

            return Command::FAILURE;
        }

        $usage = $tokens->usage($id, max(1, (int) $input->getOption('days')));
        $rows = array_map(fn ($day, $requests) => [$day, $requests], array_keys($usage), $usage);
        $rows[] = ['Total', array_sum($usage)];

        (new Table($output))->setHeaders(['Day', 'Requests'])->setRows($rows)->render();

        return Command::SUCCESS;
    }
}
