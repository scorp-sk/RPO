<?php

declare(strict_types=1);

namespace Rpo\Command;

use Rpo\Config;
use Rpo\Db;
use Rpo\Vat\VatImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpo:vat:sync', description: 'Downloads and imports the list of registered VAT payers (Financial Administration of the SR)')]
final class VatSyncCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Import the list even if its date is already imported')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print whether the list would be imported');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = fopen($this->config->varDir . '/vat-sync.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>VAT sync is already running</error>');

            return Command::FAILURE;
        }

        $importer = new VatImporter(Db::connect($this->config), $this->config->vatUrl, $this->config->varDir . '/tmp');
        $importer->setOutput(
            function (string $message) use ($output): void {
                $output->writeln(sprintf('[%s] %s', date('Y-m-d H:i:s'), $message));
            },
            function (int $records) use ($output): void {
                if ($records > 0 && $records % 50000 === 0) {
                    $output->writeln("    … {$records}", OutputInterface::VERBOSITY_VERBOSE);
                }
            },
        );
        $importer->sync((bool) $input->getOption('force'), (bool) $input->getOption('dry-run'));

        return Command::SUCCESS;
    }
}
