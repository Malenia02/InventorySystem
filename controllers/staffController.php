<?php
declare(strict_types=1);

final class StaffController
{
    private const TABLE = 'users';
    private const ALLOWED_ROLES = ['admin', 'staff', 'cashier'];
    private const DEFAULT_PHOTO = '/inventory_system/assets/img/default-user.png';
    private const MAX_PHOTO_SIZE = 2097152; // 2MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public static function getAllStaff(PDO $conn): array
    {
        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, email, username, role, status, photo, deactivated_at
            FROM " . self::TABLE . "
            ORDER BY role ASC, status ASC, user_id DESC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getStaffById(PDO $conn, int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid staff ID.');
        }

        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, email, username, role, status, photo, deactivated_at
            FROM " . self::TABLE . "
            WHERE user_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        return $staff ?: null;
    }

    public static function addStaff(PDO $conn, array $data): int
    {
        $payload = self::validatePayload($data, false);
        self::assertUniqueCredentials($conn, $payload['username'], $payload['email']);

        $stmt = $conn->prepare("
            INSERT INTO " . self::TABLE . "
                (first_name, last_name, email, username, password, role, status, photo)
            VALUES
                (:first_name, :last_name, :email, :username, :password, :role, 'active', :photo)
        ");

        $stmt->execute([
            ':first_name' => $payload['first_name'],
            ':last_name'  => $payload['last_name'],
            ':email'      => $payload['email'],
            ':username'   => $payload['username'],
            ':password'   => password_hash((string) $payload['password'], PASSWORD_DEFAULT),
            ':role'       => $payload['role'],
            ':photo'      => $payload['photo'] ?? self::DEFAULT_PHOTO,
        ]);

        return (int) $conn->lastInsertId();
    }

    public static function updateStaff(PDO $conn, int $id, array $data): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid staff ID.');
        }

        $existing = self::getStaffById($conn, $id);
        if (!$existing) {
            throw new RuntimeException('Staff not found.');
        }

        $payload = self::validatePayload($data, true);
        self::assertUniqueCredentials($conn, $payload['username'], $payload['email'], $id);

        $fields = [
            'first_name = :first_name',
            'last_name = :last_name',
            'email = :email',
            'username = :username',
            'role = :role',
        ];

        $params = [
            ':id'         => $id,
            ':first_name' => $payload['first_name'],
            ':last_name'  => $payload['last_name'],
            ':email'      => $payload['email'],
            ':username'   => $payload['username'],
            ':role'       => $payload['role'],
        ];

        if ($payload['password'] !== null) {
            $fields[] = 'password = :password';
            $params[':password'] = password_hash($payload['password'], PASSWORD_DEFAULT);
        }

        if ($payload['photo'] !== null) {
            $fields[] = 'photo = :photo';
            $params[':photo'] = $payload['photo'];
        }

        $sql = "UPDATE " . self::TABLE . " SET " . implode(', ', $fields) . " WHERE user_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    }

    public static function toggleStatus(PDO $conn, int $id, int $sessionUserId): string
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid staff ID.');
        }

        if ($id === $sessionUserId) {
            throw new RuntimeException('You cannot deactivate your own account while signed in.');
        }

        $staff = self::getStaffById($conn, $id);
        if (!$staff) {
            throw new RuntimeException('Staff not found.');
        }

        $newStatus = (($staff['status'] ?? 'inactive') === 'active') ? 'inactive' : 'active';
        $deactivatedAtSql = $newStatus === 'inactive' ? 'NOW()' : 'NULL';

        $stmt = $conn->prepare("
            UPDATE " . self::TABLE . "
            SET status = :status, deactivated_at = {$deactivatedAtSql}
            WHERE user_id = :id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $id,
        ]);

        return $newStatus;
    }

    public static function handlePhotoUpload(string $fileInputName, string $staffName = 'unknown'): ?string
    {
        if (
            !isset($_FILES[$fileInputName]) ||
            !is_array($_FILES[$fileInputName]) ||
            ($_FILES[$fileInputName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        $file = $_FILES[$fileInputName];

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_PHOTO_SIZE) {
            throw new RuntimeException('File too large. Max size is 2MB.');
        }

        $mimeType = mime_content_type($tmpName);
        if (!is_string($mimeType) || !array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException('Invalid file type. Only JPG, PNG, and WEBP are allowed.');
        }

        if (getimagesize($tmpName) === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($staffName))) ?: 'unknown';

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $uploadDir = $basePath . '/uploads/staff/' . $safeName . '/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create upload directory.');
        }

        $photoName = bin2hex(random_bytes(16)) . '.' . $extension;
        $fullPath = $uploadDir . $photoName;

        if (!move_uploaded_file($tmpName, $fullPath)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }

        return '/inventory_system/uploads/staff/' . $safeName . '/' . $photoName;
    }

    public static function normalizeForView(array $staff): array
    {
        $photo = !empty($staff['photo']) ? (string) $staff['photo'] : self::DEFAULT_PHOTO;
        $status = (string) ($staff['status'] ?? 'inactive');

        return [
            'user_id'      => (int) ($staff['user_id'] ?? 0),
            'first_name'   => (string) ($staff['first_name'] ?? ''),
            'last_name'    => (string) ($staff['last_name'] ?? ''),
            'full_name'    => trim((string) (($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))),
            'email'        => (string) ($staff['email'] ?? ''),
            'username'     => (string) ($staff['username'] ?? ''),
            'role'         => (string) ($staff['role'] ?? 'staff'),
            'status'       => $status,
            'status_label' => ucfirst($status),
            'photo'        => $photo,
        ];
    }

    private static function validatePayload(array $data, bool $isUpdate): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName  = trim((string) ($data['last_name'] ?? ''));
        $email     = trim((string) ($data['email'] ?? ''));
        $username  = trim((string) ($data['username'] ?? ''));
        $password  = isset($data['password']) ? trim((string) $data['password']) : '';
        $photo     = isset($data['photo']) ? trim((string) $data['photo']) : null;
        $role      = trim((string) ($data['role'] ?? 'staff'));

        if ($firstName === '' || $lastName === '' || $email === '' || $username === '') {
            throw new InvalidArgumentException('Please fill in all required staff details.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid staff role.');
        }

        if (!$isUpdate && $password === '') {
            throw new InvalidArgumentException('Password is required for new staff.');
        }

        if ($password !== '' && strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }

        if ($photo !== null && $photo === '') {
            $photo = null;
        }

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'username'   => $username,
            'password'   => $password !== '' ? $password : null,
            'photo'      => $photo,
            'role'       => $role,
        ];
    }

    private static function assertUniqueCredentials(PDO $conn, string $username, string $email, ?int $excludeId = null): void
    {
        $sql = "
            SELECT COUNT(*)
            FROM " . self::TABLE . "
            WHERE (username = :username OR email = :email)
        ";

        $params = [
            ':username' => $username,
            ':email'    => $email,
        ];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND user_id <> :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Username or email already exists.');
        }
    }
}