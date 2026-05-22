<?php
declare(strict_types=1);

final class PosConfigController
{
    private const TABLE = 'pos_config';
    private const MAX_LOGO_SIZE = 2097152; // 2MB
    private const DEFAULT_SHIFT_EDIT_WINDOW_HOURS = 8;
    private const DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS = 2;
    private const ALLOWED_LOGO_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public static function get(PDO $conn): array
    {
        self::ensureSchema($conn);

        $stmt = $conn->query("
            SELECT
                config_id,
                store_name,
                store_address,
                store_phone,
                store_email,
                opening_hours,
                closing_hours,
                tax_rate,
                currency,
                logo,
                shift_edit_window_hours,
                shift_unlock_window_hours
            FROM " . self::TABLE . "
            ORDER BY config_id ASC
            LIMIT 1
        ");

        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        return $config ?: self::defaults();
    }

    public static function save(PDO $conn, array $input, array $files = []): array
    {
        self::ensureSchema($conn);

        $payload = self::validate($input);
        $existing = self::existingConfig($conn);
        $existingId = isset($existing['config_id']) ? (int) $existing['config_id'] : null;
        $existingLogo = trim((string) ($existing['logo'] ?? ''));
        $newLogo = null;

        try {
            $newLogo = self::handleLogoUpload($files['logo_upload'] ?? null);
            if ($newLogo !== null) {
                $payload['logo'] = $newLogo;
            } elseif ($payload['logo'] === null && $existingLogo !== '') {
                $payload['logo'] = $existingLogo;
            }
        } catch (Throwable $e) {
            if ($newLogo !== null) {
                self::deleteStoredLogo($newLogo);
            }
            throw $e;
        }

        try {
            if ($existingId !== null) {
                $stmt = $conn->prepare("
                    UPDATE " . self::TABLE . "
                    SET
                        store_name = :store_name,
                        store_address = :store_address,
                        store_phone = :store_phone,
                        store_email = :store_email,
                        opening_hours = :opening_hours,
                        closing_hours = :closing_hours,
                        tax_rate = :tax_rate,
                        currency = :currency,
                        logo = :logo,
                        shift_edit_window_hours = :shift_edit_window_hours,
                        shift_unlock_window_hours = :shift_unlock_window_hours
                    WHERE config_id = :config_id
                ");

                $stmt->execute([
                    ':store_name'    => $payload['store_name'],
                    ':store_address' => $payload['store_address'],
                    ':store_phone'   => $payload['store_phone'],
                    ':store_email'   => $payload['store_email'],
                    ':opening_hours' => $payload['opening_hours'],
                    ':closing_hours' => $payload['closing_hours'],
                    ':tax_rate'      => $payload['tax_rate'],
                    ':currency'      => $payload['currency'],
                    ':logo'          => $payload['logo'],
                    ':shift_edit_window_hours' => $payload['shift_edit_window_hours'],
                    ':shift_unlock_window_hours' => $payload['shift_unlock_window_hours'],
                    ':config_id'     => $existingId,
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO " . self::TABLE . " (
                        store_name,
                        store_address,
                        store_phone,
                        store_email,
                        opening_hours,
                        closing_hours,
                        tax_rate,
                        currency,
                        logo,
                        shift_edit_window_hours,
                        shift_unlock_window_hours
                    ) VALUES (
                        :store_name,
                        :store_address,
                        :store_phone,
                        :store_email,
                        :opening_hours,
                        :closing_hours,
                        :tax_rate,
                        :currency,
                        :logo,
                        :shift_edit_window_hours,
                        :shift_unlock_window_hours
                    )
                ");

                $stmt->execute([
                    ':store_name'    => $payload['store_name'],
                    ':store_address' => $payload['store_address'],
                    ':store_phone'   => $payload['store_phone'],
                    ':store_email'   => $payload['store_email'],
                    ':opening_hours' => $payload['opening_hours'],
                    ':closing_hours' => $payload['closing_hours'],
                    ':tax_rate'      => $payload['tax_rate'],
                    ':currency'      => $payload['currency'],
                    ':logo'          => $payload['logo'],
                    ':shift_edit_window_hours' => $payload['shift_edit_window_hours'],
                    ':shift_unlock_window_hours' => $payload['shift_unlock_window_hours'],
                ]);
            }
        } catch (Throwable $e) {
            if ($newLogo !== null) {
                self::deleteStoredLogo($newLogo);
            }
            throw $e;
        }

        if ($newLogo !== null && $existingLogo !== '' && $existingLogo !== $newLogo) {
            self::deleteStoredLogo($existingLogo);
        }

        return self::get($conn);
    }

    public static function taxRate(PDO $conn): float
    {
        return (float) (self::get($conn)['tax_rate'] ?? 12.00);
    }

    public static function shiftEditWindowHours(PDO $conn): int
    {
        return self::normalizeHourSetting(self::get($conn)['shift_edit_window_hours'] ?? self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS, self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS);
    }

    public static function shiftUnlockWindowHours(PDO $conn): int
    {
        return self::normalizeHourSetting(self::get($conn)['shift_unlock_window_hours'] ?? self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS, self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS);
    }

    public static function logoUrl(?string $logo): ?string
    {
        $logo = trim((string) $logo);
        if ($logo === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $logo) === 1 || str_starts_with($logo, '/')) {
            return $logo;
        }

        if (
            preg_match('#^[a-z0-9/_\.-]+$#i', $logo) === 1
            && (str_starts_with($logo, 'pos-config/')
                || str_starts_with($logo, 'products/')
                || str_starts_with($logo, 'staff/'))
        ) {
            return self::buildMediaUrl($logo);
        }

        return $logo;
    }

    private static function existingId(PDO $conn): ?int
    {
        $existing = self::existingConfig($conn);
        return isset($existing['config_id']) ? (int) $existing['config_id'] : null;
    }

    private static function existingConfig(PDO $conn): ?array
    {
        self::ensureSchema($conn);

        $stmt = $conn->query("
            SELECT
                config_id,
                store_name,
                store_address,
                store_phone,
                store_email,
                opening_hours,
                closing_hours,
                tax_rate,
                currency,
                logo,
                shift_edit_window_hours,
                shift_unlock_window_hours
            FROM " . self::TABLE . "
            ORDER BY config_id ASC
            LIMIT 1
        ");

        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        return $config ?: null;
    }

    private static function validate(array $input): array
    {
        $storeName = trim((string) ($input['store_name'] ?? ''));
        $storeAddress = self::nullableTrim($input['store_address'] ?? null);
        $storePhone = self::nullableTrim($input['store_phone'] ?? null);
        $storeEmail = self::nullableTrim($input['store_email'] ?? null);
        $openingHours = self::nullableTrim($input['opening_hours'] ?? null);
        $closingHours = self::nullableTrim($input['closing_hours'] ?? null);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'PHP')));
        $logo = self::nullableTrim($input['logo_current'] ?? $input['logo'] ?? null);
        $taxRate = round((float) ($input['tax_rate'] ?? 12), 2);
        $shiftEditWindowHours = self::normalizeHourSetting($input['shift_edit_window_hours'] ?? self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS, self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS);
        $shiftUnlockWindowHours = self::normalizeHourSetting($input['shift_unlock_window_hours'] ?? self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS, self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS);

        if ($storeName === '') {
            throw new InvalidArgumentException('Store name is required.');
        }

        if ($storeEmail !== null && !filter_var($storeEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Store email is invalid.');
        }

        if ($storePhone !== null && !preg_match('/^[0-9+\-\s()]{7,50}$/', $storePhone)) {
            throw new InvalidArgumentException('Store phone format is invalid.');
        }

        if ($taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('VAT rate must be between 0 and 100.');
        }

        if ($currency === '' || strlen($currency) > 10) {
            throw new InvalidArgumentException('Currency is required and must be 10 characters or fewer.');
        }

        return [
            'store_name'    => $storeName,
            'store_address' => $storeAddress,
            'store_phone'   => $storePhone,
            'store_email'   => $storeEmail,
            'opening_hours' => $openingHours,
            'closing_hours' => $closingHours,
            'tax_rate'      => $taxRate,
            'currency'      => $currency,
            'logo'          => $logo,
            'shift_edit_window_hours' => $shiftEditWindowHours,
            'shift_unlock_window_hours' => $shiftUnlockWindowHours,
        ];
    }

    private static function handleLogoUpload(mixed $file): ?string
    {
        if (
            !is_array($file)
            || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Logo upload failed.');
        }

        $tmpFile = (string) ($file['tmp_name'] ?? '');
        if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
            throw new RuntimeException('Invalid uploaded logo file.');
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_LOGO_SIZE) {
            throw new RuntimeException('Logo file is too large. Maximum size is 2MB.');
        }

        $mimeType = mime_content_type($tmpFile);
        if (!is_string($mimeType) || !array_key_exists($mimeType, self::ALLOWED_LOGO_MIME_TYPES)) {
            throw new RuntimeException('Invalid logo file type. Only JPG, PNG, and WEBP are allowed.');
        }

        if (getimagesize($tmpFile) === false) {
            throw new RuntimeException('Uploaded logo is not a valid image.');
        }

        $extension = self::ALLOWED_LOGO_MIME_TYPES[$mimeType];
        $uploadDir = self::secureUploadDirectory();

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create the logo upload directory.');
        }

        $fileName = 'logo_' . bin2hex(random_bytes(12)) . '.' . $extension;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($tmpFile, $targetPath)) {
            throw new RuntimeException('Failed to save the uploaded logo.');
        }

        return self::buildMediaUrl('pos-config/' . $fileName);
    }

    private static function defaults(): array
    {
        return [
            'config_id'      => null,
            'store_name'     => 'My Store',
            'store_address'  => null,
            'store_phone'    => null,
            'store_email'    => null,
            'opening_hours'  => null,
            'closing_hours'  => null,
            'tax_rate'       => 12.00,
            'currency'       => 'PHP',
            'logo'           => null,
            'shift_edit_window_hours' => self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS,
            'shift_unlock_window_hours' => self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS,
        ];
    }

    private static function ensureSchema(PDO $conn): void
    {
        self::ensureColumn($conn, 'shift_edit_window_hours', 'shift_edit_window_hours int(11) NOT NULL DEFAULT ' . self::DEFAULT_SHIFT_EDIT_WINDOW_HOURS . ' AFTER logo');
        self::ensureColumn($conn, 'shift_unlock_window_hours', 'shift_unlock_window_hours int(11) NOT NULL DEFAULT ' . self::DEFAULT_SHIFT_UNLOCK_WINDOW_HOURS . ' AFTER shift_edit_window_hours');
    }

    private static function ensureColumn(PDO $conn, string $column, string $definition): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name' => self::TABLE,
                ':column_name' => $column,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }

            $conn->exec("
                ALTER TABLE " . self::TABLE . "
                ADD COLUMN {$definition}
            ");
        } catch (Throwable $e) {
            error_log('[PosConfigController][schema] ' . $e->getMessage());
        }
    }

    private static function normalizeHourSetting(mixed $value, int $default): int
    {
        $hours = (int) $value;
        if ($hours < 1 || $hours > 72) {
            return $default;
        }

        return $hours;
    }

    private static function deleteStoredLogo(?string $logoPath): void
    {
        $absolutePath = self::resolveStoredLogoPath($logoPath);
        if ($absolutePath === null || !is_file($absolutePath)) {
            return;
        }

        @unlink($absolutePath);
    }

    private static function resolveStoredLogoPath(?string $logoPath): ?string
    {
        $logoPath = trim((string) $logoPath);
        if ($logoPath === '') {
            return null;
        }

        $mediaAsset = self::extractMediaAsset($logoPath);
        if ($mediaAsset !== null) {
            return self::resolveSecureAssetPath($mediaAsset, 'pos-config');
        }

        if (!str_starts_with($logoPath, '/inventory_system/uploads/pos-config/')) {
            return null;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $relativePath = substr($logoPath, strlen('/inventory_system'));
        $absolutePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $realBasePath = realpath($basePath);
        $realDirectory = realpath(dirname($absolutePath));

        if ($realBasePath === false || $realDirectory === false) {
            return null;
        }

        $uploadsBase = $realBasePath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pos-config';
        if (!str_starts_with($realDirectory, $uploadsBase)) {
            return null;
        }

        return $absolutePath;
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

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        return $basePath . DIRECTORY_SEPARATOR . 'uploads';
    }

    private static function buildMediaUrl(string $asset): string
    {
        return '/inventory_system/media.php?asset=' . rawurlencode($asset);
    }

    private static function extractMediaAsset(string $path): ?string
    {
        if (!str_starts_with($path, '/inventory_system/media.php')) {
            return null;
        }

        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $asset = trim((string) ($params['asset'] ?? ''));

        return $asset !== '' ? $asset : null;
    }

    private static function resolveSecureAssetPath(string $asset, string $expectedPrefix): ?string
    {
        $asset = trim($asset);
        if ($asset === '' || str_contains($asset, '..')) {
            return null;
        }

        if (preg_match('#^[a-z0-9/_\.-]+$#i', $asset) !== 1) {
            return null;
        }

        $prefix = $expectedPrefix . '/';
        if (!str_starts_with($asset, $prefix)) {
            return null;
        }

        $baseDir = self::secureUploadsBasePath();
        $absolutePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
        $realBaseDir = realpath($baseDir);
        $realDirectory = realpath(dirname($absolutePath));

        if ($realBaseDir === false || $realDirectory === false) {
            return null;
        }

        $expectedBase = $realBaseDir . DIRECTORY_SEPARATOR . $expectedPrefix;
        if (!str_starts_with($realDirectory, $expectedBase)) {
            return null;
        }

        return $absolutePath;
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
