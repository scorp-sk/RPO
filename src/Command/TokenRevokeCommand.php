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

#[AsCommand(name: 'rpo:token:revoke', description: 'Revokes an access token')]
final class TokenRevokeCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Token number (from rpo:token:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('id');
        $tokens = new TokenRepository(Db::connect($this->config));

        if (!$tokens->exists($id)) {
            $output->writeln("<error>Token #{$id} does not exist</error>");

            return Command::FAILURE;
        }
        if (!$tokens->revoke($id)) {
            $output->writeln("<comment>Token #{$id} was already revoked</comment>");

            return Command::SUCCESS;
        }
        $output->writeln("<info>Token #{$id} revoked</info>");

        return Command::SUCCESS;
    }
}
