<?php
declare(strict_types=1);

final class PosConfigController
{
    private const TABLE = 'pos_config';

    public static function get(PDO $conn): array
    {
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
                logo
            FROM " . self::TABLE . "
            ORDER BY config_id ASC
            LIMIT 1
        ");

        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        return $config ?: self::defaults();
    }

    public static function save(PDO $conn, array $input): array
    {
        $payload = self::validate($input);
        $existing = self::existingId($conn);

        if ($existing !== null) {
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
                    logo = :logo
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
                ':config_id'     => $existing,
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
                    logo
                ) VALUES (
                    :store_name,
                    :store_address,
                    :store_phone,
                    :store_email,
                    :opening_hours,
                    :closing_hours,
                    :tax_rate,
                    :currency,
                    :logo
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
            ]);
        }

        return self::get($conn);
    }

    public static function taxRate(PDO $conn): float
    {
        return (float) (self::get($conn)['tax_rate'] ?? 12.00);
    }

    private static function existingId(PDO $conn): ?int
    {
        $stmt = $conn->query("
            SELECT config_id
            FROM " . self::TABLE . "
            ORDER BY config_id ASC
            LIMIT 1
        ");

        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
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
        $logo = self::nullableTrim($input['logo'] ?? null);
        $taxRate = round((float) ($input['tax_rate'] ?? 12), 2);

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
        ];
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
        ];
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
