<?php

declare(strict_types=1);

namespace Rpo\Command;

use Rpo\Config;
use Rpo\Db;
use Rpo\Import\BucketClient;
use Rpo\Import\Importer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpo:sync', description: 'Downloads and imports new RPO batches (init + daily)')]
final class SyncCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('init', null, InputOption::VALUE_NONE, 'Force a re-import of the latest init batch')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Import a single daily file only (storage key or local path)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be imported');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = fopen($this->config->varDir . '/sync.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>Sync is already running</error>');

            return Command::FAILURE;
        }

        $pdo = Db::connect($this->config);
        $bucket = new BucketClient($this->config->bucketUrl);
        $importer = new Importer($pdo, $bucket, $this->config->varDir . '/tmp');

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

        if (($file = $input->getOption('file')) !== null) {
            return $this->importOne($importer, $bucket, (string) $file, (bool) $input->getOption('dry-run'), $output);
        }

        $plan = $importer->plan((bool) $input->getOption('init'));
        if ($input->getOption('dry-run')) {
            $output->writeln('Init: ' . ($plan['init'] === null ? "unchanged ({$plan['initDate']})" : "{$plan['init']['date']}, " . count($plan['init']['files']) . ' files'));
            foreach ($plan['daily'] as $object) {
                $output->writeln("Daily: {$object['key']}");
            }

            return Command::SUCCESS;
        }

        $importer->sync((bool) $input->getOption('init'));

        return Command::SUCCESS;
    }

    private function importOne(Importer $importer, BucketClient $bucket, string $file, bool $dryRun, OutputInterface $output): int
    {
        if ($dryRun) {
            $output->writeln("Would import file {$file}");

            return Command::SUCCESS;
        }
        if (is_file($file)) {
            $importer->importDailyFile($file, date('Y-m-d'));

            return Command::SUCCESS;
        }

        $objects = $bucket->list($file);
        if (!isset($objects[$file]) || !preg_match('/(\d{4}-\d{2}-\d{2})/', $file, $m)) {
            $output->writeln("<error>File {$file} not found in storage</error>");

            return Command::FAILURE;
        }
        $importer->runDaily($objects[$file] + ['date' => $m[1]]);

        return Command::SUCCESS;
    }
}
