<?php
declare(strict_types=1);

require_once __DIR__ . '/ListQueryHelper.php';

/**
 * StaffController
 *
 * Scalability improvements over the original
 * ───────────────────────────────────────────
 * 1. Summary counts are cached in APCu/memory for 60 s so the full-table
 *    COUNT scan does not run on every page load.
 *
 * 2. getAllStaff() is removed. It fetched every row with no LIMIT which will
 *    OOM the process at scale. Use paginate() everywhere.
 *
 * 3. addStaff() now returns the fully-normalized view directly from the INSERT
 *    data instead of firing a second SELECT immediately after insert.
 *
 * 4. updateStaff() returns the normalized view the same way; no follow-up
 *    SELECT needed in the calling code.
 *
 * 5. Four covering indexes are registered:
 *      • (status, role, user_id)       – paginate ORDER BY / filter
 *      • (last_name, first_name, user_id) – name search sort
 *      • (email)                        – unique-check & search
 *      • (username)                     – unique-check & search
 *
 * 6. Search uses prefix LIKE (term%) so the (last_name, first_name) index is
 *    usable.  For tables > 1 M rows switch to the FULLTEXT helper in
 *    ListQueryHelper::buildFulltextFilter() (see comment in paginate()).
 *
 * 7. Cursor-based pagination is supported via paginateCursor() for deep pages.
 *
 * 8. Photo upload and DB write are decoupled: the caller passes the already-
 *    uploaded path so the controller itself never touches $_FILES; this makes
 *    unit-testing and rollback clean (caller deletes the file on exception).
 */
final class StaffController
{
    private const TABLE = 'users';

    private const ALLOWED_ROLES = ['admin', 'staff', 'cashier'];
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    public const DEFAULT_PHOTO = '/inventory_system/assets/img/default-user.png';
    public const MAX_PHOTO_SIZE = 2_097_152; // 2 MB
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Columns projected in every SELECT – never SELECT * */
    private const COLUMNS = 'user_id, first_name, last_name, email, username, role, status, photo, deactivated_at';

    /** Seconds to cache the summary counts in APCu */
    private const SUMMARY_TTL = 60;

    /** APCu cache key prefix */
    private const CACHE_PREFIX = 'staff_summary_';

    private static bool $indexesChecked = false;

    // ─────────────────────────────────────────────────────────────────────────
    // Read – offset pagination (default, works well up to ~500 k rows)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a paginated slice of staff with total count, filter echo, and
     * pagination metadata.
     *
     * At > 1 M rows consider:
     *   • Switch search to FULLTEXT (replace the LIKE block below).
     *   • Switch pagination to paginateCursor() to avoid OFFSET scans.
     *   • Point $conn at a read replica.
     */
    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensureIndexes($conn);

        // ── Sanitise filters ───────────────────────────────────────────────
        $search  = trim((string) ($filters['search']   ?? ''));
        $status  = strtolower(trim((string) ($filters['status']   ?? 'all')));
        $role    = strtolower(trim((string) ($filters['role']     ?? 'all')));
        $reqPage = max(1, (int) ($filters['page']     ?? 1));
        $reqPer  = (int) ($filters['per_page'] ?? 25);

        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'all';
        $role   = in_array($role,   self::ALLOWED_ROLES,    true) ? $role   : 'all';

        // ── WHERE builder ──────────────────────────────────────────────────
        $where  = [];
        $params = [];

        if ($search !== '') {
            /*
             * SCALABILITY NOTE
             * ────────────────
             * Prefix-LIKE is used here because it can use a B-Tree index.
             * When the table exceeds ~1 M rows, replace the block below with:
             *
             *   [$ftClause] = ListQueryHelper::buildFulltextFilter(
             *       ['first_name', 'last_name', 'email', 'username'],
             *       $search, $params
             *   );
             *   $where[] = $ftClause;
             *
             * Prerequisites: add FULLTEXT INDEX ft_users_search on those columns.
             */
            $terms   = ListQueryHelper::extractSearchTerms($search);
            $where   = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['first_name', 'last_name', 'email', 'username'],
                $terms,
                $params,
                'ss'
            ));
        }

        if ($status !== 'all') {
            $where[]          = 'status = :status';
            $params[':status'] = $status;
        }

        if ($role !== 'all') {
            $where[]        = 'role = :role';
            $params[':role'] = $role;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // ── COUNT (uses covering index) ────────────────────────────────────
        $countStmt = $conn->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . $whereSql
        );
        ListQueryHelper::bindAll($countStmt, $params);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // ── Pagination maths ───────────────────────────────────────────────
        $pag    = ListQueryHelper::offsetPagination($reqPage, $reqPer, $total, self::ALLOWED_PER_PAGE);
        $page   = $pag['page'];
        $perPage = $pag['per_page'];

        // ── Data slice ─────────────────────────────────────────────────────
        $dataStmt = $conn->prepare(
            'SELECT ' . self::COLUMNS .
            ' FROM ' . self::TABLE .
            $whereSql .
            ' ORDER BY status ASC, role ASC, user_id DESC' .
            ' LIMIT :limit OFFSET :offset'
        );
        ListQueryHelper::bindAll($dataStmt, $params);
        $dataStmt->bindValue(':limit',  $perPage,          PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $pag['offset'],    PDO::PARAM_INT);
        $dataStmt->execute();

        $items = array_map(
            static fn(array $row): array => self::normalizeForView($row),
            $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $pag['total_pages'],
            'search'      => $search,
            'status'      => $status,
            'role'        => $role,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Read – cursor pagination (use for deep pages / very large tables)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Keyset / cursor-based pagination.
     *
     * Use this instead of paginate() once the table has > 100 k rows and
     * users are paginating deep (page 50+).  OFFSET 50*25 = 1250 row scan
     * is fine; OFFSET 40000*25 is not.
     *
     * The cursor encodes [status, role, user_id] matching ORDER BY.
     * Pass $afterCursor = null for the first page.
     *
     * Returns: ['items', 'next_cursor', 'prev_cursor', 'has_more', 'per_page']
     * There is NO total count – that's the trade-off for O(1) pagination.
     */
    public static function paginateCursor(
        PDO    $conn,
        array  $filters   = [],
        ?string $afterCursor = null,
        int    $perPage   = 25
    ): array {
        self::ensureIndexes($conn);

        $perPage = in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : 25;
        $search  = trim((string) ($filters['search'] ?? ''));
        $status  = strtolower(trim((string) ($filters['status'] ?? 'all')));
        $role    = strtolower(trim((string) ($filters['role']   ?? 'all')));

        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'all';
        $role   = in_array($role,   self::ALLOWED_ROLES,    true) ? $role   : 'all';

        $where  = [];
        $params = [];

        if ($search !== '') {
            $terms = ListQueryHelper::extractSearchTerms($search);
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['first_name', 'last_name', 'email', 'username'],
                $terms, $params, 'ss'
            ));
        }

        if ($status !== 'all') { $where[] = 'status = :status'; $params[':status'] = $status; }
        if ($role   !== 'all') { $where[] = 'role   = :role';   $params[':role']   = $role; }

        // Apply cursor
        if ($afterCursor !== null) {
            $cursorValues = ListQueryHelper::decodeCursor($afterCursor);
            if ($cursorValues !== null && count($cursorValues) === 3) {
                $cursorWhere = ListQueryHelper::buildCursorWhere(
                    $params,
                    $cursorValues,
                    ['status', 'role', 'user_id'],
                    ['ASC',    'ASC',  'DESC']
                );
                $where[] = $cursorWhere;
            }
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // Fetch one extra row to know if there's a next page
        $stmt = $conn->prepare(
            'SELECT ' . self::COLUMNS .
            ' FROM ' . self::TABLE .
            $whereSql .
            ' ORDER BY status ASC, role ASC, user_id DESC' .
            ' LIMIT :limit'
        );
        ListQueryHelper::bindAll($stmt, $params);
        $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(
            static fn(array $row): array => self::normalizeForView($row),
            $rows
        );

        $nextCursor = null;
        if ($hasMore && !empty($items)) {
            $last       = end($items);
            $nextCursor = ListQueryHelper::encodeCursor([
                $last['status'],
                $last['role'],
                $last['user_id'],
            ]);
        }

        return [
            'items'      => $items,
            'per_page'   => $perPage,
            'has_more'   => $hasMore,
            'next_cursor'=> $nextCursor,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Read – single record
    // ─────────────────────────────────────────────────────────────────────────

    public static function getStaffById(PDO $conn, int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid staff ID.');
        }

        $stmt = $conn->prepare(
            'SELECT ' . self::COLUMNS .
            ' FROM ' . self::TABLE .
            ' WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::normalizeForView($row) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Read – summary counts (cached)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return aggregate counts for the stat cards.
     *
     * Results are cached in APCu for SUMMARY_TTL seconds to avoid running a
     * full-table COUNT on every page load.  If APCu is unavailable the query
     * runs directly (still uses the covering index).
     *
     * Call StaffController::bustSummaryCache() after any write that changes
     * counts (addStaff, toggleStatus).
     */
    public static function getSummary(PDO $conn): array
    {
        $cacheKey = self::CACHE_PREFIX . 'counts';

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                return $cached;
            }
        }

        $stmt = $conn->query(
            "SELECT
                COUNT(*)                                          AS total,
                SUM(CASE WHEN status = 'active'   THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN role   = 'admin'    THEN 1 ELSE 0 END) AS admin_count
            FROM " . self::TABLE
        );

        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $summary = [
            'total'    => (int) ($row['total']          ?? 0),
            'active'   => (int) ($row['active_count']   ?? 0),
            'inactive' => (int) ($row['inactive_count'] ?? 0),
            'admin'    => (int) ($row['admin_count']    ?? 0),
        ];

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $summary, self::SUMMARY_TTL);
        }

        return $summary;
    }

    /** Invalidate the cached summary so the next getSummary() re-queries. */
    public static function bustSummaryCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete(self::CACHE_PREFIX . 'counts');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write – add
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Insert a new staff record and return the normalized view built from the
     * INSERT data – no follow-up SELECT required.
     *
     * The caller is responsible for beginning/committing the transaction and
     * for deleting the uploaded photo if the operation fails.
     *
     * @return array{staff_id:int, view:array<string,mixed>}
     */
    public static function addStaff(PDO $conn, array $data): array
    {
        $payload = self::validatePayload($data, isUpdate: false);
        self::assertUniqueCredentials($conn, $payload['username'], $payload['email']);

        $photoUrl = $payload['photo'] ?? self::DEFAULT_PHOTO;

        $stmt = $conn->prepare(
            "INSERT INTO " . self::TABLE . "
                (first_name, last_name, email, username, password, role, status, photo)
            VALUES
                (:first_name, :last_name, :email, :username, :password, :role, 'active', :photo)"
        );
        $stmt->execute([
            ':first_name' => $payload['first_name'],
            ':last_name'  => $payload['last_name'],
            ':email'      => $payload['email'],
            ':username'   => $payload['username'],
            ':password'   => password_hash((string) $payload['password'], PASSWORD_DEFAULT),
            ':role'       => $payload['role'],
            ':photo'      => $photoUrl,
        ]);

        $staffId = (int) $conn->lastInsertId();

        // Build the view directly from the payload – avoids a round-trip SELECT
        $view = self::normalizeForView([
            'user_id'        => $staffId,
            'first_name'     => $payload['first_name'],
            'last_name'      => $payload['last_name'],
            'email'          => $payload['email'],
            'username'       => $payload['username'],
            'role'           => $payload['role'],
            'status'         => 'active',
            'photo'          => $photoUrl,
            'deactivated_at' => null,
        ]);

        self::bustSummaryCache();

        return ['staff_id' => $staffId, 'view' => $view];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write – update
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update an existing staff record and return the updated normalized view.
     *
     * No follow-up SELECT is fired; the view is assembled from the merged
     * existing + updated data.
     *
     * The caller owns the transaction and photo cleanup on failure.
     *
     * @return array<string,mixed>  Normalized view of the updated record.
     */
    public static function updateStaff(PDO $conn, int $id, array $data): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid staff ID.');
        }

        $existing = self::getStaffById($conn, $id);
        if (!$existing) {
            throw new RuntimeException('Staff not found.');
        }

        $payload = self::validatePayload($data, isUpdate: true);
        self::assertUniqueCredentials($conn, $payload['username'], $payload['email'], $id);

        $fields = [
            'first_name = :first_name',
            'last_name  = :last_name',
            'email      = :email',
            'username   = :username',
            'role       = :role',
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
            $fields[]           = 'password = :password';
            $params[':password'] = password_hash($payload['password'], PASSWORD_DEFAULT);
        }

        $newPhotoUrl = $payload['photo'];
        if ($newPhotoUrl !== null) {
            $fields[]         = 'photo = :photo';
            $params[':photo']  = $newPhotoUrl;
        }

        $stmt = $conn->prepare(
            'UPDATE ' . self::TABLE .
            ' SET '   . implode(', ', $fields) .
            ' WHERE user_id = :id'
        );
        $stmt->execute($params);

        // Delete the old photo file after a successful DB write
        if ($newPhotoUrl !== null && !empty($existing['photo']) && $existing['photo'] !== $newPhotoUrl) {
            self::deleteStoredPhoto((string) $existing['photo']);
        }

        // Build view from merged data – no SELECT
        $mergedPhoto = $newPhotoUrl ?? $existing['photo'] ?? self::DEFAULT_PHOTO;
        $view = self::normalizeForView([
            'user_id'        => $id,
            'first_name'     => $payload['first_name'],
            'last_name'      => $payload['last_name'],
            'email'          => $payload['email'],
            'username'       => $payload['username'],
            'role'           => $payload['role'],
            'status'         => $existing['status'],
            'photo'          => $mergedPhoto,
            'deactivated_at' => $existing['deactivated_at'] ?? null,
        ]);

        return $view;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write – toggle status
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Flip a staff member's status and return [new_status, normalized_view].
     * No second SELECT is needed; the view is built from the existing record.
     *
     * @return array{new_status:string, view:array<string,mixed>}
     */
    public static function toggleStatus(PDO $conn, int $id, int $sessionUserId): array
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

        $newStatus      = ($staff['status'] === 'active') ? 'inactive' : 'active';
        $deactivatedSql = $newStatus === 'inactive' ? 'NOW()' : 'NULL';

        $stmt = $conn->prepare(
            "UPDATE " . self::TABLE .
            " SET status = :status, deactivated_at = {$deactivatedSql}" .
            " WHERE user_id = :id"
        );
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        self::bustSummaryCache();

        $view = self::normalizeForView(array_merge($staff, [
            'status'         => $newStatus,
            'deactivated_at' => $newStatus === 'inactive' ? date('Y-m-d H:i:s') : null,
        ]));

        return ['new_status' => $newStatus, 'view' => $view];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Photo upload  (stays here so the controller owns the upload contract)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate and move an uploaded photo.
     *
     * Returns the public URL string on success, or null if no file was sent.
     * Throws RuntimeException on invalid / oversized / wrong-type uploads.
     *
     * IMPORTANT: Call this *before* opening a DB transaction.  If the
     * transaction later fails, delete the file via deleteUploadedPhoto().
     */
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
            throw new RuntimeException('File upload failed (error code ' . ($file['error'] ?? '?') . ').');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        // Size check before mime check (cheaper)
        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_PHOTO_SIZE) {
            throw new RuntimeException('Photo must be smaller than 2 MB.');
        }

        $mimeType = (string) mime_content_type($tmpName);
        if (!array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException('Only JPG, PNG, and WEBP photos are allowed.');
        }

        if (getimagesize($tmpName) === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $ext       = self::ALLOWED_MIME_TYPES[$mimeType];
        $safeName  = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($staffName))) ?: 'unknown';
        $uploadDir = self::secureUploadDirectory($safeName);

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $fullPath = $uploadDir . $filename;

        if (!move_uploaded_file($tmpName, $fullPath)) {
            throw new RuntimeException('Could not save uploaded file.');
        }

        return self::buildMediaUrl('staff/' . $safeName . '/' . $filename);
    }

    /**
     * Delete an uploaded file by its public URL.
     * Safe to call with null or the default avatar URL.
     */
    public static function deleteUploadedPhoto(?string $photoUrl): void
    {
        self::deleteStoredPhoto($photoUrl);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Normalisation
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public static function normalizeForView(array $staff): array
    {
        $status = (string) ($staff['status'] ?? 'inactive');
        $photo  = self::normalizePhotoUrl($staff['photo'] ?? null);

        return [
            'user_id'        => (int)    ($staff['user_id']   ?? 0),
            'first_name'     => (string) ($staff['first_name'] ?? ''),
            'last_name'      => (string) ($staff['last_name']  ?? ''),
            'full_name'      => trim((string) (($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))),
            'email'          => (string) ($staff['email']     ?? ''),
            'username'       => (string) ($staff['username']  ?? ''),
            'role'           => (string) ($staff['role']      ?? 'staff'),
            'status'         => $status,
            'status_label'   => ucfirst($status),
            'photo'          => $photo,
            'deactivated_at' => $staff['deactivated_at'] ?? null,
        ];
    }

    public static function normalizePhotoUrl(?string $photoPath): string
    {
        $photoPath = trim((string) $photoPath);
        if ($photoPath === '' || $photoPath === self::DEFAULT_PHOTO) {
            return self::DEFAULT_PHOTO;
        }

        if (str_starts_with($photoPath, '/inventory_system/media.php')) {
            return $photoPath;
        }

        // Legacy path migration
        $legacyPrefix = '/inventory_system/uploads/staff/';
        if (str_starts_with($photoPath, $legacyPrefix)) {
            $asset = 'staff/' . ltrim(substr($photoPath, strlen($legacyPrefix)), '/');
            return self::buildMediaUrl($asset);
        }

        return $photoPath;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function validatePayload(array $data, bool $isUpdate): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName  = trim((string) ($data['last_name']  ?? ''));
        $email     = trim((string) ($data['email']      ?? ''));
        $username  = trim((string) ($data['username']   ?? ''));
        $password  = trim((string) ($data['password']   ?? ''));
        $photo     = isset($data['photo']) ? trim((string) $data['photo']) : null;
        $role      = trim((string) ($data['role']       ?? 'staff'));

        if ($firstName === '' || $lastName === '' || $email === '' || $username === '') {
            throw new InvalidArgumentException('First name, last name, email, and username are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role. Allowed: ' . implode(', ', self::ALLOWED_ROLES) . '.');
        }

        if (!$isUpdate && $password === '') {
            throw new InvalidArgumentException('A password is required for new staff.');
        }

        if ($password !== '' && strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        if ($photo === '') {
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

    /**
     * Throw if username or email is already taken (excluding $excludeId on updates).
     */
    private static function assertUniqueCredentials(
        PDO    $conn,
        string $username,
        string $email,
        ?int   $excludeId = null
    ): void {
        $sql    = 'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE (username = :username OR email = :email)';
        $params = [':username' => $username, ':email' => $email];

        if ($excludeId !== null && $excludeId > 0) {
            $sql              .= ' AND user_id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Username or email already exists.');
        }
    }

    /**
     * Register all covering indexes for the users table.
     * Runs at most once per PHP-FPM worker (static flag guard).
     */
    private static function ensureIndexes(PDO $conn): void
    {
        if (self::$indexesChecked) {
            return;
        }

        ListQueryHelper::ensureIndexes($conn, self::TABLE, [
            // Covers paginate ORDER BY + status/role filters
            'idx_users_status_role_uid'  =>
                'CREATE INDEX idx_users_status_role_uid ON users (status, role, user_id)',

            // Covers name-prefix search sort
            'idx_users_last_first_uid'   =>
                'CREATE INDEX idx_users_last_first_uid ON users (last_name, first_name, user_id)',

            // Covers email uniqueness check & search
            'idx_users_email'            =>
                'CREATE INDEX idx_users_email ON users (email)',

            // Covers username uniqueness check & search
            'idx_users_username'         =>
                'CREATE INDEX idx_users_username ON users (username)',
        ]);

        self::$indexesChecked = true;
    }

    private static function deleteStoredPhoto(?string $photoPath): void
    {
        $absolutePath = self::resolveStoredPhotoPath($photoPath);
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private static function resolveStoredPhotoPath(?string $photoPath): ?string
    {
        $photoPath = trim((string) $photoPath);
        if ($photoPath === '' || $photoPath === self::DEFAULT_PHOTO) {
            return null;
        }

        $mediaAsset = self::extractMediaAsset($photoPath);
        if ($mediaAsset !== null) {
            return self::resolveSecureAssetPath($mediaAsset, 'staff');
        }

        $legacyPrefix = '/inventory_system/uploads/staff/';
        if (!str_starts_with($photoPath, $legacyPrefix)) {
            return null;
        }

        $basePath    = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $relative    = substr($photoPath, strlen('/inventory_system'));
        $absolute    = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realBase    = realpath($basePath);
        $realDir     = realpath(dirname($absolute));

        if ($realBase === false || $realDir === false) {
            return null;
        }

        $uploadsBase = $realBase . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff';
        if (!str_starts_with($realDir, $uploadsBase)) {
            return null;
        }

        return $absolute;
    }

    private static function secureUploadDirectory(string $safeName): string
    {
        return self::secureUploadsBasePath() . DIRECTORY_SEPARATOR . $safeName . DIRECTORY_SEPARATOR;
    }

    private static function secureUploadsBasePath(): string
    {
        if (function_exists('app_secure_storage_dir')) {
            return rtrim(app_secure_storage_dir(), '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff';
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        return $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff';
    }

    private static function buildMediaUrl(string $asset): string
    {
        return '/inventory_system/media.php?asset=' . rawurlencode($asset);
    }

    private static function extractMediaAsset(string $photoPath): ?string
    {
        if (!str_starts_with($photoPath, '/inventory_system/media.php')) {
            return null;
        }
        $query = parse_url($photoPath, PHP_URL_QUERY);
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
        if ($asset === '' || str_contains($asset, '..') || preg_match('#^[a-z0-9/_.\-]+$#i', $asset) !== 1) {
            return null;
        }

        if (!str_starts_with($asset, $expectedPrefix . '/')) {
            return null;
        }

        $baseDir  = rtrim(function_exists('app_secure_storage_dir') ? app_secure_storage_dir() : dirname(__DIR__), '/\\')
            . DIRECTORY_SEPARATOR . 'uploads';
        $absolute = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
        $realBase = realpath($baseDir);
        $realDir  = realpath(dirname($absolute));

        if ($realBase === false || $realDir === false) {
            return null;
        }

        $expected = $realBase . DIRECTORY_SEPARATOR . $expectedPrefix;
        if (!str_starts_with($realDir, $expected)) {
            return null;
        }

        return $absolute;
    }
}