<?php
/**
 * controllers/ProductController.php
 * Handles all product-related DB operations.
 */

// Prevent direct browser access
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

class ProductController
{
    // ================================================================
    //  CONSTANTS
    // ================================================================
    private const UPLOAD_BASE    = '/inventory_system/assets/uploads/products/';
    private const ALLOWED_TYPES  = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_FILE_SIZE  = 2097152; // 2MB

    // ================================================================
    //  GET ALL PRODUCTS (admin table)
    // ================================================================
    public static function allProducts(PDO $conn, string $table = 'products'): array
    {
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name, s.supplier_id
            FROM {$table} p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers  s ON p.supplier_id  = s.supplier_id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  GET PRODUCT BY ID
    // ================================================================
    public static function getProductById(PDO $conn, string $table, int $id): array|false
    {
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name, s.supplier_id
            FROM {$table} p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers  s ON p.supplier_id  = s.supplier_id
            WHERE p.product_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  POS — active products only
    //  $activeOnly = false → returns all products (for admin)
    //  $activeOnly = true  → returns active only (for POS)
    // ================================================================
    public static function activeProductsForPOS(PDO $conn, string $table = 'products', bool $activeOnly = true): array
    {
        $where = $activeOnly ? "WHERE p.status = 'active'" : '';
        $stmt  = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name
            FROM {$table} p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers  s ON p.supplier_id  = s.supplier_id
            {$where}
            ORDER BY p.product_id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  HANDLE PHOTO UPLOAD
    //  Returns the URL-safe path string, or null if no file uploaded.
    //  Throws Exception on invalid file.
    // ================================================================
    public static function handlePhotoUpload(string $fileInputName, string $productName = 'unknown'): ?string
    {
        if (empty($_FILES[$fileInputName]['name'])) return null;

        $tmpFile  = $_FILES[$fileInputName]['tmp_name'];
        $fileType = mime_content_type($tmpFile);
        $fileSize = $_FILES[$fileInputName]['size'];

        if (!in_array($fileType, self::ALLOWED_TYPES, true)) {
            throw new Exception('Invalid file type. Only JPG, PNG, and WebP are allowed.');
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new Exception('File too large. Maximum size is 2MB.');
        }

        $safeName  = preg_replace('/[^a-z0-9_-]/', '_', strtolower($productName));
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . self::UPLOAD_BASE . "{$safeName}/";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext       = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        $photoName = uniqid('photo_', true) . '.' . $ext;

        if (!move_uploaded_file($tmpFile, $uploadDir . $photoName)) {
            throw new Exception('Failed to save uploaded file.');
        }

        return self::UPLOAD_BASE . "{$safeName}/{$photoName}";
    }

    // ================================================================
    //  ADD PRODUCT
    //
    //  $data = [
    //      'name'             => string   (required)
    //      'category_id'      => int      (required)
    //      'supplier_id'      => int      (required)
    //      'price'            => float    (required)
    //      'sale_price'       => float|null
    //      'vatable'          => int      (0 or 1)
    //      'initial_quantity' => int
    //      'reorder_level'    => int
    //      'sku'              => string|null
    //      'photo'            => string|null  (path from handlePhotoUpload)
    //      'user_id'          => int|null     (for stock_in log)
    //  ]
    // ================================================================
    public static function addProduct(PDO $conn, string $table, array $data): int
    {
        $initialQty = max(0, (int)($data['initial_quantity'] ?? 0));

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO {$table}
                    (product_name, category_id, supplier_id, price, sale_price,
                     vatable, photo, sku, quantity, reorder_level, status)
                VALUES
                    (:name, :category, :supplier, :price, :sale_price,
                     :vatable, :photo, :sku, :quantity, :reorder, 'inactive')
            ");

            $stmt->execute([
                ':name'       => trim($data['name']),
                ':category'   => (int)$data['category_id'],
                ':supplier'   => (int)$data['supplier_id'],
                ':price'      => (float)$data['price'],
                ':sale_price' => !empty($data['sale_price']) ? (float)$data['sale_price'] : null,
                ':vatable'    => (int)($data['vatable'] ?? 0),
                ':photo'      => $data['photo'] ?? null,
                ':sku'        => trim($data['sku'] ?? ''),
                ':quantity'   => $initialQty,
                ':reorder'    => (int)($data['reorder_level'] ?? 5),
            ]);

            $productId = (int) $conn->lastInsertId();

            // Insert initial stock_in record if quantity > 0
            // trg_stock_in_after_insert will update products.quantity automatically
            // so we set quantity = 0 above and let the trigger handle it
            if ($initialQty > 0) {
                $stockStmt = $conn->prepare("
                    INSERT INTO stock_in (product_id, quantity, stockin_date, user_id)
                    VALUES (:product_id, :quantity, NOW(), :user_id)
                ");
                $stockStmt->execute([
                    ':product_id' => $productId,
                    ':quantity'   => $initialQty,
                    ':user_id'    => $data['user_id'] ?? null,
                ]);
            }

            $conn->commit();
            return $productId;

        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ================================================================
    //  UPDATE PRODUCT
    // ================================================================
    public static function updateProduct(PDO $conn, string $table, int $id, array $data): bool
    {
        $sql = "UPDATE {$table} SET
                    product_name  = :name,
                    category_id   = :category,
                    supplier_id   = :supplier,
                    price         = :price,
                    sale_price    = :sale_price,
                    vatable       = :vatable,
                    reorder_level = :reorder,
                    sku           = :sku";

        if (!empty($data['photo'])) {
            $sql .= ", photo = :photo";
        }

        $sql .= " WHERE product_id = :id";

        $params = [
            ':name'       => trim($data['name']),
            ':category'   => (int)$data['category_id'],
            ':supplier'   => (int)$data['supplier_id'],
            ':price'      => (float)$data['price'],
            ':sale_price' => !empty($data['sale_price']) ? (float)$data['sale_price'] : null,
            ':vatable'    => (int)($data['vatable'] ?? 0),
            ':reorder'    => (int)($data['reorder_level'] ?? 5),
            ':sku'        => trim($data['sku'] ?? ''),
            ':id'         => $id,
        ];

        if (!empty($data['photo'])) {
            $params[':photo'] = $data['photo'];
        }

        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    }

    // ================================================================
    //  RESTOCK PRODUCT
    //  Inserts into stock_in only.
    //  trg_stock_in_after_insert handles:
    //    → UPDATE products.quantity automatically
    //    → INSERT into stock_audit_log
    // ================================================================
    public static function restockProduct(
        PDO $conn,
        string $table,
        int $productId,
        int $quantity,
        ?int $userId = null
    ): bool {
        if ($quantity <= 0) {
            throw new Exception('Restock quantity must be greater than zero.');
        }

        try {
            $conn->beginTransaction();

            // Insert into stock_in — trigger handles everything else
            $stmt = $conn->prepare("
                INSERT INTO stock_in (product_id, quantity, stockin_date, user_id)
                VALUES (:product_id, :quantity, NOW(), :user_id)
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity'   => $quantity,
                ':user_id'    => $userId,
            ]);

            $conn->commit();
            return true;

        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ================================================================
    //  TOGGLE STATUS (active ↔ inactive)
    //  Returns: 'active' | 'inactive' | false (not found)
    // ================================================================
    public static function toggleStatus(PDO $conn, string $table, int $id): string|false
    {
        $product = self::getProductById($conn, $table, $id);
        if (!$product) return false;

        $newStatus = $product['status'] === 'active' ? 'inactive' : 'active';

        $stmt = $conn->prepare("
            UPDATE {$table} SET status = :status WHERE product_id = :id
        ");
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        return $newStatus;
    }
}