<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\Storage\StorageInterface;
use Exception;
use PDO;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ListCommand extends Command
{
    private PDO $pdo;

    public function __construct(private StorageInterface $storage)
    {
        $this->pdo = $this->storage->getPdo();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('db:list')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit results to this number', 20)
            ->addOption(
                'via',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified server. This filter is not reliable. and changes based on last server query.'
            )
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Display output as [json, yaml, table]', 'table')
            ->addOption('series', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified series.')
            ->addOption('movie', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified movie.')
            ->addOption('parent', null, InputOption::VALUE_NONE, 'If set it will search parent GUIDs instead.')
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Select season number')
            ->addOption('episode', null, InputOption::VALUE_REQUIRED, 'Select episode number')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Select db record number')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'sort order by [id, updated]', 'updated')
            ->setDescription('List Database entries.');

        foreach (array_keys(Guid::SUPPORTED) as $guid) {
            $guid = afterLast($guid, 'guid_');
            $this->addOption(
                $guid,
                null,
                InputOption::VALUE_REQUIRED,
                'Search Using ' . ucfirst($guid) . ' id.'
            );
        }
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $table = new Table($output);
        $table->setHeaders(
            [
                'ID',
                'Type',
                'Via (Temp)',
                'Name',
                'Year',
                'Episode',
                'Date',
                'Watched',
                'WH Event'
            ]
        );

        $params = [
            'limit' => (int)$input->getOption('limit'),
        ];

        $where = [];

        $sql = "SELECT * FROM state ";

        if ($input->getOption('via')) {
            $where[] = "json_extract(meta,'$.via') = :via";
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('id')) {
            $where[] = "id = :id";
            $params['id'] = $input->getOption('id');
        }

        if ($input->getOption('series')) {
            $where[] = "json_extract(meta,'$.series') = :series";
            $params['series'] = $input->getOption('series');
        }

        if ($input->getOption('movie')) {
            $where[] = "json_extract(meta,'$.title') = :movie";
            $params['movie'] = $input->getOption('movie');
        }

        if (null !== $input->getOption('season')) {
            $where[] = "json_extract(meta,'$.season') = " . (int)$input->getOption('season');
        }

        if (null !== $input->getOption('episode')) {
            $where[] = "json_extract(meta,'$.episode') = " . (int)$input->getOption('episode');
        }

        if ($input->getOption('parent')) {
            foreach (array_keys(Guid::SUPPORTED) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "json_extract(meta,'$.parent.{$guid}') = :{$guid}";
                $params[$guid] = $val;
            }
        } else {
            foreach (array_keys(Guid::SUPPORTED) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "{$guid} LIKE '%' || :{$guid} || '%'";
                $params[$guid] = $val;
            }
        }

        if (count($where) >= 1) {
            $sql .= 'WHERE ' . implode(' AND ', $where);
        }

        $sort = $input->getOption('sort') === 'id' ? 'id' : 'updated';
        $sql .= " ORDER BY {$sort} DESC LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        $rowCount = count($rows);

        if (0 === $rowCount) {
            $output->writeln('<error>No Results. Probably invalid filters values were used.</error>');
            if (count($params) > 1) {
                array_shift($params);
                if ('json' === $input->getOption('output')) {
                    $output->writeln(
                        json_encode(
                            [
                                'Filters' => $params
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        )
                    );
                } else {
                    $output->writeln(Yaml::dump(['Filters' => $params], 8, 2));
                }
            }
            return self::FAILURE;
        }

        if ('json' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $row['watched'] = (bool)$row['watched'];
                $row['updated'] = makeDate($row['updated']);
                $row['meta'] = json_decode($row['meta'], true);
            }
            unset($row);

            $output->writeln(
                json_encode(
                    1 === count($rows) ? $rows[0] : $rows,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        } elseif ('yaml' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $row['watched'] = (bool)$row['watched'];
                $row['updated'] = makeDate($row['updated']);
                $row['meta'] = json_decode($row['meta'], true);
            }

            unset($row);
            $output->writeln(Yaml::dump(1 === count($rows) ? $rows[0] : $rows, 8, 2));
        } else {
            $x = 0;

            foreach ($rows as $row) {
                $x++;

                $type = strtolower($row['type'] ?? '??');

                $meta = json_decode(ag($row, 'meta', '{}'), true);
                $episode = null;

                if (StateInterface::TYPE_EPISODE === $type) {
                    $episode = sprintf(
                        '%sx%s',
                        str_pad((string)($meta['season'] ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($meta['episode'] ?? 0), 2, '0', STR_PAD_LEFT),
                    );
                }

                $list[] = [
                    $row['id'],
                    ucfirst($row['type'] ?? '??'),
                    $meta['via'] ?? '??',
                    $meta['series'] ?? $meta['title'] ?? '??',
                    $meta['year'] ?? '0000',
                    $episode ?? '-',
                    makeDate($row['updated']),
                    true === (bool)$row['watched'] ? 'Yes' : 'No',
                    $meta['webhook']['event'] ?? '-',
                ];

                if ($x < $rowCount) {
                    $list[] = new TableSeparator();
                }
            }

            $rows = null;

            $table->setStyle('box')->setRows($list)->render();
        }

        return self::SUCCESS;
    }
}
