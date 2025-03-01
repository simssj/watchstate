<?php

declare(strict_types=1);

use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Plex\PlexClient;
use App\Commands\State\BackupCommand;
use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\State\PushCommand;
use App\Commands\System\IndexCommand;
use App\Commands\System\PruneCommand;
use App\Libs\Mappers\Import\MemoryMapper;
use Monolog\Logger;

return (function () {
    $config = [
        'name' => 'WatchState',
        'version' => '$(version_via_ci)',
        'tz' => env('WS_TZ', 'UTC'),
        'path' => fixPath(env('WS_DATA_PATH', fn() => env('IN_DOCKER') ? '/config' : realpath(__DIR__ . '/../var'))),
        'logs' => [
            'context' => (bool)env('WS_LOGS_CONTEXT', false),
            'prune' => [
                'after' => env('WS_LOGS_PRUNE_AFTER', '-3 DAYS'),
            ],
        ],
        'database' => [
            'version' => 'v01',
        ],
        'export' => [
            // -- Trigger full export mode if changes exceed X number.
            'threshold' => env('WS_EXPORT_THRESHOLD', 1000),
            // -- Extra margin for marking item not found for backend in export mode. Default 3 days.
            'not_found' => env('WS_EXPORT_NOT_FOUND', 259_200),
        ],
        'episodes' => [
            'disable' => [
                'guid' => (bool)env('WS_EPISODES_DISABLE_GUID', true),
            ]
        ],
        'ignore' => [],
        'trust' => [
            'proxy' => (bool)env('WS_TRUST_PROXY', false),
            'header' => (string)env('WS_TRUST_HEADER', 'X-Forwarded-For'),
        ],
    ];

    $logDateFormat = makeDate()->format('Ymd');

    $config['tmpDir'] = fixPath(env('WS_TMP_DIR', ag($config, 'path')));

    $config['database'] += [
        'dsn' => 'sqlite:' . ag($config, 'path') . '/db/watchstate_' . ag($config, 'database.version') . '.db',
        'username' => null,
        'password' => null,
        'options' => [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
        'exec' => [
            'PRAGMA journal_mode=MEMORY',
            'PRAGMA SYNCHRONOUS=OFF'
        ],
    ];

    $config['webhook'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/access.' . $logDateFormat . '.log',
        'debug' => (bool)env('WS_WEBHOOK_DEBUG', false),
        'tokenLength' => 16,
    ];

    $config['mapper'] = [
        'import' => [
            'type' => MemoryMapper::class,
            'opts' => [],
        ],
    ];

    $config['http'] = [
        'default' => [
            'options' => [
                'headers' => [
                    'User-Agent' => ag($config, 'name') . '/' . getAppVersion(),
                ],
                'timeout' => 300.0,
                'extra' => [
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ],
            ],
        ],
    ];

    $config['debug'] = [
        'profiler' => [
            'options' => [
                'save.handler' => 'file',
                'save.handler.file' => [
                    'filename' => ag($config, 'tmpDir') . '/profiler/run.' . makeDate()->format('Ymd_His') . '.json'
                ],
            ],
        ],
    ];

    $config['cache'] = [
        'enabled' => true !== (bool)env('WS_DISABLE_CACHE'),
        'prefix' => env('WS_CACHE_PREFIX', null),
        'url' => env('WS_CACHE_URL', 'redis://127.0.0.1:6379'),
        'path' => env('WS_CACHE_PATH', fn() => ag($config, 'tmpDir') . '/cache'),
    ];

    $config['logger'] = [
        'file' => [
            'type' => 'stream',
            'enabled' => (bool)env('WS_LOGGER_FILE_ENABLE', true),
            'level' => env('WS_LOGGER_FILE_LEVEL', Logger::ERROR),
            'filename' => ag($config, 'tmpDir') . '/logs/app.' . $logDateFormat . '.log',
        ],
        'stderr' => [
            'type' => 'stream',
            'enabled' => 'cli' !== PHP_SAPI,
            'level' => Logger::WARNING,
            'filename' => 'php://stderr',
        ],
        'console' => [
            'type' => 'console',
            'enabled' => 'cli' === PHP_SAPI,
            // -- controllable by -vvv flag -v for NOTICE -vv for INFO -vvv for DEBUG.
            'level' => Logger::WARNING,
        ],
        'syslog' => [
            'type' => 'syslog',
            'docker' => false,
            'facility' => env('WS_LOGGER_SYSLOG_FACILITY', LOG_USER),
            'enabled' => (bool)env('WS_LOGGER_SYSLOG_ENABLED', !env('IN_DOCKER')),
            'level' => env('WS_LOGGER_SYSLOG_LEVEL', Logger::ERROR),
            'name' => ag($config, 'name'),
        ],
    ];

    $config['supported'] = [
        'plex' => PlexClient::class,
        'emby' => EmbyClient::class,
        'jellyfin' => JellyfinClient::class,
    ];

    $config['servers'] = [];

    $config['php'] = [
        'ini' => [
            'disable_functions' => null,
            'display_errors' => 0,
            'error_log' => env('IN_DOCKER') ? '/proc/self/fd/2' : 'syslog',
            'syslog.ident' => 'php-fpm',
            'memory_limit' => '265M',
            'pcre.jit' => 1,
            'opcache.enable' => 1,
            'opcache.memory_consumption' => 128,
            'opcache.interned_strings_buffer' => 8,
            'opcache.max_accelerated_files' => 10000,
            'opcache.max_wasted_percentage' => 5,
            'expose_php' => 0,
            'date.timezone' => ag($config, 'tz', 'UTC'),
            'mbstring.http_input' => ag($config, 'charset', 'UTF-8'),
            'mbstring.http_output' => ag($config, 'charset', 'UTF-8'),
            'mbstring.internal_encoding' => ag($config, 'charset', 'UTF-8'),
        ],
        'fpm' => [
            'www' => [
                'pm' => 'dynamic',
                'pm.max_children' => 10,
                'pm.start_servers' => 1,
                'pm.min_spare_servers' => 1,
                'pm.max_spare_servers' => 3,
                'pm.max_requests' => 1000,
                'pm.status_path' => '/fpm_status',
                'ping.path' => '/fpm_ping',
                'catch_workers_output' => 'yes',
                'decorate_workers_output' => 'no',
            ],
        ],
    ];

    $config['tasks'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/task.' . $logDateFormat . '.log',
        'list' => [
            ImportCommand::TASK_NAME => [
                'command' => ImportCommand::ROUTE,
                'name' => ImportCommand::TASK_NAME,
                'info' => 'Import play state and metadata from backends.',
                'enabled' => (bool)env('WS_CRON_IMPORT', false),
                'timer' => (string)env('WS_CRON_IMPORT_AT', '0 */1 * * *'),
                'args' => env('WS_CRON_IMPORT_ARGS', '-v'),
            ],
            ExportCommand::TASK_NAME => [
                'command' => ExportCommand::ROUTE,
                'name' => ExportCommand::TASK_NAME,
                'info' => 'Export play state to backends.',
                'enabled' => (bool)env('WS_CRON_EXPORT', false),
                'timer' => (string)env('WS_CRON_EXPORT_AT', '30 */1 * * *'),
                'args' => env('WS_CRON_EXPORT_ARGS', '-v'),
            ],
            PushCommand::TASK_NAME => [
                'command' => PushCommand::ROUTE,
                'name' => PushCommand::TASK_NAME,
                'info' => 'Push Webhook play states to backends.',
                'enabled' => (bool)env('WS_CRON_PUSH', false),
                'timer' => (string)env('WS_CRON_PUSH_AT', '*/10 * * * *'),
                'args' => env('WS_CRON_PUSH_ARGS', '-v'),
            ],
            BackupCommand::TASK_NAME => [
                'command' => BackupCommand::ROUTE,
                'name' => BackupCommand::TASK_NAME,
                'info' => 'Backup backends play states.',
                'enabled' => (bool)env('WS_CRON_BACKUP', false),
                'timer' => (string)env('WS_CRON_BACKUP_AT', '0 6 */3 * *'),
                'args' => env('WS_CRON_BACKUP_ARGS', '-v'),
            ],
            PruneCommand::TASK_NAME => [
                'command' => PruneCommand::ROUTE,
                'name' => PruneCommand::TASK_NAME,
                'info' => 'Delete old logs and backups.',
                'enabled' => (bool)env('WS_CRON_PRUNE', true),
                'timer' => (string)env('WS_CRON_PRUNE_AT', '0 */12 * * *'),
                'args' => env('WS_CRON_PRUNE_ARGS', '-v'),
            ],
            IndexCommand::TASK_NAME => [
                'command' => IndexCommand::ROUTE,
                'name' => IndexCommand::TASK_NAME,
                'info' => 'Check database for optimal indexes.',
                'enabled' => (bool)env('WS_CRON_INDEXES', true),
                'timer' => (string)env('WS_CRON_INDEXES_AT', '0 3 * * 3'),
                'args' => env('WS_CRON_INDEXES_ARGS', '-v'),
            ],
        ],
    ];

    return $config;
})();
