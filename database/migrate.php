<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/database.php';

$command = $argv[1] ?? 'up';

try {
    $status = app_migration_status($conn);

    if ($command === 'status') {
        $pending = is_array($status['pending'] ?? null) ? $status['pending'] : [];
        $drifted = is_array($status['drifted'] ?? null) ? $status['drifted'] : [];

        echo "Pending migrations: " . count($pending) . PHP_EOL;
        foreach ($pending as $migration) {
            echo "  - {$migration}" . PHP_EOL;
        }

        echo "Drifted migrations: " . count($drifted) . PHP_EOL;
        foreach ($drifted as $migration) {
            echo "  - {$migration}" . PHP_EOL;
        }

        exit(($pending === [] && $drifted === []) ? 0 : 1);
    }

    if ($command !== 'up') {
        throw new InvalidArgumentException('Usage: php database/migrate.php [status|up]');
    }

    if (!empty($status['drifted'])) {
        throw new RuntimeException(
            'Migration checksum mismatch detected for: ' . implode(', ', (array) $status['drifted'])
        );
    }

    $applied = app_apply_pending_migrations($conn);
    if ($applied === []) {
        echo "No pending migrations." . PHP_EOL;
        exit(0);
    }

    echo "Applied migrations:" . PHP_EOL;
    foreach ($applied as $migration) {
        echo "  - {$migration}" . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
