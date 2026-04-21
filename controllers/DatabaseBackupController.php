<?php
declare(strict_types=1);

final class DatabaseBackupController
{
    private const TABLES = [
        'users',
        'categories',
        'suppliers',
        'subcategories',
        'pos_config',
        'products',
        'sales',
        'sale_items',
        'stock_in',
        'stock_out',
        'stock_audit_log',
        'activity_logs',
        'notifications',
        'notification_seen',
        'remember_tokens',
        'login_attempts',
        'shift_closings',
    ];

    private const AUTO_INCREMENT_COLUMNS = [
        'users'            => 'user_id',
        'categories'       => 'category_id',
        'suppliers'        => 'supplier_id',
        'subcategories'    => 'subcategory_id',
        'pos_config'       => 'config_id',
        'products'         => 'product_id',
        'sales'            => 'sale_id',
        'sale_items'       => 'sale_item_id',
        'stock_in'         => 'stockin_id',
        'stock_out'        => 'stockout_id',
        'stock_audit_log'  => 'log_id',
        'activity_logs'    => 'id',
        'notifications'    => 'notification_id',
        'remember_tokens'  => 'id',
        'login_attempts'   => 'attempt_id',
        'shift_closings'   => 'shift_closing_id',
    ];

    public static function manifest(PDO $conn): array
    {
        $manifest = [];

        foreach (self::TABLES as $table) {
            $manifest[] = [
                'table' => $table,
                'rows'  => self::tableCount($conn, $table),
            ];
        }

        return $manifest;
    }

    public static function exportPayload(PDO $conn): array
    {
        $payload = [
            'app' => 'StockWise',
            'generated_at' => date('c'),
            'tables' => [],
        ];

        foreach (self::TABLES as $table) {
            if (!self::hasTable($conn, $table)) {
                continue;
            }

            $stmt = $conn->query('SELECT * FROM `' . $table . '`');
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            $payload['tables'][$table] = [
                'columns' => $rows !== [] ? array_keys($rows[0]) : [],
                'rows'    => $rows,
            ];
        }

        return $payload;
    }

    public static function download(PDO $conn): never
    {
        $payload = self::exportPayload($conn);

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="stockwise-backup-' . date('Ymd-His') . '.json"');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function restoreFromUpload(PDO $conn, array $file): array
    {
        if (
            !isset($file['error']) ||
            (int) $file['error'] === UPLOAD_ERR_NO_FILE
        ) {
            throw new InvalidArgumentException('Please choose a backup file to restore.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The backup file could not be uploaded.');
        }

        $tmpFile = (string) ($file['tmp_name'] ?? '');
        if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
            throw new RuntimeException('The uploaded backup file is invalid.');
        }

        $raw = (string) file_get_contents($tmpFile);
        $payload = json_decode($raw, true);

        if (!is_array($payload) || !isset($payload['tables']) || !is_array($payload['tables'])) {
            throw new RuntimeException('This backup file does not look valid.');
        }

        return self::restorePayload($conn, $payload);
    }

    public static function restorePayload(PDO $conn, array $payload): array
    {
        $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];
        if ($tables === []) {
            throw new RuntimeException('No tables were found in the backup file.');
        }

        $summary = [
            'tables_cleared' => 0,
            'rows_restored'  => 0,
        ];

        $conn->beginTransaction();
        try {
            $conn->exec('SET FOREIGN_KEY_CHECKS=0');

            foreach (array_reverse(self::TABLES) as $table) {
                if (!self::hasTable($conn, $table)) {
                    continue;
                }
                $conn->exec('DELETE FROM `' . $table . '`');
                $summary['tables_cleared']++;
            }

            foreach (self::TABLES as $table) {
                if (!isset($tables[$table]) || !is_array($tables[$table])) {
                    continue;
                }

                $rows = is_array($tables[$table]['rows'] ?? null) ? $tables[$table]['rows'] : [];
                if ($rows === []) {
                    continue;
                }

                $inserted = self::restoreTableRows($conn, $table, $rows);
                $summary['rows_restored'] += $inserted;
                self::resetAutoIncrement($conn, $table, $rows);
            }

            $conn->exec('SET FOREIGN_KEY_CHECKS=1');
            $conn->commit();

            return $summary;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            try {
                $conn->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
                // ignore
            }

            throw $e;
        }
    }

    private static function restoreTableRows(PDO $conn, string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        $count = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $params = [];
            foreach ($columns as $column) {
                $params[':' . $column] = $row[$column] ?? null;
            }

            $stmt->execute($params);
            $count++;
        }

        return $count;
    }

    private static function resetAutoIncrement(PDO $conn, string $table, array $rows): void
    {
        $column = self::AUTO_INCREMENT_COLUMNS[$table] ?? null;
        if ($column === null || $rows === []) {
            return;
        }

        $maxId = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $maxId = max($maxId, (int) ($row[$column] ?? 0));
        }

        if ($maxId <= 0) {
            return;
        }

        $conn->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . ($maxId + 1));
    }

    private static function tableCount(PDO $conn, string $table): int
    {
        if (!self::hasTable($conn, $table)) {
            return 0;
        }

        $stmt = $conn->query('SELECT COUNT(*) FROM `' . $table . '`');
        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    }

    private static function hasTable(PDO $conn, string $table): bool
    {
        if (function_exists('app_has_table')) {
            return app_has_table($conn, $table);
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $stmt->execute([':table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
