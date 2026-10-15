<?php

declare(strict_types=1);

namespace Rpo\Command;

use Rpo\Auth\TokenRepository;
use Rpo\Config;
use Rpo\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpo:token:list', description: 'Lists access tokens with usage statistics')]
final class TokenListCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ((new TokenRepository(Db::connect($this->config)))->all() as $t) {
            $rows[] = [
                $t['id'],
                $t['name'],
                $t['token_hint'] . '…',
                $t['revoked_at'] === null ? '<info>active</info>' : "<comment>revoked {$t['revoked_at']}</comment>",
                $t['created_at'],
                $t['last_used_at'] ?? '–',
                $t['today'],
                $t['last30'],
                $t['total'],
            ];
        }

        (new Table($output))
            ->setHeaders(['#', 'Name', 'Token', 'Status', 'Created', 'Last used', 'Today', '30 days', 'Total'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
