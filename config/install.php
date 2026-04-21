<?php
declare(strict_types=1);

if (!function_exists('app_install_url')) {
    function app_install_url(): string
    {
        return '/inventory_system/install.php';
    }
}

if (!function_exists('app_secure_storage_dir')) {
    function app_secure_storage_dir(): string
    {
        return 'C:/xampp/secure';
    }
}

if (!function_exists('app_external_env_path')) {
    function app_external_env_path(): string
    {
        $configured = trim((string) ($_ENV['APP_ENV_FILE'] ?? $_SERVER['APP_ENV_FILE'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return app_secure_storage_dir() . '/inventory_system.env';
    }
}

if (!function_exists('app_install_lock_path')) {
    function app_install_lock_path(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        return $basePath . '/config/install.lock';
    }
}

if (!function_exists('app_setup_token_path')) {
    function app_setup_token_path(): string
    {
        $configured = trim((string) ($_ENV['APP_SETUP_TOKEN_FILE'] ?? $_SERVER['APP_SETUP_TOKEN_FILE'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return app_secure_storage_dir() . '/inventory_system.setup.token';
    }
}

if (!function_exists('app_is_install_context')) {
    function app_is_install_context(): bool
    {
        return defined('INSTALL_CONTEXT') && INSTALL_CONTEXT === true;
    }
}

if (!function_exists('app_is_install_route')) {
    function app_is_install_route(): bool
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'install.php';
    }
}

if (!function_exists('app_has_database_env')) {
    function app_has_database_env(): bool
    {
        $dbUser = trim((string) ($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? ''));
        $dbName = trim((string) ($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? ''));

        return $dbUser !== '' && $dbName !== '';
    }
}

if (!function_exists('app_is_install_locked')) {
    function app_is_install_locked(): bool
    {
        return is_file(app_install_lock_path());
    }
}

if (!function_exists('app_request_ip')) {
    function app_request_ip(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
}

if (!function_exists('app_is_private_or_local_ip')) {
    function app_is_private_or_local_ip(string $ip): bool
    {
        if ($ip === '' || $ip === '::1' || $ip === '127.0.0.1') {
            return true;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $longIp = ip2long($ip);
        if ($longIp === false) {
            return false;
        }

        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['127.0.0.0', '127.255.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            if ($startLong !== false && $endLong !== false && $longIp >= $startLong && $longIp <= $endLong) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('app_is_loopback_request')) {
    function app_is_loopback_request(): bool
    {
        $ip = app_request_ip();
        return $ip === '127.0.0.1' || $ip === '::1';
    }
}

if (!function_exists('app_is_allowed_unlocked_install_request')) {
    function app_is_allowed_unlocked_install_request(): bool
    {
        return app_is_private_or_local_ip(app_request_ip());
    }
}

if (!function_exists('app_setup_token_hash')) {
    function app_setup_token_hash(): string
    {
        $envHash = trim((string) ($_ENV['APP_SETUP_TOKEN_HASH'] ?? $_SERVER['APP_SETUP_TOKEN_HASH'] ?? ''));
        if ($envHash !== '') {
            return $envHash;
        }

        $tokenPath = app_setup_token_path();
        if (!is_file($tokenPath)) {
            return '';
        }

        $fileHash = trim((string) file_get_contents($tokenPath));
        return $fileHash;
    }
}

if (!function_exists('app_is_public_install_request')) {
    function app_is_public_install_request(): bool
    {
        return !app_is_allowed_unlocked_install_request();
    }
}

if (!function_exists('app_public_install_requires_token')) {
    function app_public_install_requires_token(): bool
    {
        return true;
    }
}

if (!function_exists('app_has_setup_token')) {
    function app_has_setup_token(): bool
    {
        return app_setup_token_hash() !== '';
    }
}

if (!function_exists('app_validate_setup_token')) {
    function app_validate_setup_token(?string $token): bool
    {
        $expectedHash = app_setup_token_hash();
        $provided = trim((string) $token);

        if ($expectedHash === '' || $provided === '') {
            return false;
        }

        return hash_equals($expectedHash, hash('sha256', $provided));
    }
}

if (!function_exists('app_lock_installation')) {
    function app_lock_installation(string $projectRoot): void
    {
        $lockPath = rtrim($projectRoot, '/\\') . '/config/install.lock';
        $payload = json_encode([
            'installed_at' => date('c'),
            'host' => $_SERVER['HTTP_HOST'] ?? 'cli',
        ], JSON_PRETTY_PRINT);

        $written = file_put_contents($lockPath, $payload !== false ? $payload : 'installed');
        if ($written === false) {
            throw new RuntimeException('Setup finished, but the installer lock file could not be created.');
        }
    }
}

if (!function_exists('app_redirect_install_blocked')) {
    function app_redirect_install_blocked(
        string $message = 'The installer is locked because this system has already been configured.'
    ): never {
        if (php_sapi_name() !== 'cli') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $_SESSION['setup_success'] = $message;
            safe_redirect('/inventory_system/login.php');
        }

        throw new RuntimeException($message);
    }
}

if (!function_exists('app_deny_unlocked_install_access')) {
    function app_deny_unlocked_install_access(
        string $message = 'Installer access is allowed only from localhost or a private network during initial setup.'
    ): never {
        if (php_sapi_name() !== 'cli') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $_SESSION['error_code'] = 403;
            $_SESSION['error_message'] = $message;
            safe_redirect('/inventory_system/error.php');
        }

        throw new RuntimeException($message);
    }
}

if (!function_exists('app_clear_setup_token')) {
    function app_clear_setup_token(string $projectRoot): void
    {
        $tokenPath = app_setup_token_path();
        if (is_file($tokenPath)) {
            @unlink($tokenPath);
        }
    }
}

if (!function_exists('app_write_setup_token_hash')) {
    function app_write_setup_token_hash(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Setup access key cannot be empty.');
        }

        if (strlen($token) < 8) {
            throw new RuntimeException('Setup access key must be at least 8 characters long.');
        }

        $tokenPath = app_setup_token_path();
        $directory = dirname($tokenPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the setup token directory.');
        }

        $written = file_put_contents($tokenPath, hash('sha256', $token) . PHP_EOL, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to save the setup access key.');
        }
    }
}

if (!function_exists('app_create_database_connection')) {
    function app_create_database_connection(
        string $host,
        string $port,
        string $username,
        string $password,
        ?string $database = null
    ): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%s%s;charset=utf8mb4',
            $host,
            $port,
            $database !== null && $database !== '' ? ';dbname=' . $database : ''
        );

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}

if (!function_exists('app_database_table_count')) {
    function app_database_table_count(PDO $conn, string $databaseName): int
    {
        $stmt = $conn->prepare('
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = :schema_name
        ');
        $stmt->execute([':schema_name' => $databaseName]);

        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('app_has_table')) {
    function app_has_table(PDO $conn, string $table): bool
    {
        $stmt = $conn->prepare('
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ');
        $stmt->execute([':table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('app_is_fully_installed')) {
    function app_is_fully_installed(?PDO $conn = null): bool
    {
        if (!app_has_database_env() || $conn === null) {
            return false;
        }

        if (!app_has_table($conn, 'users') || !app_has_table($conn, 'pos_config')) {
            return false;
        }

        $userCount = (int) $conn->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $configCount = (int) $conn->query('SELECT COUNT(*) FROM pos_config')->fetchColumn();

        return $userCount > 0 && $configCount > 0;
    }
}

if (!function_exists('app_import_sql_file')) {
    function app_import_sql_file(PDO $conn, string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Schema file was not found.');
        }

        $delimiter = ';';
        $statement = '';
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('Unable to read schema file.');
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches) === 1) {
                $delimiter = $matches[1];
                continue;
            }

            $statement .= $line . "\n";

            if (substr(rtrim($statement), -strlen($delimiter)) !== $delimiter) {
                continue;
            }

            $sql = trim(substr(rtrim($statement), 0, -strlen($delimiter)));
            $statement = '';

            if ($sql === '') {
                continue;
            }

            $sql = preg_replace('/\/\*!\d+\s+DEFINER=`[^`]*`(?:@`[^`]*`)?\s*\*\/\s*/i', '', $sql) ?? $sql;
            $sql = preg_replace('/\/\*!\d+\s+DEFINER=``\s*\*\/\s*/i', '', $sql) ?? $sql;

            $conn->exec($sql);
        }

        if (trim($statement) !== '') {
            $sql = preg_replace('/\/\*!\d+\s+DEFINER=`[^`]*`(?:@`[^`]*`)?\s*\*\/\s*/i', '', $statement) ?? $statement;
            $sql = preg_replace('/\/\*!\d+\s+DEFINER=``\s*\*\/\s*/i', '', $sql) ?? $sql;
            $conn->exec($sql);
        }
    }
}

if (!function_exists('app_write_env_file')) {
    function app_write_env_file(string $projectRoot, array $values): void
    {
        $defaults = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_INSTALLED' => 'false',
            'APP_URL' => 'http://localhost/inventory_system',
            'APP_SETUP_TOKEN_HASH' => '',
            'APP_TIMEZONE' => 'Asia/Manila',
            'SESSION_TIMEOUT' => '1800',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'inventory_system',
            'DB_USER' => 'root',
            'DB_PASS' => '',
        ];

        $payload = array_merge($defaults, $values);
        $lines = [];

        foreach ($payload as $key => $value) {
            $stringValue = (string) $value;
            $needsQuotes = preg_match('/\s/', $stringValue) === 1 || str_contains($stringValue, '#');
            $safeValue = $needsQuotes
                ? '"' . addcslashes($stringValue, "\\\"") . '"'
                : $stringValue;
            $lines[] = $key . '=' . $safeValue;
        }

        $envPath = app_external_env_path();
        $envDirectory = dirname($envPath);

        if (!is_dir($envDirectory) && !mkdir($envDirectory, 0755, true) && !is_dir($envDirectory)) {
            throw new RuntimeException('Unable to create the secure environment directory.');
        }

        $written = file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL);

        if ($written === false) {
            throw new RuntimeException('Unable to save environment configuration.');
        }
    }
}
