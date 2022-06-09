<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EnvCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:env')
            ->setDescription('Dump loaded environment variables.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $keys = [];

        foreach (getenv() as $key => $val) {
            if (false === str_starts_with($key, 'WS_')) {
                continue;
            }

            $keys[$key] = $val;
        }

        if ('table' === $mode) {
            $list = [];

            foreach ($keys as $key => $val) {
                $list[] = ['key' => $key, 'value' => $val];
            }

            $keys = $list;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }
}
