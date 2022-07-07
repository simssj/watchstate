<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
final class UnifyCommand extends Command
{
    public const ROUTE = 'servers:unify';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Unify [backendType] webhook API key.')
            ->addOption('select-backends', 's', InputOption::VALUE_OPTIONAL, 'Select backends. comma , seperated.', '')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                sprintf(
                    'Backend type to unify. Expecting one of [%s]',
                    implode('|', array_keys(Config::get('supported', [])))
                ),
            )
            ->addOption('servers-filter', null, InputOption::VALUE_OPTIONAL, '[DEPRECATED] Select backends.', '');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
        }

        $type = strtolower((string)$input->getArgument('type'));

        if (!array_key_exists($type, Config::get('supported', []))) {
            $message = sprintf(
                '<error>Invalid type was given. Expecting one of [%s] but got \'%s\' instead.',
                implode('|', array_keys(Config::get('supported', []))),
                $type
            );

            $output->writeln($message);
            return self::FAILURE;
        }

        $selectBackends = (string)$input->getOption('select-backends');
        $serversFilter = (string)$input->getOption('servers-filter');

        if (!empty($serversFilter)) {
            $output->writeln(
                '<comment>The [--servers-filter] flag is deprecated and will be removed in v1.0. Use [--select-backends].</comment>'
            );
            if (empty($selectBackends)) {
                $selectBackends = $serversFilter;
            }
        }

        $selected = explode(',', $selectBackends);
        $selected = array_map('trim', $selected);
        $isCustom = !empty($selectBackends) && count($selected) >= 1;

        $list = $keys = [];

        foreach (Config::get('servers', []) as $backendName => $backend) {
            if (ag($backend, 'type') !== $type) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' backend, not of %s type. (type: %s).</comment>',
                        $backendName,
                        $type,
                        ag($backend, 'type')
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            if ($isCustom && !in_array($backendName, $selected, true)) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' as requested by [-s, --select-backends] filter.</comment>',
                        $backendName
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            $backend['name'] = $backendName;
            $backend['ref'] = "servers.{$backendName}";

            $list[$backendName] = $backend;

            if (null === ($apiToken = ag($backend, 'webhook.token', null))) {
                try {
                    $apiToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));
                } catch (Throwable $e) {
                    $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                    return self::FAILURE;
                }
            }

            $keys[$apiToken] = 1;
        }

        $count = count($list);

        if (0 === $count) {
            $message = sprintf(
                $isCustom ? '[-s, --select-backends] did not return any %s backends.' : 'No %s backends were found.',
                $type
            );
            $output->writeln(sprintf('<error>%s</error>', $message));
            return self::FAILURE;
        }

        if (1 === $count) {
            $output->writeln(sprintf('<info>We found only one %s backend, therefore, no need to unify.</info>', $type));
            return self::SUCCESS;
        }

        if (count($keys) <= 1) {
            $output->writeln(sprintf('<info>%s Webhook API keys is already unified.</info>', ucfirst($type)));
            return self::SUCCESS;
        }

        // -- check for server unique identifier before unifying.
        foreach ($list as $backendName => $backend) {
            $ref = ag($backend, 'ref');

            if (null !== Config::get("{$ref}.uuid", null)) {
                continue;
            }

            $output->writeln(sprintf('<error>ERROR %s: does not have backend unique id set.</error>', $backendName));
            $output->writeln('<comment>Please run this command to update backend info.</comment>');
            $output->writeln(sprintf(commandContext() . 'servers:manage \'%s\' ', $backendName));
            return self::FAILURE;
        }

        try {
            $apiToken = array_keys($keys ?? [])[0] ?? bin2hex(random_bytes(Config::get('webhook.tokenLength')));
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        foreach ($list as $backend) {
            $ref = ag($backend, 'ref');
            Config::save("{$ref}.webhook.token", $apiToken);
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        $output->writeln(sprintf('<comment>Unified the API key of %d %s backends.</comment>', count($list), $type));
        $output->writeln(sprintf('<info>%s global webhook API key is: %s</info>', ucfirst($type), $apiToken));
        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('supported', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
