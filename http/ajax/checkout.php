<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../controllers/PosConfigController.php';
require_once __DIR__ . '/../../controllers/ProductController.php';
require_once __DIR__ . '/../../controllers/ShiftClosingController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('checkout', 20, 60, 'Too many checkout attempts. Please wait a moment and try again.');



// ── Safe fallback — MUST be before try{} so catch{} can use it ───────────────
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

// ── Limits ────────────────────────────────────────────────────────────────────
const MAX_CHECKOUT_LINES = 100;

// ── Response helpers ──────────────────────────────────────────────────────────
function checkout_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function checkout_txn_no(int $saleId, string $saleDate): string
{
    return sprintf('SALE-%s-%06d', date('Ymd', strtotime($saleDate)), $saleId);
}

function checkout_actor_label(): string
{
    $name = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
    if ($name !== '') return $name;
    $u = trim((string) ($_SESSION['username'] ?? ''));
    return $u !== '' ? $u : 'Cashier';
}

function checkout_notify(
    PDO     $conn,
    int     $userId,
    string  $type,
    string  $title,
    string  $message,
    string  $icon   = 'bi-bell',
    string  $color  = 'text-primary',
    ?string $link   = null
): void {
    if ($userId <= 0) return;
    try {
        NotificationController::create(
            $conn, $userId, 'admin', $type, $title, $message, $icon, $color,
            ($link !== null && trim($link) !== '')
                ? $link
                : '/inventory_system/product_management/pos.php'
        );
    } catch (Throwable $e) {
        error_log('[checkout.php][notify] ' . $e->getMessage());
    }
}

function checkout_fail(
    PDO    $conn,
    int    $userId,
    string $type,
    string $message,
    int    $status = 422,
    array  $extra  = []
): never {
    checkout_notify($conn, $userId, $type, 'Sale failed',
        checkout_actor_label() . ': ' . $message, 'bi-exclamation-triangle', 'text-danger');
    checkout_json(array_merge([
        'success'           => false,
        'error'             => $message,
        'notification_type' => $type,
    ], $extra), $status);
}

// ── Item helpers ──────────────────────────────────────────────────────────────
function checkout_normalize_items(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $pid  = (int) ($item['product_id'] ?? 0);
        $qty  = (int) ($item['quantity']   ?? 0);
        $unit = strtolower(trim((string) ($item['unit_type'] ?? 'piece')));
        if ($pid <= 0 || $qty <= 0) continue;
        if (!in_array($unit, ['piece', 'box', 'case'], true)) $unit = 'piece';
        $key = $pid . ':' . $unit;
        if (!isset($out[$key])) {
            $out[$key] = ['product_id' => $pid, 'quantity' => 0, 'unit_type' => $unit];
        }
        $out[$key]['quantity'] += $qty;
    }
    return array_values($out);
}

function checkout_unit_multiplier(array $product, string $unitType): int
{
    $ppb = max(1, (int) ($product['pieces_per_box']  ?? 1));
    $bpc = max(1, (int) ($product['boxes_per_case']  ?? 1));
    return match ($unitType) {
        'box'   => $ppb,
        'case'  => $ppb * $bpc,
        default => 1,
    };
}

function checkout_unit_available(array $product, string $unitType): bool
{
    return match ($unitType) {
        'box'   => (float) ($product['box_price']  ?? 0) > 0,
        'case'  => (float) ($product['case_price'] ?? 0) > 0,
        default => true,
    };
}

function checkout_discount_pct(array $product, string $unitType): float
{
    $field = match ($unitType) {
        'box'   => 'box_sale_price',
        'case'  => 'case_sale_price',
        default => 'sale_price',
    };
    if (!isset($product[$field]) || $product[$field] === null || $product[$field] === '') return 0.0;
    return max(0.0, min(100.0, (float) $product[$field]));
}

function checkout_unit_label(string $unitType, int $qty): string
{
    if ($qty === 1) return $unitType;
    return match ($unitType) {
        'box'   => 'boxes',
        'case'  => 'cases',
        default => 'pieces',
    };
}

// ── Main ──────────────────────────────────────────────────────────────────────
try {
    // ── Parse body ────────────────────────────────────────────────────────────
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        checkout_json(['success' => false, 'error' => 'Invalid JSON payload.'], 400);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────
    if ($sessionUserId <= 0) {
        checkout_json(['success' => false, 'error' => 'Unauthorized.'], 401);
    }

    // ── Normalize + validate cart ─────────────────────────────────────────────
    $items = checkout_normalize_items((array) ($body['items'] ?? []));
    if ($items === []) {
        checkout_fail($conn, $sessionUserId, 'sale_failed_empty_cart', 'Cart is empty.');
    }
    if (count($items) > MAX_CHECKOUT_LINES) {
        checkout_fail($conn, $sessionUserId, 'sale_failed_too_many_items',
            sprintf('Cart may not exceed %d distinct product lines.', MAX_CHECKOUT_LINES));
    }

    $paymentMethod = strtolower(trim((string) ($body['payment_method'] ?? 'cash')));
    if (!in_array($paymentMethod, ['cash', 'card', 'gcash', 'other'], true)) {
        checkout_fail($conn, $sessionUserId, 'sale_failed_invalid_payment', 'Invalid payment method.');
    }

    // ── Shift guard ───────────────────────────────────────────────────────────
    $sessionRole = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($sessionRole !== 'admin' && !ShiftClosingController::hasOpenShiftForToday($conn, $sessionUserId)) {
        checkout_fail($conn, $sessionUserId, 'sale_failed_shift_not_started',
            'Start your shift first before saving a sale.');
    }

    // ── Config ────────────────────────────────────────────────────────────────
    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    $vatRate = PosConfigController::taxRate($conn) / 100;

    // Static guard — runs INFORMATION_SCHEMA check only once per worker
    ProductController::ensureStockMovementSchema($conn);

    // ── Begin transaction ─────────────────────────────────────────────────────
    $conn->beginTransaction();

    // ── Lock product rows (FOR UPDATE on primary key — O(1) per row) ─────────
    $productIds   = array_column($items, 'product_id');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $productStmt = $conn->prepare("
        SELECT
            product_id, product_name,
            price, box_price, case_price,
            pieces_per_box, boxes_per_case,
            sale_price, box_sale_price, case_sale_price,
            on_sale, vatable, quantity, status
        FROM {$table_products}
        WHERE product_id IN ({$placeholders})
        FOR UPDATE
    ");
    $productStmt->execute($productIds);

    $products = [];
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        $products[(int) $p['product_id']] = $p;
    }

    // ── Validate + price each line ────────────────────────────────────────────
    // $remainingQtyByProduct tracks in-memory reservations so two lines of the
    // same product (e.g. 1 box + 5 pieces) don't both pass against full stock.
    $remainingQtyByProduct = [];
    foreach ($products as $pid => $p) {
        $remainingQtyByProduct[(int) $pid] = (int) ($p['quantity'] ?? 0);
    }

    $subtotal       = 0.0;
    $discountAmount = 0.0;
    $taxAmount      = 0.0;
    $saleItems      = [];

    foreach ($items as $item) {
        $pid      = (int) $item['product_id'];
        $qty      = (int) $item['quantity'];
        $unitType = (string) ($item['unit_type'] ?? 'piece');
        $product  = $products[$pid] ?? null;

        if ($product === null || ($product['status'] ?? 'inactive') !== 'active') {
            throw new RuntimeException('One or more products are unavailable.');
        }

        if (!checkout_unit_available($product, $unitType)) {
            throw new RuntimeException(sprintf(
                '"%s" is not available in %s units.',
                (string) ($product['product_name'] ?? ''), $unitType
            ));
        }

        $multiplier   = checkout_unit_multiplier($product, $unitType);
        $requiredBase = $qty * $multiplier;

        // Check against remaining (already-reserved) stock, not raw DB value
        if (($remainingQtyByProduct[$pid] ?? 0) < $requiredBase) {
            if ($conn->inTransaction()) $conn->rollBack();
            checkout_fail(
                $conn, $sessionUserId, 'sale_failed_low_stock',
                sprintf(
                    'Insufficient stock for "%s". Available: %d pcs, requested: %d pcs.',
                    (string) ($product['product_name'] ?? ''),
                    $remainingQtyByProduct[$pid] ?? 0,
                    $requiredBase
                ),
                409,
                [
                    'product'   => $product['product_name'],
                    'available' => $remainingQtyByProduct[$pid] ?? 0,
                    'requested' => $requiredBase,
                ]
            );
        }

        // Reserve stock in memory for subsequent lines of the same product
        $remainingQtyByProduct[$pid] -= $requiredBase;

        // Server-side pricing — client-supplied prices are intentionally ignored
        $regularPrice = match ($unitType) {
            'box'   => round((float) ($product['box_price']  ?? 0), 2),
            'case'  => round((float) ($product['case_price'] ?? 0), 2),
            default => round((float) ($product['price']      ?? 0), 2),
        };

        if ($regularPrice <= 0) {
            throw new RuntimeException(sprintf(
                '"%s" is missing a valid selling price.',
                (string) ($product['product_name'] ?? '')
            ));
        }

        $discPct      = checkout_discount_pct($product, $unitType);
        $unitPrice    = round($regularPrice * (1 - $discPct / 100), 2);
        $lineSubtotal = round($unitPrice * $qty, 2);
        $lineDiscount = round(max(0.0, $regularPrice - $unitPrice) * $qty, 2);
        $lineTax      = !empty($product['vatable']) ? round($lineSubtotal * $vatRate, 2) : 0.0;

        $subtotal       += $lineSubtotal;
        $discountAmount += $lineDiscount;
        $taxAmount      += $lineTax;

        $saleItems[] = [
            'product_id'    => $pid,
            'quantity'      => $qty,
            'unit_type'     => $unitType,
            'unit_multiplier' => $multiplier,
            'unit_price'    => $unitPrice,
            'base_qty_sold' => $requiredBase,   // pre-calculated, reused below
        ];
    }

    $subtotal       = round($subtotal,       2);
    $discountAmount = round($discountAmount, 2);
    $taxAmount      = round($taxAmount,      2);
    $grandTotal     = round($subtotal + $taxAmount, 2);

    // ── INSERT sale header ────────────────────────────────────────────────────
    // BUG FIX 3: explicitly pass sale_date = NOW() so the column never relies
    // on a DEFAULT that may not exist.
    $conn->prepare("
        INSERT INTO {$table_sales}
            (sale_date, total_amount, tax, discount, payment_method, user_id)
        VALUES
            (NOW(), :total, :tax, :discount, :payment, :user)
    ")->execute([
        ':total'   => $grandTotal,
        ':tax'     => $taxAmount,
        ':discount' => $discountAmount,
        ':payment' => $paymentMethod,
        ':user'    => $sessionUserId,
    ]);

    $saleId = (int) $conn->lastInsertId();

    // BUG FIX 4: guard against lastInsertId() returning 0
    if ($saleId <= 0) {
        throw new RuntimeException('Failed to create sale record — lastInsertId returned 0.');
    }

    // Fetch the server-side sale_date so TXN number is consistent with the DB row
    $saleDateStmt = $conn->prepare(
        "SELECT sale_date FROM {$table_sales} WHERE sale_id = :id LIMIT 1"
    );
    $saleDateStmt->execute([':id' => $saleId]);
    $saleDate = (string) ($saleDateStmt->fetchColumn() ?: date('Y-m-d H:i:s'));
    $txnNo    = checkout_txn_no($saleId, $saleDate);

    // ── Prepared statements — declared ONCE, reused per item ─────────────────
    $itemStmt = $conn->prepare("
        INSERT INTO {$table_sale_items}
            (sale_id, product_id, unit_price, quantity, unit_type, unit_multiplier)
        VALUES
            (:sale_id, :product_id, :unit_price, :qty, :unit_type, :multiplier)
    ");

    // BUG FIX 1 (CRITICAL): actually deduct stock from the products table.
    // GREATEST(0, ...) is a DB-level safety net even if the FOR UPDATE lock
    // is somehow not effective — quantity can never go negative.
    $stockStmt = $conn->prepare("
        UPDATE {$table_products}
        SET    quantity = GREATEST(0, quantity - :deduct)
        WHERE  product_id = :product_id
    ");

    $auditStmt = $conn->prepare("
        INSERT INTO stock_audit_log
            (product_id, change_qty, current_qty, action,
             reference_type, reference_id, notes, user_id, timestamp)
        VALUES
            (:product_id, :change_qty, :current_qty, :action,
             :ref_type, :ref_id, :notes, :user_id, NOW())
    ");

    foreach ($saleItems as $si) {
        $pid         = (int) $si['product_id'];
        $baseQtySold = (int) $si['base_qty_sold'];
        $newQty      = max(0, (int) ($remainingQtyByProduct[$pid] ?? 0));

        // 1 — sale item line
        $itemStmt->execute([
            ':sale_id'    => $saleId,
            ':product_id' => $pid,
            ':unit_price' => $si['unit_price'],
            ':qty'        => $si['quantity'],
            ':unit_type'  => $si['unit_type'],
            ':multiplier' => $si['unit_multiplier'],
        ]);

        // 2 — deduct stock (THE FIX — was missing in the original)
        $stockStmt->execute([
            ':deduct'     => $baseQtySold,
            ':product_id' => $pid,
        ]);

        // 3 — audit log
        $auditStmt->execute([
            ':product_id'  => $pid,
            ':change_qty'  => -$baseQtySold,
            ':current_qty' => $newQty,
            ':action'      => 'sale',
            ':ref_type'    => 'sale',
            ':ref_id'      => $saleId,
            ':notes'       => sprintf(
                'Transaction %s | Sold %d %s',
                $txnNo,
                (int) $si['quantity'],
                checkout_unit_label((string) $si['unit_type'], (int) $si['quantity'])
            ),
            ':user_id'     => $sessionUserId,
        ]);
    }

    $conn->commit();

    // ── Post-commit work (non-fatal if any of these throw) ────────────────────

    // SCALABILITY FIX: bust APCu product summary cache so manage_product.php
    // stat cards (total stock, low-stock count) reflect the new quantities
    // immediately rather than waiting for the 60 s TTL to expire.
    try {
        ProductController::bustSummaryCache();
    } catch (Throwable $e) {
        error_log('[checkout.php][bust_cache] ' . $e->getMessage());
    }

    // BUG FIX 6: logActivity() wrapped so a logging failure cannot trigger
    // the catch block and attempt to rollback an already-committed transaction.
    try {
        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'sale_create',
            sprintf(
                'Completed sale #%d (%s), %d item line(s), payment: %s, total: PHP %.2f.',
                $saleId,
                $txnNo,
                count($saleItems),
                $paymentMethod,
                $grandTotal
            ),
            'sale',
            $saleId
        );
    } catch (Throwable $e) {
        error_log('[checkout.php][log_activity] ' . $e->getMessage());
    }

    checkout_notify(
        $conn,
        $sessionUserId,
        'sale_success',
        'Sale completed',
        sprintf(
            '%s saved %s for PHP %s.',
            checkout_actor_label(),
            $txnNo,
            number_format($grandTotal, 2)
        ),
        'bi-receipt',
        'text-success',
        '/inventory_system/reports/sales_report.php?sale_id=' . $saleId
    );

    checkout_json([
        'success'           => true,
        'sale_id'           => $saleId,
        'transaction_no'    => $txnNo,
        'message'           => 'Sale saved successfully.',
        'notification_type' => 'sale_success',
        'server_totals'     => [
            'subtotal' => $subtotal,
            'discount' => $discountAmount,
            'tax'      => $taxAmount,
            'grand'    => $grandTotal,
        ],
    ]);

} catch (Throwable $e) {
    // Only roll back if still inside an open transaction.
    // If the throw happened after commit(), inTransaction() returns false
    // so we won't attempt to roll back a committed transaction.
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('[checkout.php] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());

    checkout_notify(
        $conn ?? new PDO('sqlite::memory:'), // fallback so notify() doesn't crash if $conn is unset
        $sessionUserId,
        'sale_failed_error',
        'Sale failed',
        checkout_actor_label() . ' could not complete checkout right now.',
        'bi-exclamation-triangle',
        'text-danger'
    );

    checkout_json([
        'success'           => false,
        'error'             => 'Unable to complete checkout right now.',
        'notification_type' => 'sale_failed_error',
    ], 500);
}