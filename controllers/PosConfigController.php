<?php
declare(strict_types=1);

final class PosConfigController
{
    private const TABLE                            = 'pos_config';
    private const MAX_LOGO_SIZE                    = 2_097_152; // 2 MB
    private const DEFAULT_SHIFT_EDIT_WINDOW_HOURS   = 8;
    private const DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS = 2;
    private const ALLOWED_LOGO_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * SCALABILITY: APCu cache key and TTL for get().
     * taxRate(), shiftEditWindowHours(), shiftUnlockWindowHours()
     * all call get() — without the cache this is 3+ identical
     * SELECT queries per request.
     */
    private const CACHE_KEY = 'pos_config_row';
    private const CACHE_TTL = 30; // seconds

    /** SCALABILITY: schema check runs at most once per worker. */
    private static bool $schemaChecked = false;

    // =========================================================================
    // PUBLIC READ
    // =========================================================================

    public static function get(PDO $conn): array
    {
        self::ensureSchema($conn);

        // APCu cache — busted by save()
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch(self::CACHE_KEY, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        $stmt = $conn->query("
            SELECT
                config_id, store_name, store_address, store_phone, store_email,
                opening_hours, closing_hours, tax_rate, currency, logo,
                shift_edit_window_hours, shift_unlock_window_hours
            FROM " . self::TABLE . "
            ORDER BY config_id ASC
            LIMIT 1
        ");

        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        $config = $row ?? self::defaults();

        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $config, self::CACHE_TTL);
        }

        return $config;
    }

    public static function taxRate(PDO $conn): float
    {
        return (float) (self::get($conn)['tax_rate'] ?? 12.00);
    }

    public static function shiftEditWindowHours(PDO $conn): int
    {
        return self::normalizeHours(
            self::get($conn)['shift_edit_window_hours'] ?? self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS,
            self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS
        );
    }

    public static function shiftUnlockWindowHours(PDO $conn): int
    {
        return self::normalizeHours(
            self::get($conn)['shift_unlock_window_hours'] ?? self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS,
            self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS
        );
    }

    public static function logoUrl(?string $logo): ?string
    {
        $logo = trim((string) $logo);
        if ($logo === '') return null;

        if (preg_match('#^https?://#i', $logo) === 1 || str_starts_with($logo, '/')) {
            return $logo;
        }

        if (
            preg_match('#^[a-z0-9/_.\-]+$#i', $logo) === 1
            && (str_starts_with($logo, 'pos-config/')
                || str_starts_with($logo, 'products/')
                || str_starts_with($logo, 'staff/'))
        ) {
            return self::buildMediaUrl($logo);
        }

        return $logo;
    }

    // =========================================================================
    // PUBLIC WRITE
    // =========================================================================

    public static function save(PDO $conn, array $input, array $files = []): array
    {
        self::ensureSchema($conn);

        $payload     = self::validate($input);
        $existing    = self::fetchExisting($conn);
        $existingId  = isset($existing['config_id']) ? (int) $existing['config_id'] : null;
        $existingLogo = trim((string) ($existing['logo'] ?? ''));
        $newLogo     = null;

        try {
            $newLogo = self::handleLogoUpload($files['logo_upload'] ?? null);
            if ($newLogo !== null) {
                $payload['logo'] = $newLogo;
            } elseif ($payload['logo'] === null && $existingLogo !== '') {
                $payload['logo'] = $existingLogo;
            }
        } catch (Throwable $e) {
            if ($newLogo !== null) self::deleteStoredLogo($newLogo);
            throw $e;
        }

        try {
            if ($existingId !== null) {
                $conn->prepare("
                    UPDATE " . self::TABLE . "
                    SET store_name              = :store_name,
                        store_address           = :store_address,
                        store_phone             = :store_phone,
                        store_email             = :store_email,
                        opening_hours           = :opening_hours,
                        closing_hours           = :closing_hours,
                        tax_rate                = :tax_rate,
                        currency                = :currency,
                        logo                    = :logo,
                        shift_edit_window_hours   = :shift_edit_window_hours,
                        shift_unlock_window_hours = :shift_unlock_window_hours
                    WHERE config_id = :config_id
                ")->execute([
                    ':store_name'               => $payload['store_name'],
                    ':store_address'            => $payload['store_address'],
                    ':store_phone'              => $payload['store_phone'],
                    ':store_email'              => $payload['store_email'],
                    ':opening_hours'            => $payload['opening_hours'],
                    ':closing_hours'            => $payload['closing_hours'],
                    ':tax_rate'                 => $payload['tax_rate'],
                    ':currency'                 => $payload['currency'],
                    ':logo'                     => $payload['logo'],
                    ':shift_edit_window_hours'  => $payload['shift_edit_window_hours'],
                    ':shift_unlock_window_hours' => $payload['shift_unlock_window_hours'],
                    ':config_id'                => $existingId,
                ]);
            } else {
                $conn->prepare("
                    INSERT INTO " . self::TABLE . "
                        (store_name, store_address, store_phone, store_email,
                         opening_hours, closing_hours, tax_rate, currency, logo,
                         shift_edit_window_hours, shift_unlock_window_hours)
                    VALUES
                        (:store_name, :store_address, :store_phone, :store_email,
                         :opening_hours, :closing_hours, :tax_rate, :currency, :logo,
                         :shift_edit_window_hours, :shift_unlock_window_hours)
                ")->execute([
                    ':store_name'               => $payload['store_name'],
                    ':store_address'            => $payload['store_address'],
                    ':store_phone'              => $payload['store_phone'],
                    ':store_email'              => $payload['store_email'],
                    ':opening_hours'            => $payload['opening_hours'],
                    ':closing_hours'            => $payload['closing_hours'],
                    ':tax_rate'                 => $payload['tax_rate'],
                    ':currency'                 => $payload['currency'],
                    ':logo'                     => $payload['logo'],
                    ':shift_edit_window_hours'  => $payload['shift_edit_window_hours'],
                    ':shift_unlock_window_hours' => $payload['shift_unlock_window_hours'],
                ]);
            }
        } catch (Throwable $e) {
            if ($newLogo !== null) self::deleteStoredLogo($newLogo);
            throw $e;
        }

        if ($newLogo !== null && $existingLogo !== '' && $existingLogo !== $newLogo) {
            self::deleteStoredLogo($existingLogo);
        }

        // Bust APCu cache so next get() returns fresh data
        self::bustConfigCache();

        return self::get($conn);
    }

    /** Bust the APCu config cache — call after any write. */
    public static function bustConfigCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete(self::CACHE_KEY);
        }
    }

    // =========================================================================
    // PRIVATE — schema
    // =========================================================================

    private static function ensureSchema(PDO $conn): void
    {
        // Static guard: only runs INFORMATION_SCHEMA checks once per worker
        if (self::$schemaChecked) return;

        if (!app_has_table($conn, self::TABLE) && !app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change(self::TABLE);
        }

        self::ensureColumn($conn, 'shift_edit_window_hours',
            'shift_edit_window_hours int(11) NOT NULL DEFAULT ' . self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS . ' AFTER logo');
        self::ensureColumn($conn, 'shift_unlock_window_hours',
            'shift_unlock_window_hours int(11) NOT NULL DEFAULT ' . self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS . ' AFTER shift_edit_window_hours');

        self::$schemaChecked = true;
    }

    private static function ensureColumn(PDO $conn, string $column, string $definition): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = :t
                  AND COLUMN_NAME  = :c
            ");
            $stmt->execute([':t' => self::TABLE, ':c' => $column]);
            if ((int) $stmt->fetchColumn() > 0) return;

            if (!app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change(self::TABLE . '.' . $column);
            }

            $conn->exec("ALTER TABLE " . self::TABLE . " ADD COLUMN {$definition}");
        } catch (Throwable $e) {
            error_log('[PosConfigController][schema] ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PRIVATE — helpers
    // =========================================================================

    /**
     * Fetch the single config row from the DB (no cache).
     * Used internally by save() where we need the live value.
     */
    private static function fetchExisting(PDO $conn): ?array
    {
        $stmt = $conn->query("
            SELECT config_id, store_name, store_address, store_phone, store_email,
                   opening_hours, closing_hours, tax_rate, currency, logo,
                   shift_edit_window_hours, shift_unlock_window_hours
            FROM " . self::TABLE . "
            ORDER BY config_id ASC
            LIMIT 1
        ");
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        return $row;
    }

    private static function defaults(): array
    {
        return [
            'config_id'               => null,
            'store_name'              => 'My Store',
            'store_address'           => null,
            'store_phone'             => null,
            'store_email'             => null,
            'opening_hours'           => null,
            'closing_hours'           => null,
            'tax_rate'                => 12.00,
            'currency'                => 'PHP',
            'logo'                    => null,
            'shift_edit_window_hours'  => self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS,
            'shift_unlock_window_hours' => self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS,
        ];
    }

    private static function validate(array $input): array
    {
        $storeName   = trim((string) ($input['store_name'] ?? ''));
        $address     = self::nullableTrim($input['store_address']  ?? null);
        $phone       = self::nullableTrim($input['store_phone']    ?? null);
        $email       = self::nullableTrim($input['store_email']    ?? null);
        $openHours   = self::nullableTrim($input['opening_hours']  ?? null);
        $closeHours  = self::nullableTrim($input['closing_hours']  ?? null);
        $currency    = strtoupper(trim((string) ($input['currency'] ?? 'PHP')));
        $logo        = self::nullableTrim($input['logo_current'] ?? $input['logo'] ?? null);
        $taxRate     = round((float) ($input['tax_rate'] ?? 12), 2);
        $editHours   = self::normalizeHours($input['shift_edit_window_hours']   ?? self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS,   self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS);
        $unlockHours = self::normalizeHours($input['shift_unlock_window_hours'] ?? self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS, self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS);

        if ($storeName === '') {
            throw new InvalidArgumentException('Store name is required.');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Store email is invalid.');
        }
        if ($phone !== null && !preg_match('/^[0-9+\-\s()]{7,50}$/', $phone)) {
            throw new InvalidArgumentException('Store phone format is invalid.');
        }
        if ($taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('VAT rate must be between 0 and 100.');
        }
        if ($currency === '' || strlen($currency) > 10) {
            throw new InvalidArgumentException('Currency is required and must be 10 characters or fewer.');
        }

        return [
            'store_name'               => $storeName,
            'store_address'            => $address,
            'store_phone'              => $phone,
            'store_email'              => $email,
            'opening_hours'            => $openHours,
            'closing_hours'            => $closeHours,
            'tax_rate'                 => $taxRate,
            'currency'                 => $currency,
            'logo'                     => $logo,
            'shift_edit_window_hours'  => $editHours,
            'shift_unlock_window_hours' => $unlockHours,
        ];
    }

    private static function handleLogoUpload(mixed $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Logo upload failed.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded logo file.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_LOGO_SIZE) {
            throw new RuntimeException('Logo file is too large. Maximum size is 2 MB.');
        }

        $mime = mime_content_type($tmp);
        if (!is_string($mime) || !array_key_exists($mime, self::ALLOWED_LOGO_MIME_TYPES)) {
            throw new RuntimeException('Invalid logo type. Only JPG, PNG, and WEBP are allowed.');
        }
        if (getimagesize($tmp) === false) {
            throw new RuntimeException('Uploaded logo is not a valid image.');
        }

        $ext       = self::ALLOWED_LOGO_MIME_TYPES[$mime];
        $uploadDir = self::secureUploadDirectory();

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create the logo upload directory.');
        }

        $fileName   = 'logo_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($tmp, $targetPath)) {
            throw new RuntimeException('Failed to save the uploaded logo.');
        }

        return self::buildMediaUrl('pos-config/' . $fileName);
    }

    private static function deleteStoredLogo(?string $path): void
    {
        $abs = self::resolveStoredLogoPath($path);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
    }

    private static function resolveStoredLogoPath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') return null;

        $asset = self::extractMediaAsset($path);
        if ($asset !== null) {
            return self::resolveSecureAssetPath($asset, 'pos-config');
        }

        if (!str_starts_with($path, '/inventory_system/uploads/pos-config/')) {
            return null;
        }

        $base     = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $rel      = substr($path, strlen('/inventory_system'));
        $abs      = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $realBase = realpath($base);
        $realDir  = realpath(dirname($abs));

        if ($realBase === false || $realDir === false) return null;

        $uploadsBase = $realBase . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pos-config';
        return str_starts_with($realDir, $uploadsBase) ? $abs : null;
    }

    private static function secureUploadDirectory(): string
    {
        return self::secureUploadsBasePath() . DIRECTORY_SEPARATOR . 'pos-config' . DIRECTORY_SEPARATOR;
    }

    private static function secureUploadsBasePath(): string
    {
        if (function_exists('app_secure_storage_dir')) {
            return rtrim(app_secure_storage_dir(), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        return $base . DIRECTORY_SEPARATOR . 'uploads';
    }

    private static function buildMediaUrl(string $asset): string
    {
        return '/inventory_system/media.php?asset=' . rawurlencode($asset);
    }

    private static function extractMediaAsset(string $path): ?string
    {
        if (!str_starts_with($path, '/inventory_system/media.php')) return null;
        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') return null;
        parse_str($query, $params);
        $asset = trim((string) ($params['asset'] ?? ''));
        return $asset !== '' ? $asset : null;
    }

    private static function resolveSecureAssetPath(string $asset, string $prefix): ?string
    {
        $asset = trim($asset);
        if ($asset === '' || str_contains($asset, '..')) return null;
        if (preg_match('#^[a-z0-9/_.\-]+$#i', $asset) !== 1) return null;
        if (!str_starts_with($asset, $prefix . '/')) return null;

        $baseDir  = self::secureUploadsBasePath();
        $abs      = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
        $realBase = realpath($baseDir);
        $realDir  = realpath(dirname($abs));

        if ($realBase === false || $realDir === false) return null;

        $expectedBase = $realBase . DIRECTORY_SEPARATOR . $prefix;
        return str_starts_with($realDir, $expectedBase) ? $abs : null;
    }

    private static function normalizeHours(mixed $value, int $default): int
    {
        $h = (int) $value;
        return ($h >= 1 && $h <= 72) ? $h : $default;
    }

    private static function nullableTrim(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v !== '' ? $v : null;
    }
}