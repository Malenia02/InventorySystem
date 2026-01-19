<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';

class CategoryController {
    public static function all() {
        global $conn; // PDO connection
        global $table_categories, $category_id, $category_name, $category_status;

        $sql = "SELECT $category_id, $category_name FROM $table_categories WHERE $category_status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC); // PDO-compatible fetch
    }
}
?>
