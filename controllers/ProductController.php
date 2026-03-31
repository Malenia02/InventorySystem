<?php
declare(strict_types=1);

require_once __DIR__ . '/SubcategoryController.php';

final class ProductController
{
    private const TABLE = 'products';
    private const DEFAULT_REORDER_LEVEL = 5;
    private const MAX_FILE_SIZE = 2097152; // 2MB
    private const MAX_BULK_FILE_SIZE = 5242880; // 5MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    private const ALLOWED_PRODUCT_STATUSES = ['active', 'inactive'];

    public static function allProducts(PDO $conn): array
    {
        SubcategoryController::ensureSchema($conn);
        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                p.subcategory_id,
                p.supplier_id,
                p.sku,
                p.price,
                p.sale_price,
                p.on_sale,
                p.vatable,
                p.quantity,
                p.photo,
                p.reorder_level,
                p.status,
                p.created_at,
                c.category_name,
                sc.subcategory_name,
                s.supplier_name
            FROM " . self::TABLE . " p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN subcategories sc ON p.subcategory_id = sc.subcategory_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            ORDER BY p.created_at DESC, p.product_id DESC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getProductById(PDO $conn, int $id): ?array
    {
        SubcategoryController::ensureSchema($conn);
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                p.subcategory_id,
                p.supplier_id,
                p.sku,
                p.price,
                p.sale_price,
                p.on_sale,
                p.vatable,
                p.quantity,
                p.photo,
                p.reorder_level,
                p.status,
                p.created_at,
                c.category_name,
                sc.subcategory_name,
                s.supplier_name
            FROM " . self::TABLE . " p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN subcategories sc ON p.subcategory_id = sc.subcategory_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        return $product ?: null;
    }

    public static function activeProductsForPOS(PDO $conn): array
    {
        SubcategoryController::ensureSchema($conn);
        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                p.subcategory_id,
                p.supplier_id,
                p.sku,
                p.price,
                p.sale_price,
                p.on_sale,
                p.vatable,
                p.quantity,
                p.photo,
                p.reorder_level,
                p.status,
                c.category_name,
                sc.subcategory_name,
                s.supplier_name
            FROM " . self::TABLE . " p
            INNER JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN subcategories sc ON p.subcategory_id = sc.subcategory_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.status = 'active'
              AND c.status = 'active'
              AND p.quantity > 0
            ORDER BY p.product_name ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function handlePhotoUpload(string $fileInputName, string $productName = 'unknown'): ?string
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

        $tmpFile = (string) ($file['tmp_name'] ?? '');
        if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File too large. Maximum size is 2MB.');
        }

        $mimeType = mime_content_type($tmpFile);
        if (!is_string($mimeType) || !array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException('Invalid file type. Only JPG, PNG, and WEBP are allowed.');
        }

        if (getimagesize($tmpFile) === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $safeName = preg_replace('/[^a-z0-9_-]/', '_', strtolower(trim($productName))) ?: 'unknown';
        $extension = self::ALLOWED_MIME_TYPES[$mimeType];

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $uploadDir = $basePath . '/uploads/products/' . $safeName . '/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create upload directory.');
        }

        $photoName = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $uploadDir . $photoName;

        if (!move_uploaded_file($tmpFile, $target)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }

        return '/inventory_system/uploads/products/' . $safeName . '/' . $photoName;
    }

    public static function addProduct(PDO $conn, array $data): int
    {
        SubcategoryController::ensureSchema($conn);
        $payload = self::validatePayload($data, false);

        self::assertCategoryExists($conn, $payload['category_id']);
        if ($payload['subcategory_id'] !== null) {
            SubcategoryController::assertExistsForCategory($conn, $payload['subcategory_id'], $payload['category_id']);
        }
        if ($payload['supplier_id'] !== null) {
            self::assertSupplierExists($conn, $payload['supplier_id']);
        }
        if ($payload['sku'] !== null) {
            self::assertUniqueSku($conn, $payload['sku']);
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO " . self::TABLE . " (
                    product_name,
                    category_id,
                    subcategory_id,
                    supplier_id,
                    sku,
                    price,
                    vatable,
                    on_sale,
                    sale_price,
                    quantity,
                    photo,
                    reorder_level,
                    status
                ) VALUES (
                    :name,
                    :category_id,
                    :subcategory_id,
                    :supplier_id,
                    :sku,
                    :price,
                    :vatable,
                    :on_sale,
                    :sale_price,
                    0,
                    :photo,
                    :reorder_level,
                    :status
                )
            ");

            $stmt->execute([
                ':name'          => $payload['name'],
                ':category_id'   => $payload['category_id'],
                ':subcategory_id'=> $payload['subcategory_id'],
                ':supplier_id'   => $payload['supplier_id'],
                ':sku'           => $payload['sku'],
                ':price'         => $payload['price'],
                ':vatable'       => $payload['vatable'],
                ':on_sale'       => $payload['sale_price'] !== null ? 1 : 0,
                ':sale_price'    => $payload['sale_price'],
                ':photo'         => $payload['photo'],
                ':reorder_level' => $payload['reorder_level'],
                ':status'        => $payload['status'],
            ]);

            $productId = (int) $conn->lastInsertId();

            if ($payload['initial_quantity'] > 0) {
                $stockStmt = $conn->prepare("
                    INSERT INTO stock_in (product_id, quantity, stockin_date, user_id)
                    VALUES (:product_id, :quantity, NOW(), :user_id)
                ");
                $stockStmt->execute([
                    ':product_id' => $productId,
                    ':quantity'   => $payload['initial_quantity'],
                    ':user_id'    => $payload['user_id'],
                ]);
            }

            $conn->commit();
            return $productId;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function updateProduct(PDO $conn, int $id, array $data): bool
    {
        SubcategoryController::ensureSchema($conn);
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        $existing = self::getProductById($conn, $id);
        if (!$existing) {
            throw new RuntimeException('Product not found.');
        }

        $payload = self::validatePayload($data, true);

        self::assertCategoryExists($conn, $payload['category_id']);
        if ($payload['subcategory_id'] !== null) {
            SubcategoryController::assertExistsForCategory($conn, $payload['subcategory_id'], $payload['category_id']);
        }
        if ($payload['supplier_id'] !== null) {
            self::assertSupplierExists($conn, $payload['supplier_id']);
        }
        if ($payload['sku'] !== null) {
            self::assertUniqueSku($conn, $payload['sku'], $id);
        }

        $sql = "
            UPDATE " . self::TABLE . " SET
                product_name  = :name,
                category_id   = :category_id,
                subcategory_id = :subcategory_id,
                supplier_id   = :supplier_id,
                sku           = :sku,
                price         = :price,
                sale_price    = :sale_price,
                on_sale       = :on_sale,
                vatable       = :vatable,
                reorder_level = :reorder_level
        ";

        $params = [
            ':name'          => $payload['name'],
            ':category_id'   => $payload['category_id'],
            ':subcategory_id'=> $payload['subcategory_id'],
            ':supplier_id'   => $payload['supplier_id'],
            ':sku'           => $payload['sku'],
            ':price'         => $payload['price'],
            ':sale_price'    => $payload['sale_price'],
            ':on_sale'       => $payload['sale_price'] !== null ? 1 : 0,
            ':vatable'       => $payload['vatable'],
            ':reorder_level' => $payload['reorder_level'],
            ':id'            => $id,
        ];

        if ($payload['photo'] !== null) {
            $sql .= ", photo = :photo";
            $params[':photo'] = $payload['photo'];
        }

        $sql .= " WHERE product_id = :id";

        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    }

    public static function restockProduct(PDO $conn, int $productId, int $quantity, ?int $userId = null): bool
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Restock quantity must be greater than zero.');
        }

        self::assertProductExists($conn, $productId);

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
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function stockOutProduct(PDO $conn, int $productId, int $quantity, string $reason = '', ?int $userId = null): bool
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Stock-out quantity must be greater than zero.');
        }

        $product = self::getProductById($conn, $productId);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        $currentQty = (int) ($product['quantity'] ?? 0);
        if ($quantity > $currentQty) {
            throw new RuntimeException('Insufficient stock quantity.');
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
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function toggleStatus(PDO $conn, int $id): string|false
    {
        $product = self::getProductById($conn, $id);
        if (!$product) {
            return false;
        }

        $newStatus = (($product['status'] ?? 'inactive') === 'active') ? 'inactive' : 'active';

        $stmt = $conn->prepare("
            UPDATE " . self::TABLE . "
            SET status = :status
            WHERE product_id = :id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $id,
        ]);

        return $newStatus;
    }

    public static function parseBulkUploadFile(string $fileInputName): array
    {
        if (
            !isset($_FILES[$fileInputName]) ||
            !is_array($_FILES[$fileInputName]) ||
            ($_FILES[$fileInputName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            throw new InvalidArgumentException('Please choose a CSV file to upload.');
        }

        $file = $_FILES[$fileInputName];

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Bulk upload failed while receiving the file.');
        }

        $tmpFile = (string) ($file['tmp_name'] ?? '');
        if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
            throw new RuntimeException('Invalid uploaded CSV file.');
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0) {
            throw new RuntimeException('The uploaded CSV file is empty.');
        }

        if ($fileSize > self::MAX_BULK_FILE_SIZE) {
            throw new RuntimeException('CSV file is too large. Maximum size is 5MB.');
        }

        $originalName = strtolower((string) ($file['name'] ?? ''));
        if (pathinfo($originalName, PATHINFO_EXTENSION) !== 'csv') {
            throw new RuntimeException('Only CSV files are supported for bulk upload.');
        }

        $handle = fopen($tmpFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        try {
            $headerRow = fgetcsv($handle);
            if ($headerRow === false) {
                throw new RuntimeException('The CSV file must include a header row.');
            }

            $headers = array_map(
                static fn($header): string => self::normalizeCsvHeader((string) $header),
                $headerRow
            );

            if (!in_array('product_name', $headers, true)) {
                throw new RuntimeException('The CSV header must include a product_name column.');
            }

            if (!in_array('category', $headers, true) && !in_array('category_id', $headers, true) && !in_array('category_name', $headers, true)) {
                throw new RuntimeException('The CSV header must include category, category_name, or category_id.');
            }

            if (!in_array('price', $headers, true)) {
                throw new RuntimeException('The CSV header must include a price column.');
            }

            $rows = [];
            $rowNumber = 1;

            while (($csvRow = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($csvRow === [null] || self::isBlankCsvRow($csvRow)) {
                    continue;
                }

                $values = [];
                foreach ($headers as $index => $header) {
                    $values[$header] = isset($csvRow[$index]) ? trim((string) $csvRow[$index]) : '';
                }

                $rows[] = [
                    'row_number' => $rowNumber,
                    'data'       => $values,
                ];
            }

            if ($rows === []) {
                throw new RuntimeException('The CSV file does not contain any product rows.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public static function bulkUploadProducts(PDO $conn, array $rows, ?int $userId = null): array
    {
        $createdIds = [];
        $createdNames = [];
        $errors = [];

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['row_number'] ?? 0);
            $rowData = is_array($row['data'] ?? null) ? $row['data'] : [];

            try {
                $productId = self::addProduct($conn, [
                    'name'             => trim((string) ($rowData['product_name'] ?? $rowData['name'] ?? '')),
                    'category_id'      => self::resolveCategoryReference($conn, (string) self::firstFilledValue($rowData, ['category_id', 'category', 'category_name'])),
                    'supplier_id'      => self::resolveSupplierReference($conn, (string) self::firstFilledValue($rowData, ['supplier_id', 'supplier', 'supplier_name'])),
                    'sku'              => trim((string) ($rowData['sku'] ?? '')),
                    'price'            => self::requiredNumericCsvValue($rowData['price'] ?? '', 'Price is required.'),
                    'sale_price'       => self::nullableNumericCsvValue($rowData['sale_price'] ?? ''),
                    'vatable'          => self::parseBooleanCsvValue($rowData['vatable'] ?? ''),
                    'initial_quantity' => (int) self::normalizeNumericCsvValue($rowData['initial_quantity'] ?? $rowData['quantity'] ?? 0),
                    'reorder_level'    => (int) (self::nullableNumericCsvValue($rowData['reorder_level'] ?? '') ?? self::DEFAULT_REORDER_LEVEL),
                    'status'           => self::parseProductStatus($rowData['status'] ?? ''),
                    'photo'            => null,
                    'user_id'          => $userId,
                ]);

                $createdIds[] = $productId;
                $createdNames[] = trim((string) ($rowData['product_name'] ?? $rowData['name'] ?? ('Row ' . $rowNumber)));
            } catch (Throwable $e) {
                $errors[] = [
                    'row'     => $rowNumber,
                    'product' => trim((string) ($rowData['product_name'] ?? $rowData['name'] ?? '')),
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return [
            'created_ids'   => $createdIds,
            'created_names' => $createdNames,
            'errors'        => $errors,
        ];
    }

    private static function validatePayload(array $data, bool $isUpdate): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $categoryId = (int) ($data['category_id'] ?? 0);
        $subcategoryId = !empty($data['subcategory_id']) ? (int) $data['subcategory_id'] : null;
        $supplierId = !empty($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        $price = (float) ($data['price'] ?? 0);
        $salePrice = ($data['sale_price'] !== '' && $data['sale_price'] !== null)
            ? (float) $data['sale_price']
            : null;
        $vatable = !empty($data['vatable']) ? 1 : 0;
        $photo = isset($data['photo']) ? trim((string) $data['photo']) : null;
        $sku = trim((string) ($data['sku'] ?? ''));
        $reorderLevel = isset($data['reorder_level']) ? (int) $data['reorder_level'] : self::DEFAULT_REORDER_LEVEL;
        $initialQty = !$isUpdate ? max(0, (int) ($data['initial_quantity'] ?? 0)) : 0;
        $userId = isset($data['user_id']) && $data['user_id'] !== '' ? (int) $data['user_id'] : null;
        $status = strtolower(trim((string) ($data['status'] ?? 'inactive')));

        if ($name === '') {
            throw new InvalidArgumentException('Product name is required.');
        }

        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Valid category is required.');
        }

        if ($price < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        if ($salePrice !== null && $salePrice < 0) {
            throw new InvalidArgumentException('Sale price cannot be negative.');
        }

        if ($salePrice !== null && $salePrice > $price) {
            throw new InvalidArgumentException('Sale price cannot be greater than regular price.');
        }

        if ($reorderLevel < 0) {
            throw new InvalidArgumentException('Reorder level cannot be negative.');
        }

        if ($sku === '') {
            $sku = null;
        }

        if ($photo !== null && $photo === '') {
            $photo = null;
        }

        if (!in_array($status, self::ALLOWED_PRODUCT_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid product status.');
        }

        return [
            'name'             => $name,
            'category_id'      => $categoryId,
            'subcategory_id'   => $subcategoryId,
            'supplier_id'      => $supplierId,
            'price'            => $price,
            'sale_price'       => $salePrice,
            'vatable'          => $vatable,
            'photo'            => $photo,
            'sku'              => $sku,
            'reorder_level'    => $reorderLevel,
            'initial_quantity' => $initialQty,
            'user_id'          => $userId,
            'status'           => $status,
        ];
    }

    private static function normalizeCsvHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';
        return trim($header, '_');
    }

    private static function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function firstFilledValue(array $rowData, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($rowData[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function normalizeNumericCsvValue(mixed $value): float
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return 0.0;
        }

        $normalized = str_replace([',', ' '], '', $normalized);

        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException('A numeric value is required.');
        }

        return (float) $normalized;
    }

    private static function nullableNumericCsvValue(mixed $value): ?float
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return self::normalizeNumericCsvValue($normalized);
    }

    private static function requiredNumericCsvValue(mixed $value, string $message): float
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return self::normalizeNumericCsvValue($normalized);
    }

    private static function parseBooleanCsvValue(mixed $value): int
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return 0;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return 0;
        }

        throw new InvalidArgumentException('Vatable must be Yes/No, True/False, or 1/0.');
    }

    private static function parseProductStatus(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return 'inactive';
        }

        if (!in_array($normalized, self::ALLOWED_PRODUCT_STATUSES, true)) {
            throw new InvalidArgumentException('Status must be active or inactive.');
        }

        return $normalized;
    }

    private static function resolveCategoryReference(PDO $conn, string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Category is required.');
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $stmt = $conn->prepare('SELECT category_id FROM categories WHERE LOWER(category_name) = LOWER(:name) LIMIT 1');
        $stmt->execute([':name' => $value]);
        $categoryId = (int) $stmt->fetchColumn();

        if ($categoryId <= 0) {
            throw new RuntimeException("Category \"{$value}\" was not found.");
        }

        return $categoryId;
    }

    private static function resolveSupplierReference(PDO $conn, string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $stmt = $conn->prepare('SELECT supplier_id FROM suppliers WHERE LOWER(supplier_name) = LOWER(:name) LIMIT 1');
        $stmt->execute([':name' => $value]);
        $supplierId = (int) $stmt->fetchColumn();

        if ($supplierId <= 0) {
            throw new RuntimeException("Supplier \"{$value}\" was not found.");
        }

        return $supplierId;
    }

    private static function assertUniqueSku(PDO $conn, string $sku, ?int $excludeId = null): void
    {
        $sql = "SELECT COUNT(*) FROM " . self::TABLE . " WHERE sku = :sku";
        $params = [':sku' => $sku];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND product_id <> :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('SKU already exists.');
        }
    }

    private static function assertCategoryExists(PDO $conn, int $categoryId): void
    {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM categories WHERE category_id = :id");
        $stmt->execute([':id' => $categoryId]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected category does not exist.');
        }
    }

    private static function assertSupplierExists(PDO $conn, int $supplierId): void
    {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM suppliers WHERE supplier_id = :id");
        $stmt->execute([':id' => $supplierId]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected supplier does not exist.');
        }
    }

    private static function assertProductExists(PDO $conn, int $productId): void
    {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM " . self::TABLE . " WHERE product_id = :id");
        $stmt->execute([':id' => $productId]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Product does not exist.');
        }
    }
}
