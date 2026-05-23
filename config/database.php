<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];
    private static bool $loggedSlow = false;

    public static function loadConfig(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }

        self::$config = [
            'driver'   => getenv('DB_DRIVER') ?: 'pgsql',
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('DB_PORT') ?: '5432',
            'dbname'   => getenv('DB_NAME') ?: 'superma_pos',
            'user'     => getenv('DB_USER') ?: 'superma_user',
            'password' => getenv('DB_PASSWORD') ?: '',
            'schema'   => getenv('DB_SCHEMA') ?: 'public',
        ];
    }

    public static function connect(): PDO
    {
        if (self::$instance === null) {
            self::loadConfig();

            $cfg = self::$config;
            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;options=\'--search_path=%s\'',
                $cfg['driver'],
                $cfg['host'],
                $cfg['port'],
                $cfg['dbname'],
                $cfg['schema']
            );

            try {
                $start = microtime(true);
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]);
                $duration = (microtime(true) - $start) * 1000;
                if ($duration > 100 && !self::$loggedSlow) {
                    self::$loggedSlow = true;
                    Logger::warning('Slow DB connection', ['duration_ms' => round($duration, 2)]);
                }
            } catch (\PDOException $e) {
                Logger::critical('Database connection failed', [
                    'host'   => $cfg['host'],
                    'dbname' => $cfg['dbname'],
                    'error'  => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return self::$instance;
    }

    public static function disconnect(): void
    {
        self::$instance = null;
    }
}
