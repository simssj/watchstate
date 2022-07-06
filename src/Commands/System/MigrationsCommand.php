<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class MigrationsCommand extends Command
{
    public const ROUTE = 'system:db:migrations';

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run database migrations.')
            ->addOption('fresh', 'f', InputOption::VALUE_NONE, 'Start migrations from start.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $opts = [];

        if ($input->getOption('fresh')) {
            $opts['fresh'] = true;
        }

        return $this->db->migrations(iDB::MIGRATE_UP, $opts);
    }
}
