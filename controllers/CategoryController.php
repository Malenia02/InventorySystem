<?php
/**
 * CategoryController.php
 * Handles all category-related DB operations.
 */

// Prevent direct browser access
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}
class CategoryController
{
    // ================================================================
    //  GET ALL CATEGORIES
    //  $status = null    → all categories
    //  $status = 'active' → active only
    //  $status = 'inactive' → inactive only
    // ================================================================
    public static function all(PDO $conn, string $table = 'categories', ?string $status = null): array
    {
        $sql = "SELECT * FROM {$table}";

        if ($status !== null) {
            $sql .= " WHERE status = :status";
        }

        $sql .= " ORDER BY category_name ASC";

        $stmt = $conn->prepare($sql);

        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  GET CATEGORY BY ID
    // ================================================================
    public static function getCategoryById(PDO $conn, string $table, int $id): array|false
    {
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE category_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  ADD CATEGORY
    //  Returns: int (new ID) | 'duplicate' | false
    // ================================================================
    public static function addCategory(PDO $conn, string $table, string $name): int|string|false
    {
        $name = trim($name);

        if (empty($name)) {
            return false;
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO {$table} (category_name, status)
                VALUES (?, 'active')
            ");
            $stmt->execute([$name]);
            return (int) $conn->lastInsertId();

        } catch (PDOException $e) {
            // Unique constraint violation — duplicate category name
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
    }

    // ================================================================
    //  UPDATE CATEGORY
    //  Returns: true | 'duplicate' | false
    // ================================================================
    public static function updateCategory(PDO $conn, string $table, int $id, string $name): bool|string
    {
        $name = trim($name);

        if (empty($name)) {
            return false;
        }

        try {
            $stmt = $conn->prepare("
                UPDATE {$table}
                SET category_name = ?
                WHERE category_id = ?
            ");
            return $stmt->execute([$name, $id]);

        } catch (PDOException $e) {
            // Duplicate name on update
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
    }

    // ================================================================
    //  TOGGLE STATUS (active ↔ inactive)
    //  Returns: 'active' | 'inactive' | false (not found)
    // ================================================================
    public static function toggleStatus(PDO $conn, string $table, int $id): string|false
    {
        $cat = self::getCategoryById($conn, $table, $id);

        if (!$cat) {
            return false;
        }

        $newStatus = $cat['status'] === 'active' ? 'inactive' : 'active';

        $stmt = $conn->prepare("
            UPDATE {$table}
            SET status = ?
            WHERE category_id = ?
        ");
        $stmt->execute([$newStatus, $id]);

        return $newStatus;
    }

    // ================================================================
    //  COUNT CATEGORIES
    //  Useful for dashboards
    // ================================================================
    public static function count(PDO $conn, string $table, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) FROM {$table}";

        if ($status !== null) {
            $sql .= " WHERE status = :status";
        }

        $stmt = $conn->prepare($sql);

        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }

        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}