<?php

declare(strict_types=1);

namespace Rpo\Command;

use Rpo\Auth\TokenRepository;
use Rpo\Config;
use Rpo\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpo:token:create', description: 'Creates an access token for a user')]
final class TokenCreateCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Who the token belongs to (name, company, e-mail…)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = trim((string) $input->getArgument('name'));
        if ($name === '') {
            $output->writeln('<error>Please enter a name</error>');

            return Command::INVALID;
        }

        $created = (new TokenRepository(Db::connect($this->config)))->create($name);

        $output->writeln("Token #{$created['id']} for <info>{$name}</info>:");
        $output->writeln('');
        $output->writeln("  {$created['token']}");
        $output->writeln('');
        $output->writeln('<comment>The token is shown only now; the DB stores only its hash.</comment>');

        return Command::SUCCESS;
    }
}
