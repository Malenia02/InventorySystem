<?php
/**
 * controllers/ProductController.php
 * Handles all product-related DB operations.
 */

if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}

class ProductController
{
    private const TABLE_PRODUCTS = 'products';
    private const UPLOAD_BASE    = '/inventory_system/assets/uploads/products/';
    private const ALLOWED_TYPES  = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_FILE_SIZE  = 2097152; // 2MB

    // ================================================================
    // GET ALL PRODUCTS
    // ================================================================
    public static function allProducts(PDO $conn): array
    {
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name, s.supplier_id
            FROM " . self::TABLE_PRODUCTS . " p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    // GET PRODUCT BY ID
    // ================================================================
    public static function getProductById(PDO $conn, int $id): array|false
    {
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name, s.supplier_id
            FROM " . self::TABLE_PRODUCTS . " p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ================================================================
    // POS ACTIVE PRODUCTS
    // ================================================================
    public static function activeProductsForPOS(PDO $conn): array
    {
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name
            FROM " . self::TABLE_PRODUCTS . " p
            INNER JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.status = 'active'
              AND c.status = 'active'
            ORDER BY p.product_name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    // HANDLE PHOTO UPLOAD
    // ================================================================
    public static function handlePhotoUpload(string $fileInputName, string $productName = 'unknown'): ?string
    {
        if (empty($_FILES[$fileInputName]['name'])) {
            return null;
        }

        $tmpFile  = $_FILES[$fileInputName]['tmp_name'];
        $fileSize = (int)($_FILES[$fileInputName]['size'] ?? 0);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileType = $finfo->file($tmpFile);

        if (!in_array($fileType, self::ALLOWED_TYPES, true)) {
            throw new Exception('Invalid file type. Only JPG, PNG, and WebP are allowed.');
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new Exception('File too large. Maximum size is 2MB.');
        }

        $safeName  = preg_replace('/[^a-z0-9_-]/', '_', strtolower($productName));
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . self::UPLOAD_BASE . $safeName . '/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }

        $ext = match ($fileType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => throw new Exception('Unsupported image type.')
        };

        $photoName = uniqid('photo_', true) . '.' . $ext;
        $target    = $uploadDir . $photoName;

        if (!move_uploaded_file($tmpFile, $target)) {
            throw new Exception('Failed to save uploaded file.');
        }

        return self::UPLOAD_BASE . $safeName . '/' . $photoName;
    }

    // ================================================================
    // ADD PRODUCT
    // quantity starts at 0
    // initial stock goes through stock_in trigger
    // ================================================================
    public static function addProduct(PDO $conn, array $data): int
    {
        $name       = trim($data['name'] ?? '');
        $categoryId = (int)($data['category_id'] ?? 0);
        $supplierId = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
        $price      = (float)($data['price'] ?? 0);
        $salePrice  = ($data['sale_price'] !== '' && $data['sale_price'] !== null)
            ? (float)$data['sale_price']
            : null;
        $vatable    = (int)($data['vatable'] ?? 0);
        $photo      = $data['photo'] ?? null;
        $sku        = trim($data['sku'] ?? '');
        $reorder    = (int)($data['reorder_level'] ?? 5);
        $initialQty = max(0, (int)($data['initial_quantity'] ?? 0));
        $userId     = $data['user_id'] ?? null;

        if ($name === '') {
            throw new Exception('Product name is required.');
        }

        if ($categoryId <= 0) {
            throw new Exception('Valid category is required.');
        }

        if ($price < 0) {
            throw new Exception('Price cannot be negative.');
        }

        if ($salePrice !== null && $salePrice < 0) {
            throw new Exception('Sale price cannot be negative.');
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO " . self::TABLE_PRODUCTS . "
                    (product_name, category_id, supplier_id, sku, price, vatable, on_sale, sale_price, quantity, photo, reorder_level, status)
                VALUES
                    (:name, :category_id, :supplier_id, :sku, :price, :vatable, :on_sale, :sale_price, 0, :photo, :reorder_level, 'inactive')
            ");

            $stmt->execute([
                ':name'          => $name,
                ':category_id'   => $categoryId,
                ':supplier_id'   => $supplierId,
                ':sku'           => ($sku !== '' ? $sku : null),
                ':price'         => $price,
                ':vatable'       => $vatable,
                ':on_sale'       => ($salePrice !== null ? 1 : 0),
                ':sale_price'    => $salePrice,
                ':photo'         => $photo,
                ':reorder_level' => $reorder,
            ]);

            $productId = (int)$conn->lastInsertId();

            if ($initialQty > 0) {
                $stockStmt = $conn->prepare("
                    INSERT INTO stock_in (product_id, quantity, stockin_date, user_id)
                    VALUES (:product_id, :quantity, NOW(), :user_id)
                ");
                $stockStmt->execute([
                    ':product_id' => $productId,
                    ':quantity'   => $initialQty,
                    ':user_id'    => $userId,
                ]);
            }

            $conn->commit();
            return $productId;

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // ================================================================
    // UPDATE PRODUCT
    // ================================================================
    public static function updateProduct(PDO $conn, int $id, array $data): bool
    {
        $name       = trim($data['name'] ?? '');
        $categoryId = (int)($data['category_id'] ?? 0);
        $supplierId = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
        $price      = (float)($data['price'] ?? 0);
        $salePrice  = ($data['sale_price'] !== '' && $data['sale_price'] !== null)
            ? (float)$data['sale_price']
            : null;
        $vatable    = (int)($data['vatable'] ?? 0);
        $reorder    = (int)($data['reorder_level'] ?? 5);
        $sku        = trim($data['sku'] ?? '');
        $photo      = $data['photo'] ?? null;

        if ($id <= 0) {
            throw new Exception('Invalid product ID.');
        }

        if ($name === '') {
            throw new Exception('Product name is required.');
        }

        $sql = "
            UPDATE " . self::TABLE_PRODUCTS . " SET
                product_name  = :name,
                category_id   = :category_id,
                supplier_id   = :supplier_id,
                sku           = :sku,
                price         = :price,
                sale_price    = :sale_price,
                on_sale       = :on_sale,
                vatable       = :vatable,
                reorder_level = :reorder_level
        ";

        $params = [
            ':name'          => $name,
            ':category_id'   => $categoryId,
            ':supplier_id'   => $supplierId,
            ':sku'           => ($sku !== '' ? $sku : null),
            ':price'         => $price,
            ':sale_price'    => $salePrice,
            ':on_sale'       => ($salePrice !== null ? 1 : 0),
            ':vatable'       => $vatable,
            ':reorder_level' => $reorder,
            ':id'            => $id,
        ];

        if (!empty($photo)) {
            $sql .= ", photo = :photo";
            $params[':photo'] = $photo;
        }

        $sql .= " WHERE product_id = :id";

        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    }

    // ================================================================
    // RESTOCK PRODUCT
    // ================================================================
    public static function restockProduct(PDO $conn, int $productId, int $quantity, ?int $userId = null): bool
    {
        if ($productId <= 0) {
            throw new Exception('Invalid product ID.');
        }

        if ($quantity <= 0) {
            throw new Exception('Restock quantity must be greater than zero.');
        }

        try {
            $conn->beginTransaction();

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
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // ================================================================
    // STOCK OUT PRODUCT
    // ================================================================
    public static function stockOutProduct(PDO $conn, int $productId, int $quantity, string $reason = '', ?int $userId = null): bool
    {
        if ($productId <= 0) {
            throw new Exception('Invalid product ID.');
        }

        if ($quantity <= 0) {
            throw new Exception('Stock-out quantity must be greater than zero.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'No reason provided';
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO stock_out (product_id, quantity, reason, stockout_date, user_id)
                VALUES (:product_id, :quantity, :reason, NOW(), :user_id)
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity'   => $quantity,
                ':reason'     => $reason,
                ':user_id'    => $userId,
            ]);

            $conn->commit();
            return true;

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // ================================================================
    // TOGGLE STATUS
    // ================================================================
    public static function toggleStatus(PDO $conn, int $id): string|false
    {
        $product = self::getProductById($conn, $id);
        if (!$product) {
            return false;
        }

        $newStatus = ($product['status'] === 'active') ? 'inactive' : 'active';

        $stmt = $conn->prepare("
            UPDATE " . self::TABLE_PRODUCTS . "
            SET status = :status
            WHERE product_id = :id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $id
        ]);

        return $newStatus;
    }
}