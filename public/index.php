<?php

declare(strict_types=1);

use App\Command;

error_reporting(E_ALL);
ini_set('error_reporting', 'On');
ini_set('display_errors', 'Off');

require __DIR__ . '/../pre_init.php';

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    print 'Dependencies are missing please refer to https://github.com/ArabCoders/watchstate/blob/master/FAQ.md';
    exit(Command::FAILURE);
}

require __DIR__ . '/../vendor/autoload.php';

set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
    $errno = $number & error_reporting();
    if (0 === $errno) {
        return;
    }

    $message = trim(sprintf('%s: %s (%s:%d)', $number, $error, $file, $line));
    $out = fn($message) => env('IN_DOCKER') ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);
    $out($message);

    exit(Command::FAILURE);
});

set_exception_handler(function (Throwable $e) {
    $message = trim(sprintf("%s: %s (%s:%d).", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    $out = fn($message) => env('IN_DOCKER') ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);
    $out($message);
    exit(Command::FAILURE);
});

// -- In case the frontend proxy does not generate request unique id.
if (!isset($_SERVER['X_REQUEST_ID'])) {
    $_SERVER['X_REQUEST_ID'] = bin2hex(random_bytes(16));
}

(new App\Libs\Initializer())->boot()->runHttp();
