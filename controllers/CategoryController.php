<?php
class CategoryController {

    // ==========================
    // GET ALL CATEGORIES
    // ==========================
    public static function all($conn, $table = 'categories') {
        $stmt = $conn->prepare("SELECT * FROM {$table} ORDER BY category_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==========================
    // ADD CATEGORY
    // ==========================
    public static function addCategory($conn, $table, $name) {
        $stmt = $conn->prepare("INSERT INTO {$table} (category_name, status) VALUES (?, 'active')");
        $stmt->execute([$name]);
        return $conn->lastInsertId();
    }

    // ==========================
    // UPDATE CATEGORY
    // ==========================
    public static function updateCategory($conn, $table, $id, $name) {
        $stmt = $conn->prepare("UPDATE {$table} SET category_name = ? WHERE category_id = ?");
        return $stmt->execute([$name, $id]);
    }

    // ==========================
    // GET CATEGORY BY ID
    // ==========================
    public static function getCategoryById($conn, $table, $id) {
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE category_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ==========================
    // TOGGLE STATUS
    // ==========================
    public static function toggleStatus($conn, $table, $id) {
        $cat = self::getCategoryById($conn, $table, $id);
        if (!$cat) return false;

        $newStatus = $cat['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE category_id = ?");
        $stmt->execute([$newStatus, $id]);

        return $newStatus;
    }
}
