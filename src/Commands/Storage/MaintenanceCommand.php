<?php

declare(strict_types=1);

namespace App\Commands\Storage;

use App\Command;
use App\Libs\Routable;
use App\Libs\Storage\StorageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class MaintenanceCommand extends Command
{
    public const ROUTE = 'storage:maintenance';

    public function __construct(private StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run maintenance tasks on storage.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->maintenance();

        return self::SUCCESS;
    }
}
