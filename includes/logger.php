<?php

declare(strict_types=1);

final class Logger
{
    private static ?string $logDir = null;
    private static ?string $pgLogDir = null;

    const LEVELS = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

    public static function init(): void
    {
        self::$logDir = __DIR__ . '/../logs';
        self::$pgLogDir = self::$logDir . '/postgresql';

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
        if (!is_dir(self::$pgLogDir)) {
            @mkdir(self::$pgLogDir, 0755, true);
        }
    }

    public static function write(string $level, string $message, ?array $context = null, string $channel = 'app'): void
    {
        self::init();

        $level = strtoupper($level);
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'INFO';
        }

        $timestamp = date('Y-m-d H:i:s') . '.' . substr(microtime(), 2, 3);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? $trace[0] ?? null;
        $file = $caller['file'] ?? 'unknown';
        $line = $caller['line'] ?? 0;
        $shortFile = basename($file);

        $contextStr = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $logLine = sprintf(
            "[%s] [%s] [%s:%d] %s%s\n",
            $timestamp,
            str_pad($level, 8),
            $shortFile,
            $line,
            $message,
            $contextStr
        );

        $filename = self::$logDir . '/' . $channel . '-' . date('Y-m-d') . '.log';
        @file_put_contents($filename, $logLine, FILE_APPEND | LOCK_EX);

        if ($level === 'ERROR' || $level === 'CRITICAL') {
            $errorFilename = self::$logDir . '/error-' . date('Y-m-d') . '.log';
            @file_put_contents($errorFilename, $logLine, FILE_APPEND | LOCK_EX);
        }
    }

    public static function debug(string $message, ?array $context = null, string $channel = 'app'): void
    {
        self::write('DEBUG', $message, $context, $channel);
    }

    public static function info(string $message, ?array $context = null, string $channel = 'app'): void
    {
        self::write('INFO', $message, $context, $channel);
    }

    public static function warning(string $message, ?array $context = null, string $channel = 'app'): void
    {
        self::write('WARNING', $message, $context, $channel);
    }

    public static function error(string $message, ?array $context = null, string $channel = 'app'): void
    {
        self::write('ERROR', $message, $context, $channel);
    }

    public static function critical(string $message, ?array $context = null, string $channel = 'app'): void
    {
        self::write('CRITICAL', $message, $context, $channel);
    }

    public static function exception(\Throwable $e, string $channel = 'app'): void
    {
        $context = [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
        self::write('ERROR', $e->getMessage(), $context, $channel);
    }

    public static function query(string $sql, ?array $params = null, ?float $duration = null, ?string $error = null): void
    {
        $context = [
            'sql'      => $sql,
            'params'   => $params,
            'duration' => $duration !== null ? round($duration, 4) . 's' : null,
        ];
        if ($error) {
            $context['error'] = $error;
            self::write('ERROR', 'DB query failed', $context, 'sql');
        } else {
            self::write('DEBUG', 'DB query', $context, 'sql');
        }
    }

    public static function getLogFiles(string $channel = 'app', string $date = null): array
    {
        self::init();
        $date = $date ?: date('Y-m-d');
        $path = self::$logDir . '/' . $channel . '-' . $date . '.log';
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(function ($line) {
            $parsed = [];
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\] \[(\s*\w+)\] \[([^:]+):(\d+)\] (.+)$/', $line, $m)) {
                $parsed = [
                    'timestamp' => $m[1],
                    'level'     => trim($m[2]),
                    'file'      => $m[3],
                    'line'      => (int)$m[4],
                    'message'   => $m[5],
                    'raw'       => $line,
                ];
            } else {
                $parsed = ['raw' => $line, 'timestamp' => '', 'level' => '', 'message' => $line];
            }
            return $parsed;
        }, $lines);
    }

    public static function getLogDates(string $channel = 'app'): array
    {
        self::init();
        $files = glob(self::$logDir . '/' . $channel . '-*.log');
        $dates = [];
        foreach ($files as $f) {
            if (preg_match('/' . $channel . '-(\d{4}-\d{2}-\d{2})\.log$/', $f, $m)) {
                $dates[] = $m[1];
            }
        }
        rsort($dates);
        return $dates;
    }

    public static function getChannels(): array
    {
        self::init();
        $files = glob(self::$logDir . '/*.log');
        $channels = [];
        foreach ($files as $f) {
            $basename = basename($f);
            if (preg_match('/^(.+?)-\d{4}-\d{2}-\d{2}\.log$/', $basename, $m)) {
                $channels[$m[1]] = true;
            }
        }
        return array_keys($channels);
    }

    public static function pgLogConfig(): string
    {
        self::init();

        $config = sprintf(
            "log_directory = '%s'\nlog_filename = 'postgresql-%%Y-%%m-%%d.log'\nlog_statement = 'all'\nlog_min_duration_statement = 1000\nlog_line_prefix = '%%t [%%p] [%%d] '\nlogging_collector = on\n",
            self::$pgLogDir
        );

        $confPath = self::$pgLogDir . '/postgresql-log.conf';
        @file_put_contents($confPath, $config);

        return $confPath;
    }
}
