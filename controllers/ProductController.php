<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================
// GET ALL PRODUCTS
// ==========================
class ProductController {

    public static function allProducts($conn, $table_products = 'products') {
        $stmt = $conn->prepare("SELECT p.*, c.category_name 
                                FROM {$table_products} p 
                                LEFT JOIN categories c ON p.category_id = c.category_id
                                ORDER BY p.product_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getProductById($conn, $table_products, $id) {
        $stmt = $conn->prepare("SELECT * FROM {$table_products} WHERE product_id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function handlePhotoUpload($fileInputName, $productName = 'unknown') {
        if (empty($_FILES[$fileInputName]['name'])) return null;

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $maxFileSize = 2 * 1024 * 1024; // 2MB

        $fileType = mime_content_type($_FILES[$fileInputName]['tmp_name']);
        $fileSize = $_FILES[$fileInputName]['size'];

        if (!in_array($fileType, $allowedTypes)) throw new Exception("Invalid file type.");
        if ($fileSize > $maxFileSize) throw new Exception("File too large.");

        $safeName = preg_replace("/[^a-zA-Z0-9_-]/", "_", strtolower($productName));
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/inventory_system/assets/uploads/products/{$safeName}/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $ext = pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION);
        $photoName = uniqid('photo_', true) . '.' . $ext;

        if (!move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $uploadDir . $photoName)) {
            throw new Exception("Failed to move uploaded file.");
        }

        return "/inventory_system/assets/uploads/products/{$safeName}/" . $photoName;
    }

    // ==========================
    // ADD PRODUCT
    // ==========================
    public static function addProduct($conn, $table_products, $name, $category, $price, $sale_price, $vatable, $photoPath) {
        $stmt = $conn->prepare("INSERT INTO {$table_products} 
            (product_name, category_id, price, sale_price, vatable, photo, status)
            VALUES (:name, :category, :price, :sale_price, :vatable, :photo, 'active')");
        $stmt->execute([
            'name' => $name,
            'category' => $category,
            'price' => $price,
            'sale_price' => $sale_price ?: null,
            'vatable' => $vatable,
            'photo' => $photoPath
        ]);
        return $conn->lastInsertId();
    }

    // ==========================
    // UPDATE PRODUCT
    // ==========================
    public static function updateProduct($conn, $table_products, $id, $name, $category, $price, $sale_price, $vatable, $photoPath = null) {
        $fields = "product_name = :name, category_id = :category, price = :price, sale_price = :sale_price, vatable = :vatable";
        $params = [
            'name' => $name,
            'category' => $category,
            'price' => $price,
            'sale_price' => $sale_price ?: null,
            'vatable' => $vatable,
            'id' => $id
        ];

        if(!empty($photoPath)){
            $fields .= ", photo = :photo";
            $params['photo'] = $photoPath;
        }

        $stmt = $conn->prepare("UPDATE {$table_products} SET {$fields} WHERE product_id = :id");
        return $stmt->execute($params);
    }

    // ==========================
    // TOGGLE STATUS
    // ==========================
    public static function toggleStatus($conn, $table_products, $id) {
        $product = self::getProductById($conn, $table_products, $id);
        if(!$product) return false;

        $newStatus = $product['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE {$table_products} SET status = :status WHERE product_id = :id");
        $stmt->execute(['status' => $newStatus, 'id' => $id]);
        return $newStatus;
    }

    // ==========================
    // GET ALL PRODUCTS FOR POS (INCLUDE INACTIVE)
    // ==========================
    public static function allProductsForPOS($conn, $table_products = 'products') {
        $stmt = $conn->prepare("SELECT p.*, c.category_name 
                                FROM {$table_products} p 
                                LEFT JOIN categories c ON p.category_id = c.category_id
                                ORDER BY p.product_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==========================
    // GET ONLY ACTIVE PRODUCTS FOR POS
    // ==========================
    public static function activeProductsForPOS($conn, $table_products = 'products') {
        $stmt = $conn->prepare("SELECT p.*, c.category_name 
                                FROM {$table_products} p 
                                LEFT JOIN categories c ON p.category_id = c.category_id
                                WHERE p.status = 'active'
                                ORDER BY p.product_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}
