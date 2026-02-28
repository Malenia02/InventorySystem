    <?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ==========================
    // GET ALL PRODUCTS
    // ==========================
    class ProductController {

    public static function allProducts($conn, $table_products = 'products') {
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name, s.supplier_id
            FROM {$table_products} p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            ORDER BY p.product_name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getProductById($conn, $table_products, $id) {
        $stmt = $conn->prepare("SELECT p.*, c.category_name, s.supplier_name
                                FROM {$table_products} p
                                LEFT JOIN categories c ON p.category_id = c.category_id
                                LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
                                WHERE p.product_id = :id");
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
    public static function addProduct(
        $conn,
        $table_products,
        $name,
        $category,
        $supplier,
        $price,
        $sale_price,
        $vatable,
        $initialQuantity = 0,
        $photoPath = null,
        $sku = null,
        $userId = null
    ) {
        try {
            $conn->beginTransaction();

            // 1️⃣ Insert product
            $stmt = $conn->prepare("
                INSERT INTO $table_products 
                (product_name, category_id, supplier_id, price, sale_price, vatable, photo, sku, quantity) 
                VALUES 
                (:name, :category, :supplier, :price, :sale_price, :vatable, :photo, :sku, :quantity)
            ");

            $stmt->execute([
                ':name' => $name,
                ':category' => $category,
                ':supplier' => $supplier,
                ':price' => $price,
                ':sale_price' => $sale_price,
                ':vatable' => $vatable,
                ':photo' => $photoPath,
                ':sku' => $sku,
                ':quantity' => $initialQuantity
            ]);

            $productId = $conn->lastInsertId();

            // 2️⃣ If initial quantity > 0 → create stock_in record
            if ($initialQuantity > 0) {
                $stockStmt = $conn->prepare("
                    INSERT INTO stock_in 
                    (product_id, quantity, stockin_date, user_id)
                    VALUES
                    (:product_id, :quantity, NOW(), :user_id)
                ");

                $stockStmt->execute([
                    ':product_id' => $productId,
                    ':quantity' => $initialQuantity,
                    ':user_id' => $userId
                ]);
            }

            $conn->commit();
            return $productId;

        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }




        // ==========================
        // UPDATE PRODUCT
        // ==========================
    public static function updateProduct($conn, $table_products, $id, $name, $category, $supplier, $price, $sale_price, $vatable, $photoPath = null, $sku = null) {
        $sql = "UPDATE $table_products SET product_name=:name, category_id=:category, supplier_id=:supplier, price=:price, sale_price=:sale_price, vatable=:vatable, sku=:sku";
        if($photoPath) {
            $sql .= ", photo=:photo";
        }
        $sql .= " WHERE product_id=:id";
        $stmt = $conn->prepare($sql);
        $params = [
            ':name' => $name,
            ':category' => $category,
            ':supplier' => $supplier,
            ':price' => $price,
            ':sale_price' => $sale_price,
            ':vatable' => $vatable,
            ':sku' => $sku,
            ':id' => $id
        ];
        if($photoPath) $params[':photo'] = $photoPath;
        $stmt->execute($params);
    }



        // ==========================
        // Restock Product
        // ==========================

    public static function restockProduct(
        $conn,
        $productId,
        $quantity,
        $userId = null
    ) {
        try {
            $conn->beginTransaction();

            // 1️⃣ Update product quantity
            $updateStmt = $conn->prepare("
                UPDATE products 
                SET quantity = quantity + :quantity 
                WHERE product_id = :id
            ");

            $updateStmt->execute([
                ':quantity' => $quantity,
                ':id' => $productId
            ]);

            // 2️⃣ Insert stock_in record
            $stockStmt = $conn->prepare("
                INSERT INTO stock_in 
                (product_id, quantity, stockin_date, user_id)
                VALUES
                (:product_id, :quantity, NOW(), :user_id)
            ");

            $stockStmt->execute([
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':user_id' => $userId
            ]);

            $conn->commit();
            return true;

        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
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
