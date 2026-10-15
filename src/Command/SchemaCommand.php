<?php

declare(strict_types=1);

namespace Rpo\Command;

use Rpo\Config;
use Rpo\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpo:schema', description: 'Creates the DB tables (sql/schema.sql)')]
final class SchemaCommand extends Command
{
    public function __construct(private readonly Config $config, private readonly string $schemaFile)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = Db::connect($this->config);
        $sql = (string) file_get_contents($this->schemaFile);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
        $output->writeln('<info>Schema is ready</info>');

        return Command::SUCCESS;
    }
}
