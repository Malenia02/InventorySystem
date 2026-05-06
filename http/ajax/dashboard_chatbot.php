<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/DashboardController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('dashboard_chatbot', 30, 60, 'Too many assistant requests. Please wait a moment and try again.');

global $chatbotDetected;
global $chatbotUserRole;

function chatbotJsonResponse(array $payload, int $statusCode = 200): never
{
    global $chatbotDetected;
    global $chatbotUserRole;

    $payload = chatbotAttachDefaults($payload, is_array($chatbotDetected) ? $chatbotDetected : [], (string) $chatbotUserRole);

    if (($payload['success'] ?? false) === true && !empty($payload['intent'])) {
        $_SESSION['chatbot_last_context'] = [
            'intent' => (string) $payload['intent'],
            'question' => (string) ($payload['question'] ?? ''),
            'answer' => (string) ($payload['answer'] ?? ''),
            'rows' => array_slice(is_array($payload['rows'] ?? null) ? $payload['rows'] : [], 0, 12),
            'actions' => array_slice(is_array($payload['actions'] ?? null) ? $payload['actions'] : [], 0, 8),
            'saved_at' => time(),
        ];
    }

    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function chatbotAttachDefaults(array $payload, array $detected, string $role): array
{
    $intent = (string) ($detected['intent'] ?? $payload['intent'] ?? '');
    $payload['suggestions'] = chatbotMergeUniqueLists(
        $payload['suggestions'] ?? [],
        chatbotDefaultSuggestions($intent, $role, $detected)
    );
    $payload['actions'] = chatbotMergeUniqueActions(
        $payload['actions'] ?? [],
        chatbotDefaultActions($intent, $role, $detected)
    );

    if ($role === 'admin' && (string) ($_POST['debug_intent'] ?? '') === '1') {
        $payload['intent_debug'] = [
            'intent' => $intent,
            'period' => $detected['period'] ?? null,
            'confidence' => isset($detected['confidence']) ? (float) $detected['confidence'] : null,
            'matched_phrases' => array_values(is_array($detected['matched_phrases'] ?? null) ? $detected['matched_phrases'] : []),
            'matched_concepts' => array_values(is_array($detected['matched_concepts'] ?? null) ? $detected['matched_concepts'] : []),
            'role_access' => array_values(is_array($detected['role_access'] ?? null) ? $detected['role_access'] : []),
        ];
    }

    return $payload;
}

function chatbotMergeUniqueLists(array $primary, array $secondary): array
{
    $seen = [];
    $merged = [];

    foreach (array_merge($primary, $secondary) as $item) {
        $value = trim((string) $item);
        if ($value === '') {
            continue;
        }

        $key = strtolower($value);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[] = $value;
    }

    return $merged;
}

function chatbotMergeUniqueActions(array $primary, array $secondary): array
{
    $seen = [];
    $merged = [];

    foreach (array_merge($primary, $secondary) as $action) {
        if (!is_array($action)) {
            continue;
        }

        $label = trim((string) ($action['label'] ?? ''));
        $url = trim((string) ($action['url'] ?? ''));
        if ($label === '' || $url === '') {
            continue;
        }

        $key = strtolower($label . '|' . $url);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[] = [
            'label' => $label,
            'url'   => $url,
            'icon'  => trim((string) ($action['icon'] ?? 'bi-box-arrow-up-right')),
        ];
    }

    return $merged;
}

function chatbotDefaultSuggestions(string $intent, string $role, array $detected = []): array
{
    $role = strtolower(trim($role));

    if ($role === 'cashier') {
        return match ($intent) {
            'greeting' => [
                'My sales today',
                'My revenue today',
                'My recent transactions',
                'My shift summary',
                'Sales today',
            ],
            'system_status' => [
                'My sales today',
                'My recent transactions',
                'What is my shift summary?',
            ],
            'cashier_sales_summary',
            'cashier_revenue_summary',
            'cashier_items_sold_summary' => [
                'My recent transactions',
                'My shift summary',
                'My payment methods this month',
            ],
            'cashier_recent_transactions' => [
                'My shift summary',
                'My payment methods this month',
                'What was my last transaction?',
            ],
            'cashier_payment_breakdown' => [
                'My revenue today',
                'My shift summary',
                'My recent transactions',
            ],
            'cashier_shift_summary',
            'cashier_last_receipt' => [
                'My recent transactions',
                'What is my payment summary this month?',
                'What was my last transaction?',
            ],
            'low_stock',
            'out_of_stock_summary' => [
                'What products are low in stock?',
                'Which items should I reorder?',
                'How many out-of-stock products are there?',
            ],
            default => [
                'My sales today',
                'My revenue today',
                'My recent transactions',
                'My shift summary',
            ],
        };
    }

    return match ($intent) {
        'greeting' => [
            'What products are low in stock?',
            'What sold best today?',
            'What is the total revenue this month?',
            'Which products have no sales this month?',
        ],
        'system_status' => [
            'What products are low in stock?',
            'What sold best this week?',
            'What category sold best this month?',
        ],
        'low_stock' => [
            'Which items should I reorder?',
            'How many out-of-stock products are there?',
            'Show me slow-moving products.',
            'Show me those items',
        ],
        'best_sellers' => [
            'What is the total revenue this month?',
            'What category sold best this month?',
            'Which products have no sales this month?',
        ],
        'reorder_suggestions' => [
            'What products are low in stock?',
            'Show me slow-moving products.',
            'Which products have no sales this month?',
        ],
        'slow_moving' => [
            'Which products have no sales this month?',
            'Which items should I reorder?',
            'What products are low in stock?',
        ],
        'revenue_summary' => [
            'What sold best this period?',
            'What payment method is used most this month?',
            'What products are low in stock?',
        ],
        'sales_summary' => [
            'What is the total revenue this period?',
            'What products are low in stock?',
            'What sold best today?',
        ],
        'items_sold_summary' => [
            'What sold best today?',
            'What is the total revenue this month?',
            'What category sold best this month?',
        ],
        'out_of_stock_summary' => [
            'What products are low in stock?',
            'Which items should I reorder?',
            'Which products have no sales this month?',
        ],
        'payment_breakdown' => [
            'What category sold best this month?',
            'What sold best this month?',
            'What is the total revenue this month?',
        ],
        'top_categories' => [
            'What is the total revenue this month?',
            'What payment method is used most this month?',
            'What sold best this month?',
        ],
        'top_revenue_products' => [
            'Which items should I reorder?',
            'Show me slow-moving products.',
            'What category sold best this month?',
        ],
        'no_sales_this_month' => [
            'Show me slow-moving products.',
            'Which items should I reorder?',
            'What products are low in stock?',
        ],
        'stock_movement_lookup' => [
            'Show me the stock audit',
            'Which items should I reorder?',
            'What products are low in stock?',
        ],
        'expense_summary',
        'expense_top_category',
        'expense_largest' => [
            'How much did we spend this month?',
            'Top expense category this month?',
            'Largest expense this month?',
        ],
        'approval_summary' => [
            'Show pending stock requests',
            'Show sale void requests',
            'Open approval center',
        ],
        'po_pending',
        'po_received_today',
        'supplier_reorder_advice' => [
            'Which supplier should I order from?',
            'What POs are still pending?',
            'What arrived today?',
        ],
        'transaction_lookup' => [
            'Open sales history',
            'Show recent transactions',
            'Show sale void requests',
        ],
        default => [
            'What products are low in stock?',
            'What sold best today?',
            'What is the total revenue this month?',
        ],
    };
}

function chatbotDefaultActions(string $intent, string $role, array $detected = []): array
{
    $role = strtolower(trim($role));

    if ($role === 'cashier') {
        return match ($intent) {
            'cashier_sales_summary',
            'cashier_revenue_summary',
            'cashier_items_sold_summary',
            'cashier_recent_transactions',
            'cashier_payment_breakdown',
            'cashier_shift_summary',
            'cashier_last_receipt' => [
                [
                    'label' => 'Open POS',
                    'url'   => '/inventory_system/product_management/pos.php',
                    'icon'  => 'bi-shop',
                ],
                [
                    'label' => 'Close Shift',
                    'url'   => '/inventory_system/shift_closing.php',
                    'icon'  => 'bi-journal-check',
                ],
                [
                    'label' => 'My Profile',
                    'url'   => '/inventory_system/profile.php',
                    'icon'  => 'bi-person',
                ],
            ],
            'low_stock',
            'out_of_stock_summary' => [
                [
                    'label' => 'Open POS',
                    'url'   => '/inventory_system/product_management/pos.php',
                    'icon'  => 'bi-shop',
                ],
                [
                    'label' => 'My Dashboard',
                    'url'   => '/inventory_system/index.php',
                    'icon'  => 'bi-speedometer2',
                ],
            ],
            default => [
                [
                    'label' => 'Open POS',
                    'url'   => '/inventory_system/product_management/pos.php',
                    'icon'  => 'bi-shop',
                ],
                [
                    'label' => 'My Dashboard',
                    'url'   => '/inventory_system/index.php',
                    'icon'  => 'bi-speedometer2',
                ],
            ],
        };
    }

    return match ($intent) {
        'greeting',
        'system_status' => [
            [
                'label' => 'Open Sales Report',
                'url'   => '/inventory_system/reports/sales_report.php',
                'icon'  => 'bi-bar-chart-line',
            ],
            [
                'label' => 'Manage Products',
                'url'   => '/inventory_system/product_management/manage_product.php',
                'icon'  => 'bi-box-seam',
            ],
            [
                'label' => 'Backup & Restore',
                'url'   => '/inventory_system/admin/backup_restore.php',
                'icon'  => 'bi-cloud-arrow-up',
            ],
            [
                'label' => 'Reorder Planner',
                'url'   => '/inventory_system/admin/reorder_planner.php',
                'icon'  => 'bi-arrow-repeat',
            ],
            [
                'label' => 'View Activity Log',
                'url'   => '/inventory_system/admin/activity_log.php',
                'icon'  => 'bi-activity',
            ],
        ],
        'low_stock',
        'reorder_suggestions',
        'slow_moving',
        'no_sales_this_month' => [
            [
                'label' => 'View Low Stock',
                'url'   => '/inventory_system/product_management/manage_product.php',
                'icon'  => 'bi-exclamation-triangle',
            ],
            [
                'label' => 'Manage Products',
                'url'   => '/inventory_system/product_management/manage_product.php',
                'icon'  => 'bi-box-seam',
            ],
            [
                'label' => 'Reorder Planner',
                'url'   => '/inventory_system/admin/reorder_planner.php',
                'icon'  => 'bi-arrow-repeat',
            ],
            [
                'label' => 'Open POS',
                'url'   => '/inventory_system/product_management/pos.php',
                'icon'  => 'bi-shop',
            ],
            [
                'label' => 'Open Sales Report',
                'url'   => '/inventory_system/reports/sales_report.php',
                'icon'  => 'bi-bar-chart-line',
            ],
            [
                'label' => 'View Stock Audit',
                'url'   => '/inventory_system/admin/stock_movement_audit.php',
                'icon'  => 'bi-activity',
            ],
        ],
        'best_sellers',
        'revenue_summary',
        'sales_summary',
        'items_sold_summary',
        'payment_breakdown',
        'top_categories',
        'top_revenue_products' => [
            [
                'label' => 'Open Sales Report',
                'url'   => '/inventory_system/reports/sales_report.php',
                'icon'  => 'bi-bar-chart-line',
            ],
            [
                'label' => 'View Dashboard',
                'url'   => '/inventory_system/index.php',
                'icon'  => 'bi-speedometer2',
            ],
            [
                'label' => 'Manage Products',
                'url'   => '/inventory_system/product_management/manage_product.php',
                'icon'  => 'bi-box-seam',
            ],
        ],
        'system_errors_today',
        'recent_chatbot_errors',
        'last_system_error' => [
            [
                'label' => 'View Activity Log',
                'url'   => '/inventory_system/admin/activity_log.php',
                'icon'  => 'bi-activity',
            ],
            [
                'label' => 'Open Dashboard',
                'url'   => '/inventory_system/index.php',
                'icon'  => 'bi-speedometer2',
            ],
        ],
        'stock_movement_lookup' => [
            [
                'label' => 'View Stock Audit',
                'url'   => '/inventory_system/admin/stock_movement_audit.php',
                'icon'  => 'bi-activity',
            ],
            [
                'label' => 'Manage Products',
                'url'   => '/inventory_system/product_management/manage_product.php',
                'icon'  => 'bi-box-seam',
            ],
        ],
        'expense_summary',
        'expense_top_category',
        'expense_largest' => [
            [
                'label' => 'Open Expense Tracker',
                'url'   => '/inventory_system/admin/expense_tracker.php',
                'icon'  => 'bi-wallet2',
            ],
            [
                'label' => 'View Dashboard',
                'url'   => '/inventory_system/index.php',
                'icon'  => 'bi-speedometer2',
            ],
        ],
        'approval_summary' => [
            [
                'label' => 'Approval Center',
                'url'   => '/inventory_system/admin/approval_center.php',
                'icon'  => 'bi-check2-square',
            ],
            [
                'label' => 'Sale Requests',
                'url'   => '/inventory_system/admin/sale_action_requests.php',
                'icon'  => 'bi-arrow-counterclockwise',
            ],
            [
                'label' => 'Stock Requests',
                'url'   => '/inventory_system/stock_adjustment_requests.php',
                'icon'  => 'bi-clipboard-check',
            ],
        ],
        'po_pending',
        'po_received_today',
        'supplier_reorder_advice' => [
            [
                'label' => 'Purchase Orders',
                'url'   => '/inventory_system/admin/purchase_orders.php',
                'icon'  => 'bi-bag-check',
            ],
            [
                'label' => 'Receiving History',
                'url'   => '/inventory_system/admin/purchase_receiving_history.php',
                'icon'  => 'bi-box-arrow-in-down',
            ],
            [
                'label' => 'Reorder Planner',
                'url'   => '/inventory_system/admin/reorder_planner.php',
                'icon'  => 'bi-arrow-repeat',
            ],
        ],
        'transaction_lookup' => [
            [
                'label' => 'Open Sales History',
                'url'   => '/inventory_system/cashier_sales_history.php',
                'icon'  => 'bi-receipt',
            ],
            [
                'label' => 'Open Sales Report',
                'url'   => '/inventory_system/reports/sales_report.php',
                'icon'  => 'bi-bar-chart-line',
            ],
        ],
        default => [
            [
                'label' => 'Open Sales Report',
                'url'   => '/inventory_system/reports/sales_report.php',
                'icon'  => 'bi-bar-chart-line',
            ],
            [
                'label' => 'Manage Products',
                'url'   => '/inventory_system/product_management/manage_product.php',
                'icon'  => 'bi-box-seam',
            ],
            [
                'label' => 'Open Dashboard',
                'url'   => '/inventory_system/index.php',
                'icon'  => 'bi-speedometer2',
            ],
        ],
    };
}

function chatbotNormalizeQuestion(string $question): string
{
    $question = strtolower(trim($question));
    $question = preg_replace('/[^a-z0-9\s]/', ' ', $question) ?? $question;

    $commonMisspellings = [
        'bset' => 'best',
        'bst' => 'best',
        'inventry' => 'inventory',
        'inventroy' => 'inventory',
        'prodct' => 'product',
        'prodcts' => 'products',
        'prodcuts' => 'products',
        'categroy' => 'category',
        'catgory' => 'category',
        'paymnt' => 'payment',
        'paymet' => 'payment',
        'revnue' => 'revenue',
        'revanue' => 'revenue',
        'suplier' => 'supplier',
        'suppplier' => 'supplier',
        'reciept' => 'receipt',
        'recipt' => 'receipt',
        'trasaction' => 'transaction',
        'trasactions' => 'transactions',
        'transction' => 'transaction',
        'transctions' => 'transactions',
        'stok' => 'stock',
        'stck' => 'stock',
        'gcsh' => 'gcash',
    ];

    foreach ($commonMisspellings as $wrong => $right) {
        $question = preg_replace('/\b' . preg_quote($wrong, '/') . '\b/', $right, $question) ?? $question;
    }

    $question = preg_replace('/\s+/', ' ', $question) ?? $question;
    return $question;
}

function chatbotMatchesPhrase(string $normalizedQuestion, array $phrases): bool
{
    foreach ($phrases as $phrase) {
        if (preg_match('/(?:^|\s)' . preg_quote($phrase, '/') . '(?:$|\s|\?|\!|\.|,)/i', $normalizedQuestion)) {
            return true;
        }
    }

    return false;
}

function chatbotQuestionTokens(string $normalizedQuestion): array
{
    $tokens = preg_split('/[^a-z0-9]+/', $normalizedQuestion) ?: [];
    return array_values(array_filter(array_unique($tokens), static fn (string $token): bool => $token !== ''));
}

function chatbotTokenLooksLike(string $token, string $expected): bool
{
    if ($token === $expected) {
        return true;
    }

    $tokenLength = strlen($token);
    $expectedLength = strlen($expected);

    if ($tokenLength < 4 || $expectedLength < 4) {
        return false;
    }

    $distance = levenshtein($token, $expected);
    $allowedDistance = max(1, (int) floor(max($tokenLength, $expectedLength) / 4));

    return $distance <= min(2, $allowedDistance);
}

function chatbotHasConcept(string $normalizedQuestion, array $conceptWords): bool
{
    $tokens = chatbotQuestionTokens($normalizedQuestion);

    foreach ($conceptWords as $conceptWord) {
        $conceptWord = chatbotNormalizeQuestion((string) $conceptWord);

        if ($conceptWord === '') {
            continue;
        }

        if (str_contains($conceptWord, ' ')) {
            if (str_contains($normalizedQuestion, $conceptWord)) {
                return true;
            }

            continue;
        }

        foreach ($tokens as $token) {
            if (chatbotTokenLooksLike($token, $conceptWord)) {
                return true;
            }
        }
    }

    return false;
}

function chatbotDetectPeriodFromQuestion(string $normalizedQuestion, string $default = 'month'): string
{
    if (
        str_contains($normalizedQuestion, 'today')
        || str_contains($normalizedQuestion, 'tonight')
        || str_contains($normalizedQuestion, 'this day')
        || str_contains($normalizedQuestion, 'daily')
    ) {
        return 'today';
    }

    if (
        str_contains($normalizedQuestion, 'week')
        || str_contains($normalizedQuestion, 'weekly')
        || str_contains($normalizedQuestion, 'last 7 days')
        || str_contains($normalizedQuestion, '7 days')
    ) {
        return 'week';
    }

    if (
        str_contains($normalizedQuestion, 'year')
        || str_contains($normalizedQuestion, 'yearly')
        || str_contains($normalizedQuestion, 'annual')
        || str_contains($normalizedQuestion, 'annually')
        || str_contains($normalizedQuestion, 'last 365 days')
        || str_contains($normalizedQuestion, '365 days')
    ) {
        return 'year';
    }

    if (
        str_contains($normalizedQuestion, 'month')
        || str_contains($normalizedQuestion, 'monthly')
        || str_contains($normalizedQuestion, 'last 30 days')
        || str_contains($normalizedQuestion, '30 days')
    ) {
        return 'month';
    }

    return $default;
}

function chatbotIntentConceptGroups(): array
{
    return [
        'product' => ['product', 'products', 'item', 'items', 'goods', 'sku'],
        'sales' => ['sale', 'sales', 'sold', 'selling', 'transaction', 'transactions', 'order', 'orders'],
        'revenue' => ['revenue', 'income', 'earnings', 'earn', 'earned', 'gross sales', 'gross'],
        'stock' => ['stock', 'stocks', 'inventory', 'quantity', 'qty'],
        'reorder' => ['reorder', 'restock', 'replenish', 'buy', 'order', 'purchase'],
        'best' => ['best', 'top', 'most', 'highest', 'popular', 'performing', 'leader'],
        'expense' => ['expense', 'expenses', 'spend', 'spent', 'cost', 'costs'],
        'supplier' => ['supplier', 'suppliers', 'vendor', 'vendors'],
        'category' => ['category', 'categories', 'department', 'departments'],
        'payment' => ['payment', 'payments', 'paid', 'pay', 'method', 'methods', 'cash', 'gcash', 'card', 'ewallet', 'wallet'],
        'count' => ['count', 'counts', 'how many', 'number', 'total'],
        'low_stock_signal' => ['low', 'few', 'empty', 'almost empty', 'running out', 'critical', 'shortage', 'below reorder', 'need reorder', 'needs reorder', 'need restock', 'needs restock', 'restock', 'reorder', 'replenish'],
        'slow_signal' => ['slow', 'slow moving', 'dead', 'dead stock', 'not selling', 'no sales', 'stale', 'inactive'],
        'movement' => ['movement', 'movements', 'audit', 'change', 'changed', 'history', 'why'],
        'purchase_order' => ['po', 'pos', 'purchase order', 'purchase orders'],
        'pending' => ['pending', 'open', 'waiting', 'unreceived'],
        'received' => ['received', 'arrived', 'delivered', 'came in'],
        'largest' => ['largest', 'biggest', 'highest', 'top'],
        'recent' => ['recent', 'latest', 'last', 'history'],
        'shift' => ['shift', 'drawer', 'closing'],
        'receipt' => ['receipt', 'transaction', 'sale'],
        'self' => ['my', 'mine', 'own', 'me'],
    ];
}

function chatbotConceptAliases(string $concept): array
{
    $groups = chatbotIntentConceptGroups();
    return $groups[$concept] ?? [$concept];
}

function chatbotConceptMatches(string $normalizedQuestion, string $concept): bool
{
    return chatbotHasConcept($normalizedQuestion, chatbotConceptAliases($concept));
}

function chatbotGetIntentRegistry(): array
{
    return [
        'best_sellers' => [
            'sample_phrases' => [
                'what products sold the best',
                'best selling products',
                'top selling items',
                'most sold products',
                'what item is popular today',
            ],
            'required_concepts' => ['product', 'best'],
            'optional_concepts' => ['sales'],
            'excluded_concepts' => ['category', 'payment', 'expense', 'supplier'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'low_stock' => [
            'sample_phrases' => [
                'what products are low in stock',
                'items almost empty',
                'products running out',
                'what should i restock',
                'which items need reorder',
            ],
            'required_concepts' => ['low_stock_signal'],
            'optional_concepts' => ['product', 'stock', 'reorder'],
            'excluded_concepts' => ['supplier', 'payment', 'expense'],
            'default_period' => null,
            'roles' => ['admin', 'cashier'],
        ],
        'sales_summary' => [
            'sample_phrases' => [
                'how many sales today',
                'transactions this month',
                'sales count this week',
            ],
            'required_concepts' => ['sales'],
            'optional_concepts' => ['count'],
            'excluded_concepts' => ['revenue', 'payment', 'product', 'category', 'expense'],
            'default_period' => 'today',
            'roles' => ['admin'],
        ],
        'revenue_summary' => [
            'sample_phrases' => [
                'revenue today',
                'how much did we earn this month',
                'total income this week',
                'gross sales this year',
            ],
            'required_concepts' => ['revenue'],
            'optional_concepts' => ['sales', 'count'],
            'excluded_concepts' => ['payment', 'product', 'category', 'expense'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'payment_breakdown' => [
            'sample_phrases' => [
                'most used payment method',
                'cash or gcash breakdown',
                'payment summary today',
            ],
            'required_concepts' => ['payment'],
            'optional_concepts' => ['best', 'count'],
            'excluded_concepts' => ['expense', 'supplier'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'top_categories' => [
            'sample_phrases' => [
                'what category sold best',
                'top category this month',
                'best performing category',
            ],
            'required_concepts' => ['category', 'best'],
            'optional_concepts' => ['sales', 'revenue'],
            'excluded_concepts' => ['product', 'payment', 'expense'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'slow_moving' => [
            'sample_phrases' => [
                'products with no sales',
                'not selling items',
                'dead stock',
                'slow moving products',
            ],
            'required_concepts' => ['slow_signal'],
            'optional_concepts' => ['product', 'stock', 'sales'],
            'excluded_concepts' => ['category', 'payment', 'expense'],
            'default_period' => null,
            'roles' => ['admin'],
        ],
        'stock_movement_lookup' => [
            'sample_phrases' => [
                'why did coke stock change',
                'stock audit for piattos',
                'show inventory movement',
            ],
            'required_concepts' => ['stock', 'movement'],
            'optional_concepts' => ['product'],
            'excluded_concepts' => ['payment', 'expense'],
            'default_period' => null,
            'roles' => ['admin'],
        ],
        'po_pending' => [
            'sample_phrases' => [
                'what pos are pending',
                'pending purchase orders',
            ],
            'required_concepts' => ['purchase_order', 'pending'],
            'optional_concepts' => ['supplier'],
            'excluded_concepts' => ['payment'],
            'default_period' => null,
            'roles' => ['admin'],
        ],
        'po_received_today' => [
            'sample_phrases' => [
                'what arrived today',
                'purchase orders received today',
            ],
            'required_concepts' => ['purchase_order', 'received'],
            'optional_concepts' => ['supplier'],
            'excluded_concepts' => ['payment'],
            'default_period' => 'today',
            'roles' => ['admin'],
        ],
        'supplier_reorder_advice' => [
            'sample_phrases' => [
                'which supplier should i order from',
                'best supplier for restock',
                'who should i buy from',
            ],
            'required_concepts' => ['supplier', 'reorder'],
            'optional_concepts' => ['best'],
            'excluded_concepts' => ['payment', 'expense'],
            'default_period' => null,
            'roles' => ['admin'],
        ],
        'expense_summary' => [
            'sample_phrases' => [
                'how much did we spend',
                'expense summary',
            ],
            'required_concepts' => ['expense'],
            'optional_concepts' => ['count'],
            'excluded_concepts' => ['payment', 'supplier'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'expense_top_category' => [
            'sample_phrases' => [
                'top expense category',
                'highest expense category',
            ],
            'required_concepts' => ['expense', 'category', 'best'],
            'optional_concepts' => [],
            'excluded_concepts' => ['payment'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'expense_largest' => [
            'sample_phrases' => [
                'largest expense',
                'biggest expense',
            ],
            'required_concepts' => ['expense', 'largest'],
            'optional_concepts' => [],
            'excluded_concepts' => ['payment'],
            'default_period' => 'month',
            'roles' => ['admin'],
        ],
        'cashier_sales_summary' => [
            'sample_phrases' => ['my sales today'],
            'required_concepts' => ['self', 'sales'],
            'optional_concepts' => ['count'],
            'excluded_concepts' => ['revenue', 'payment'],
            'default_period' => 'today',
            'roles' => ['cashier', 'admin'],
        ],
        'cashier_revenue_summary' => [
            'sample_phrases' => ['my revenue this week'],
            'required_concepts' => ['self', 'revenue'],
            'optional_concepts' => [],
            'excluded_concepts' => ['payment'],
            'default_period' => 'today',
            'roles' => ['cashier', 'admin'],
        ],
        'cashier_recent_transactions' => [
            'sample_phrases' => ['my recent transactions'],
            'required_concepts' => ['self', 'recent', 'sales'],
            'optional_concepts' => [],
            'excluded_concepts' => ['revenue'],
            'default_period' => 'month',
            'roles' => ['cashier', 'admin'],
        ],
        'cashier_shift_summary' => [
            'sample_phrases' => ['my shift summary'],
            'required_concepts' => ['self', 'shift'],
            'optional_concepts' => [],
            'excluded_concepts' => [],
            'default_period' => null,
            'roles' => ['cashier', 'admin'],
        ],
        'cashier_last_receipt' => [
            'sample_phrases' => ['my last receipt'],
            'required_concepts' => ['self', 'receipt'],
            'optional_concepts' => ['recent'],
            'excluded_concepts' => ['revenue'],
            'default_period' => null,
            'roles' => ['cashier', 'admin'],
        ],
    ];
}

function chatbotPhraseConfidence(string $normalizedQuestion, string $phrase): float
{
    $phrase = chatbotNormalizeQuestion($phrase);

    if ($phrase === '') {
        return 0.0;
    }

    if (str_contains($normalizedQuestion, $phrase)) {
        return 1.0;
    }

    similar_text($normalizedQuestion, $phrase, $percent);
    $questionTokens = chatbotQuestionTokens($normalizedQuestion);
    $phraseTokens = chatbotQuestionTokens($phrase);

    if ($phraseTokens === []) {
        return 0.0;
    }

    $matched = 0;
    foreach ($phraseTokens as $phraseToken) {
        foreach ($questionTokens as $questionToken) {
            if (chatbotTokenLooksLike($questionToken, $phraseToken)) {
                $matched++;
                break;
            }
        }
    }

    $tokenScore = $matched / max(1, count($phraseTokens));
    $similarityScore = ((float) $percent) / 100;

    return max($tokenScore, $similarityScore);
}

function chatbotScoreIntent(string $normalizedQuestion, array $intentDefinition): array
{
    $matchedPhrases = [];
    $matchedConcepts = [];
    $phraseScore = 0.0;

    foreach ($intentDefinition['excluded_concepts'] ?? [] as $concept) {
        if (chatbotConceptMatches($normalizedQuestion, (string) $concept)) {
            return [
                'confidence' => 0.0,
                'matched_phrases' => [],
                'matched_concepts' => [],
            ];
        }
    }

    foreach ($intentDefinition['sample_phrases'] ?? [] as $phrase) {
        $confidence = chatbotPhraseConfidence($normalizedQuestion, (string) $phrase);
        if ($confidence >= 0.86) {
            $matchedPhrases[] = (string) $phrase;
            $phraseScore = max($phraseScore, $confidence);
        }
    }

    $requiredConcepts = $intentDefinition['required_concepts'] ?? [];
    $requiredMatched = 0;

    foreach ($requiredConcepts as $concept) {
        if (chatbotConceptMatches($normalizedQuestion, (string) $concept)) {
            $requiredMatched++;
            $matchedConcepts[] = (string) $concept;
        }
    }

    $requiredTotal = count($requiredConcepts);
    $requiredScore = $requiredTotal > 0 ? $requiredMatched / $requiredTotal : 1.0;

    if ($requiredTotal > 0 && $requiredMatched < $requiredTotal && $phraseScore < 0.92) {
        return [
            'confidence' => 0.0,
            'matched_phrases' => $matchedPhrases,
            'matched_concepts' => $matchedConcepts,
        ];
    }

    $optionalMatched = 0;
    foreach ($intentDefinition['optional_concepts'] ?? [] as $concept) {
        if (chatbotConceptMatches($normalizedQuestion, (string) $concept)) {
            $optionalMatched++;
            $matchedConcepts[] = (string) $concept;
        }
    }

    $optionalTotal = count($intentDefinition['optional_concepts'] ?? []);
    $optionalScore = $optionalTotal > 0 ? min(1.0, $optionalMatched / $optionalTotal) : 0.0;
    $confidence = min(0.98, (0.55 * $requiredScore) + (0.25 * $phraseScore) + (0.15 * $optionalScore));

    if ($phraseScore >= 0.95) {
        $confidence = max($confidence, 0.86);
    }

    return [
        'confidence' => round($confidence, 2),
        'matched_phrases' => array_values(array_unique($matchedPhrases)),
        'matched_concepts' => array_values(array_unique($matchedConcepts)),
    ];
}

function chatbotDetectRegistryIntent(string $normalizedQuestion): ?array
{
    $bestIntent = null;
    $bestScore = [
        'confidence' => 0.0,
        'matched_phrases' => [],
        'matched_concepts' => [],
    ];

    foreach (chatbotGetIntentRegistry() as $intent => $definition) {
        $score = chatbotScoreIntent($normalizedQuestion, $definition);
        if ((float) $score['confidence'] > (float) $bestScore['confidence']) {
            $bestIntent = (string) $intent;
            $bestScore = $score;
        }
    }

    if ($bestIntent === null || (float) $bestScore['confidence'] < 0.62) {
        return null;
    }

    $definition = chatbotGetIntentRegistry()[$bestIntent] ?? [];

    return [
        'intent' => $bestIntent,
        'period' => chatbotDetectPeriodFromQuestion($normalizedQuestion, (string) ($definition['default_period'] ?? 'month')),
        'confidence' => (float) $bestScore['confidence'],
        'matched_phrases' => $bestScore['matched_phrases'],
        'matched_concepts' => $bestScore['matched_concepts'],
        'role_access' => $definition['roles'] ?? [],
    ];
}

function chatbotDetectIntent(string $question): array
{
    $normalized = chatbotNormalizeQuestion($question);

    if ($normalized === '') {
        throw new InvalidArgumentException('Please type a question for the assistant.');
    }

    if (
        in_array($normalized, ['show me those items', 'show those items', 'show them', 'open those', 'show that list'], true)
        && !empty($_SESSION['chatbot_last_context']['intent'])
        && (time() - (int) ($_SESSION['chatbot_last_context']['saved_at'] ?? 0)) < 900
    ) {
        return [
            'intent' => 'followup_last_context',
            'previous_intent' => (string) $_SESSION['chatbot_last_context']['intent'],
        ];
    }

    if (chatbotMatchesPhrase($normalized, ['hello', 'hi', 'good morning', 'good afternoon', 'good evening'])) {
        return ['intent' => 'greeting'];
    }

    if (
        str_contains($normalized, 'system status')
        || str_contains($normalized, 'are you online')
        || str_contains($normalized, 'are you there')
        || str_contains($normalized, 'system check')
        || $normalized === 'status'
    ) {
        return ['intent' => 'system_status'];
    }

    if (
        str_contains($normalized, 'did the system encounter errors today')
        || str_contains($normalized, 'did the system have errors today')
        || str_contains($normalized, 'were there errors today')
        || str_contains($normalized, 'did the system log errors today')
    ) {
        return ['intent' => 'system_errors_today'];
    }

    if (
        str_contains($normalized, 'show recent chatbot errors')
        || str_contains($normalized, 'recent chatbot errors')
        || str_contains($normalized, 'show chatbot errors')
    ) {
        return ['intent' => 'recent_chatbot_errors'];
    }

    if (
        str_contains($normalized, 'what was the last system error')
        || str_contains($normalized, 'show the last system error')
        || str_contains($normalized, 'latest system error')
    ) {
        return ['intent' => 'last_system_error'];
    }

    if (
        preg_match('/\b(?:show|find|lookup|open)\s+(?:transaction|receipt|sale)\s+(sale-\d{8}-\d{6})\b/i', $question, $matches)
        || preg_match('/\b(sale-\d{8}-\d{6})\b/i', $question, $matches)
    ) {
        return [
            'intent' => 'transaction_lookup',
            'transaction_no' => strtoupper((string) $matches[1]),
            'confidence' => 1.0,
            'matched_phrases' => ['transaction number'],
            'matched_concepts' => ['sales', 'receipt'],
        ];
    }

    if (
        preg_match('/why did\s+(.+?)\s+stock\s+(?:change|go down|go up|move)\??$/i', $question, $matches)
        || preg_match('/why\s+(?:is|did)\s+(.+?)\s+stock/i', $question, $matches)
        || preg_match('/stock movement(?:s)?\s+(?:for|of)\s+(.+?)\??$/i', $question, $matches)
        || preg_match('/stock audit\s+(?:for|of)\s+(.+?)\??$/i', $question, $matches)
    ) {
        return [
            'intent' => 'stock_movement_lookup',
            'product' => trim((string) $matches[1]),
            'confidence' => 1.0,
            'matched_phrases' => ['stock movement lookup'],
            'matched_concepts' => ['stock', 'movement', 'product'],
        ];
    }

    if (
        str_contains($normalized, 'stock audit')
        || str_contains($normalized, 'stock movements')
        || str_contains($normalized, 'why did stock change')
    ) {
        return ['intent' => 'stock_movement_lookup'];
    }

    if (
        str_contains($normalized, 'how much did we spend')
        || str_contains($normalized, 'expense summary')
        || str_contains($normalized, 'expenses this month')
        || str_contains($normalized, 'spending this month')
    ) {
        return ['intent' => 'expense_summary'];
    }

    if (
        str_contains($normalized, 'top expense category')
        || str_contains($normalized, 'highest expense category')
    ) {
        return ['intent' => 'expense_top_category'];
    }

    if (
        str_contains($normalized, 'largest expense')
        || str_contains($normalized, 'biggest expense')
    ) {
        return ['intent' => 'expense_largest'];
    }

    if (
        str_contains($normalized, 'pending approvals')
        || str_contains($normalized, 'any approvals')
        || str_contains($normalized, 'pending stock requests')
        || str_contains($normalized, 'sale void requests')
        || str_contains($normalized, 'sale return requests')
    ) {
        return ['intent' => 'approval_summary'];
    }

    if (
        str_contains($normalized, 'pos still pending')
        || str_contains($normalized, 'purchase orders still pending')
        || str_contains($normalized, 'what pos are still pending')
        || str_contains($normalized, 'pending purchase orders')
    ) {
        return ['intent' => 'po_pending'];
    }

    if (
        str_contains($normalized, 'what arrived today')
        || str_contains($normalized, 'received today')
        || str_contains($normalized, 'purchase orders received today')
    ) {
        return ['intent' => 'po_received_today'];
    }

    if (
        str_contains($normalized, 'which supplier should i order from')
        || str_contains($normalized, 'supplier should i order from')
        || str_contains($normalized, 'best supplier to order')
    ) {
        return ['intent' => 'supplier_reorder_advice'];
    }

    if (preg_match('/\bmy sales(?:\s+(today|this week|this month|this year))?\b/i', $normalized, $matches)) {
        $periodRaw = strtolower((string) ($matches[1] ?? 'today'));
        return [
            'intent' => 'cashier_sales_summary',
            'period' => match ($periodRaw) {
                'this week' => 'week',
                'this month' => 'month',
                'this year' => 'year',
                default => 'today',
            },
        ];
    }

    if (preg_match('/\bmy revenue(?:\s+(today|this week|this month|this year))?\b/i', $normalized, $matches)) {
        $periodRaw = strtolower((string) ($matches[1] ?? 'today'));
        return [
            'intent' => 'cashier_revenue_summary',
            'period' => match ($periodRaw) {
                'this week' => 'week',
                'this month' => 'month',
                'this year' => 'year',
                default => 'today',
            },
        ];
    }

    if (preg_match('/\bmy items sold(?:\s+(today|this week|this month|this year))?\b/i', $normalized, $matches)) {
        $periodRaw = strtolower((string) ($matches[1] ?? 'today'));
        return [
            'intent' => 'cashier_items_sold_summary',
            'period' => match ($periodRaw) {
                'this week' => 'week',
                'this month' => 'month',
                'this year' => 'year',
                default => 'today',
            },
        ];
    }

    if (
        str_contains($normalized, 'my recent transactions')
        || str_contains($normalized, 'my latest transactions')
        || str_contains($normalized, 'show my recent transactions')
        || str_contains($normalized, 'show my latest transactions')
    ) {
        return [
            'intent' => 'cashier_recent_transactions',
            'period' => 'month',
        ];
    }

    if (
        str_contains($normalized, 'my payment methods')
        || str_contains($normalized, 'my payment summary')
        || str_contains($normalized, 'my payment breakdown')
        || str_contains($normalized, 'what payment methods did my customers use this month')
    ) {
        return [
            'intent' => 'cashier_payment_breakdown',
            'period' => 'month',
        ];
    }

    if (
        str_contains($normalized, 'my shift summary')
        || str_contains($normalized, 'how was my shift')
        || str_contains($normalized, 'shift summary')
    ) {
        return ['intent' => 'cashier_shift_summary'];
    }

    if (
        str_contains($normalized, 'my last receipt')
        || str_contains($normalized, 'last transaction')
        || str_contains($normalized, 'reprint my last receipt')
        || str_contains($normalized, 'what was my last transaction')
    ) {
        return ['intent' => 'cashier_last_receipt'];
    }

    if (
        preg_match('/how many\s+(pieces?|boxes?|cases?)\s+of\s+(.+?)\s+were sold\s+(today|this week|this month|this year)\??$/i', $question, $matches)
        || preg_match('/how many\s+(pieces?|boxes?|cases?)\s+of\s+(.+?)\s+sold\s+(today|this week|this month|this year)\??$/i', $question, $matches)
    ) {
        $unitRaw = strtolower((string) ($matches[1] ?? 'piece'));
        $product = trim((string) ($matches[2] ?? ''));
        $periodRaw = strtolower((string) ($matches[3] ?? 'this week'));

        $unitType = match (true) {
            str_starts_with($unitRaw, 'box') => 'box',
            str_starts_with($unitRaw, 'case') => 'case',
            default => 'piece',
        };

        $period = match ($periodRaw) {
            'today' => 'today',
            'this month' => 'month',
            'this year' => 'year',
            default => 'week',
        };

        return [
            'intent' => 'product_unit_sales',
            'product' => $product,
            'unit_type' => $unitType,
            'period' => $period,
            'confidence' => 1.0,
            'matched_phrases' => ['product unit sales'],
            'matched_concepts' => ['product', 'sales'],
        ];
    }

    $registryIntent = chatbotDetectRegistryIntent($normalized);
    if ($registryIntent !== null) {
        return $registryIntent;
    }

    if (
        str_contains($normalized, 'how many out-of-stock products')
        || str_contains($normalized, 'how many out of stock products')
        || str_contains($normalized, 'total out-of-stock products')
        || str_contains($normalized, 'out of stock count')
    ) {
        return ['intent' => 'out_of_stock_summary'];
    }

    if (
        chatbotHasConcept($normalized, ['stock', 'stocks', 'inventory', 'product', 'products', 'item', 'items'])
        && chatbotHasConcept($normalized, ['low', 'few', 'reorder', 'restock', 'replenish', 'shortage', 'short', 'critical', 'empty', 'running out', 'almost empty', 'not enough'])
    ) {
        return ['intent' => 'low_stock'];
    }

    if (
        str_contains($normalized, 'low in stock')
        || str_contains($normalized, 'low stock')
        || str_contains($normalized, 'below reorder')
        || str_contains($normalized, 'close to running out')
        || str_contains($normalized, 'running out')
        || str_contains($normalized, 'out-of-stock')
        || str_contains($normalized, 'out of stock')
        
    ) {
        return ['intent' => 'low_stock'];
    }

    if (
        str_contains($normalized, 'top category')
        || str_contains($normalized, 'best category')
        || str_contains($normalized, 'category sold best')
        || str_contains($normalized, 'what category sold best')
        || str_contains($normalized, 'highest category')
        || (
            chatbotHasConcept($normalized, ['category', 'categories'])
            && chatbotHasConcept($normalized, ['top', 'best', 'sold', 'selling', 'sale', 'sales', 'revenue', 'popular'])
        )
    ) {
        return [
            'intent' => 'top_categories',
            'period' => chatbotDetectPeriodFromQuestion($normalized, 'month'),
        ];
    }

    if (
        str_contains($normalized, 'sold best today')
        || str_contains($normalized, 'best seller today')
        || str_contains($normalized, 'best selling today')
        || str_contains($normalized, 'what products sold best today?')
        || (
            !chatbotHasConcept($normalized, ['category', 'categories'])
            && chatbotHasConcept($normalized, ['product', 'products', 'item', 'items'])
            && chatbotHasConcept($normalized, ['top', 'best', 'sold', 'selling', 'seller', 'popular', 'fast moving'])
            && chatbotDetectPeriodFromQuestion($normalized, 'today') === 'today'
        )
    ) {
        return [
            'intent' => 'best_sellers',
            'period' => 'today',
        ];
    }

    if (
        str_contains($normalized, 'sold best this week')
        || str_contains($normalized, 'best seller this week')
        || str_contains($normalized, 'best selling this week')
        || (
            !chatbotHasConcept($normalized, ['category', 'categories'])
            && chatbotHasConcept($normalized, ['product', 'products', 'item', 'items'])
            && chatbotHasConcept($normalized, ['top', 'best', 'sold', 'selling', 'seller', 'popular', 'fast moving'])
            && chatbotDetectPeriodFromQuestion($normalized, 'month') === 'week'
        )
    ) {
        return [
            'intent' => 'best_sellers',
            'period' => 'week',
        ];
    }

    if (
        str_contains($normalized, 'sold best this month')
        || str_contains($normalized, 'best seller this month')
        || str_contains($normalized, 'best selling this month')
        || (
            !chatbotHasConcept($normalized, ['category', 'categories'])
            && chatbotHasConcept($normalized, ['product', 'products', 'item', 'items'])
            && chatbotHasConcept($normalized, ['top', 'best', 'sold', 'selling', 'seller', 'popular', 'fast moving'])
            && chatbotDetectPeriodFromQuestion($normalized, 'month') === 'month'
        )
    ) {
        return [
            'intent' => 'best_sellers',
            'period' => 'month',
        ];
    }

    if (
        str_contains($normalized, 'sold best this year')
        || str_contains($normalized, 'best seller this year')
        || str_contains($normalized, 'best selling this year')
        || (
            !chatbotHasConcept($normalized, ['category', 'categories'])
            && chatbotHasConcept($normalized, ['product', 'products', 'item', 'items'])
            && chatbotHasConcept($normalized, ['top', 'best', 'sold', 'selling', 'seller', 'popular', 'fast moving'])
            && chatbotDetectPeriodFromQuestion($normalized, 'month') === 'year'
        )
    ) {
        return [
            'intent' => 'best_sellers',
            'period' => 'year',
        ];
    }

    if (
        str_contains($normalized, 'should i reorder')
        || str_contains($normalized, 'which items should i reorder')
        || str_contains($normalized, 'which products should i reorder')
        || str_contains($normalized, 'what should i reorder')
        || str_contains($normalized, 'what should i restock first')
        || str_contains($normalized, 'what items are close to running out')
        || (
            chatbotHasConcept($normalized, ['order', 'reorder', 'restock', 'replenish', 'supplier', 'buy'])
            && chatbotHasConcept($normalized, ['need', 'needs', 'should', 'which', 'what', 'suggest', 'recommend'])
        )
    ) {
        return ['intent' => 'reorder_suggestions'];
    }

    if (
        str_contains($normalized, 'slow-moving')
        || str_contains($normalized, 'slow moving')
        || str_contains($normalized, 'dead stock')
        || str_contains($normalized, 'what are the slow moving products')
        || (
            chatbotHasConcept($normalized, ['product', 'products', 'item', 'items', 'stock'])
            && chatbotHasConcept($normalized, ['slow', 'stale', 'dead', 'not selling', 'no sales'])
        )
    ) {
        return ['intent' => 'slow_moving'];
    }

    if (
        str_contains($normalized, 'total revenue this month')
        || str_contains($normalized, 'revenue this month')
        || str_contains($normalized, 'what is the total revenue this month')
        || str_contains($normalized, 'revenue this week')
        || str_contains($normalized, 'total revenue this week')
        || str_contains($normalized, 'what is the total revenue this week')
    ) {
        return [
            'intent' => 'revenue_summary',
            'period' => chatbotDetectPeriodFromQuestion($normalized, 'month'),
        ];
    }

    if (
        str_contains($normalized, 'total revenue today')
        || str_contains($normalized, 'revenue today')
        || str_contains($normalized, 'what is the total revenue today')
    ) {
        return [
            'intent' => 'revenue_summary',
            'period' => 'today',
        ];
    }

    if (
        str_contains($normalized, 'total revenue this year')
        || str_contains($normalized, 'revenue this year')
        || str_contains($normalized, 'what is the total revenue this year')
    ) {
        return [
            'intent' => 'revenue_summary',
            'period' => 'year',
        ];
    }

    if (
        str_contains($normalized, 'how many sales today')
        || str_contains($normalized, 'sales count today')
        || str_contains($normalized, 'how many transactions today')
        || str_contains($normalized, 'how many sales this week')
        || str_contains($normalized, 'sales count this week')
        || str_contains($normalized, 'how many transactions this week')
    ) {
        return [
            'intent' => 'sales_summary',
            'period' => chatbotDetectPeriodFromQuestion($normalized, 'today'),
        ];
    }

    if (
        str_contains($normalized, 'how many sales this month')
        || str_contains($normalized, 'sales count this month')
        || str_contains($normalized, 'how many transactions this month')
    ) {
        return [
            'intent' => 'sales_summary',
            'period' => 'month',
        ];
    }

    if (
        str_contains($normalized, 'how many sales this year')
        || str_contains($normalized, 'sales count this year')
        || str_contains($normalized, 'how many transactions this year')
    ) {
        return [
            'intent' => 'sales_summary',
            'period' => 'year',
        ];
    }

    if (
        str_contains($normalized, 'how many products sold today')
        || str_contains($normalized, 'how many products were sold today')
        || str_contains($normalized, 'how many items sold today')
        || str_contains($normalized, 'how many items were sold today')
    ) {
        return [
            'intent' => 'items_sold_summary',
            'period' => 'today',
        ];
    }

    if (
        str_contains($normalized, 'how many products sold this week')
        || str_contains($normalized, 'how many products were sold this week')
        || str_contains($normalized, 'how many items sold this week')
        || str_contains($normalized, 'how many items were sold this week')
    ) {
        return [
            'intent' => 'items_sold_summary',
            'period' => 'week',
        ];
    }

    if (
        str_contains($normalized, 'how many products sold this month')
        || str_contains($normalized, 'how many products were sold this month')
        || str_contains($normalized, 'how many items sold this month')
        || str_contains($normalized, 'how many items were sold this month')
    ) {
        return [
            'intent' => 'items_sold_summary',
            'period' => 'month',
        ];
    }

    if (
        str_contains($normalized, 'how many products sold this year')
        || str_contains($normalized, 'how many products were sold this year')
        || str_contains($normalized, 'how many items sold this year')
        || str_contains($normalized, 'how many items were sold this year')
    ) {
        return [
            'intent' => 'items_sold_summary',
            'period' => 'year',
        ];
    }

    if (
        str_contains($normalized, 'how many out-of-stock products')
        || str_contains($normalized, 'how many out of stock products')
        || str_contains($normalized, 'total out-of-stock products')
        || str_contains($normalized, 'out of stock count')
    ) {
        return ['intent' => 'out_of_stock_summary'];
    }

    if (
        str_contains($normalized, 'payment breakdown this month')
        || str_contains($normalized, 'most used payment method this month')
        || str_contains($normalized, 'what payment method is used most this month')
        || str_contains($normalized, 'payment breakdown this week')
        || str_contains($normalized, 'what payment method is used most this week')
        || str_contains($normalized, 'payment breakdown today')
        || str_contains($normalized, 'what payment method is used most today')
        || str_contains($normalized, 'payment breakdown this year')
        || str_contains($normalized, 'what payment method is used most this year')
        || (
            chatbotHasConcept($normalized, ['payment', 'payments', 'paid', 'pay', 'method', 'methods', 'cash', 'gcash', 'card', 'ewallet', 'wallet'])
            && chatbotHasConcept($normalized, ['used', 'use', 'most', 'breakdown', 'summary', 'popular', 'common', 'total', 'totals'])
        )
    ) {
        return [
            'intent' => 'payment_breakdown',
            'period' => chatbotDetectPeriodFromQuestion($normalized, 'month'),
        ];
    }

    if (
        str_contains($normalized, 'top category this month')
        || str_contains($normalized, 'best category this month')
        || str_contains($normalized, 'what category sold best this month')
        || str_contains($normalized, 'what category sold best today')
        || str_contains($normalized, 'top category this week')  
        || str_contains($normalized, 'best category this week')
        || str_contains($normalized, 'what category sold best this week')
        || str_contains($normalized, 'top category this year')
        || str_contains($normalized, 'best category this year')
        || str_contains($normalized, 'what category sold best this year')
    ) {
        return [
            'intent' => 'top_categories',
            'period' => str_contains($normalized, 'today') ? 'today' : (str_contains($normalized, 'week') ? 'week' : (str_contains($normalized, 'year') ? 'year' : 'month')),
        ];
    }

    if (
        str_contains($normalized, 'top revenue products')
        || str_contains($normalized, 'highest revenue products')
        || str_contains($normalized, 'products that earn the most revenue')
        || str_contains($normalized, 'what products earn the most revenue')
    ) {
        return [
            'intent' => 'top_revenue_products',
            'period' => str_contains($normalized, 'today') ? 'today' : (str_contains($normalized, 'week') ? 'week' : (str_contains($normalized, 'year') ? 'year' : 'month')),
        ];
    }

    if (
        str_contains($normalized, 'products with no sales this month')
        || str_contains($normalized, 'no sales this month')
        || str_contains($normalized, 'products not selling')
        || str_contains($normalized, 'dead stock this month')
        || str_contains($normalized, 'what are the slow moving products this month')
    ) {
        return ['intent' => 'no_sales_this_month'];
    }

    throw new InvalidArgumentException('I am not sure what you mean. Did you want to check low stock, best sellers, sales, revenue, payment methods, purchase orders, suppliers, or expenses?');
}

function chatbotFormatPeriodLabel(string $period): string
{
    return match ($period) {
        'today' => 'today',
        'week' => 'the last 7 days',
        'month' => 'the last 30 days',
        'year' => 'the last 365 days',
        default => $period,
    };
}

function chatbotTableExists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table
    ");
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function chatbotTransactionSaleId(string $transactionNo): int
{
    if (!preg_match('/^SALE-\d{8}-(\d{6})$/i', trim($transactionNo), $matches)) {
        return 0;
    }

    return (int) ltrim((string) $matches[1], '0');
}

function chatbotStockMovements(PDO $conn, ?string $productSearch = null, int $limit = 6): array
{
    $limit = max(1, min(12, $limit));
    $params = [];
    $where = '';

    if ($productSearch !== null && trim($productSearch) !== '') {
        $where = "WHERE p.product_name LIKE :product OR p.sku LIKE :product";
        $params[':product'] = '%' . trim($productSearch) . '%';
    }

    $stmt = $conn->prepare("
        SELECT
            sal.log_id,
            sal.change_qty,
            sal.current_qty,
            sal.action,
            sal.reference_type,
            sal.reference_id,
            sal.notes,
            sal.timestamp,
            p.product_name,
            p.sku,
            u.first_name,
            u.last_name,
            u.username
        FROM stock_audit_log sal
        INNER JOIN products p ON p.product_id = sal.product_id
        LEFT JOIN users u ON u.user_id = sal.user_id
        {$where}
        ORDER BY sal.timestamp DESC, sal.log_id DESC
        LIMIT :limit
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function chatbotExpenseRows(PDO $conn, string $mode): array
{
    if (!chatbotTableExists($conn, 'expenses')) {
        return [];
    }

    if ($mode === 'category') {
        $stmt = $conn->query("
            SELECT category, SUM(amount) AS total, COUNT(*) AS entries
            FROM expenses
            WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY category
            ORDER BY total DESC, category ASC
            LIMIT 5
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    if ($mode === 'largest') {
        $stmt = $conn->query("
            SELECT category, amount, notes, expense_date
            FROM expenses
            WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY amount DESC, expense_id DESC
            LIMIT 5
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    $stmt = $conn->query("
        SELECT
            IFNULL(SUM(amount), 0) AS total,
            COUNT(*) AS entries,
            IFNULL(AVG(amount), 0) AS average_amount
        FROM expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    return $row === [] ? [] : [$row];
}

function chatbotApprovalSummary(PDO $conn): array
{
    $rows = [];

    if (chatbotTableExists($conn, 'stock_adjustment_requests')) {
        $count = (int) $conn->query("SELECT COUNT(*) FROM stock_adjustment_requests WHERE status = 'pending'")->fetchColumn();
        $rows[] = ['title' => 'Pending stock requests', 'value' => $count, 'note' => 'Needs admin review'];
    }

    if (chatbotTableExists($conn, 'sale_action_requests')) {
        $count = (int) $conn->query("SELECT COUNT(*) FROM sale_action_requests WHERE status = 'pending'")->fetchColumn();
        $rows[] = ['title' => 'Pending sale void/return requests', 'value' => $count, 'note' => 'Cashier requests'];
    }

    if (chatbotTableExists($conn, 'shift_closing_edit_requests')) {
        $count = (int) $conn->query("SELECT COUNT(*) FROM shift_closing_edit_requests WHERE status = 'pending'")->fetchColumn();
        $rows[] = ['title' => 'Pending shift edit requests', 'value' => $count, 'note' => 'Drawer correction requests'];
    }

    return $rows;
}

function chatbotPurchaseOrderRows(PDO $conn, string $mode): array
{
    if (!chatbotTableExists($conn, 'purchase_orders')) {
        return [];
    }

    if ($mode === 'received_today') {
        $stmt = $conn->query("
            SELECT po.po_id, po.po_number, po.status, po.received_at, s.supplier_name
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
            WHERE DATE(po.received_at) = CURDATE()
            ORDER BY po.received_at DESC, po.po_id DESC
            LIMIT 6
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    $stmt = $conn->query("
        SELECT po.po_id, po.po_number, po.status, po.ordered_at, s.supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
        WHERE po.status IN ('ordered', 'partial')
        ORDER BY po.ordered_at DESC, po.po_id DESC
        LIMIT 6
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function chatbotSupplierAdvice(PDO $conn): array
{
    $stmt = $conn->query("
        SELECT
            s.supplier_id,
            s.supplier_name,
            COUNT(*) AS product_count,
            SUM(GREATEST(p.reorder_level - p.quantity, 0)) AS shortage
        FROM products p
        INNER JOIN suppliers s ON s.supplier_id = p.supplier_id
        WHERE p.status = 'active'
          AND p.quantity <= p.reorder_level
        GROUP BY s.supplier_id, s.supplier_name
        ORDER BY shortage DESC, product_count DESC, s.supplier_name ASC
        LIMIT 5
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function chatbotSaleLookup(PDO $conn, int $saleId, int $viewerId, string $role): ?array
{
    if ($saleId <= 0) {
        return null;
    }

    $sql = "
        SELECT s.sale_id, s.sale_date, s.total_amount, s.payment_method, s.status, s.user_id,
               u.first_name, u.last_name, u.username
        FROM sales s
        LEFT JOIN users u ON u.user_id = s.user_id
        WHERE s.sale_id = :sale_id
    ";
    $params = [':sale_id' => $saleId];

    if ($role !== 'admin') {
        $sql .= " AND s.user_id = :viewer_id";
        $params[':viewer_id'] = $viewerId;
    }

    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        return null;
    }

    $itemsStmt = $conn->prepare("
        SELECT p.product_name, si.quantity, si.unit_type, si.unit_multiplier, si.unit_price,
               (si.quantity * COALESCE(si.unit_multiplier, 1)) AS pieces,
               (si.quantity * si.unit_price) AS line_total
        FROM sale_items si
        INNER JOIN products p ON p.product_id = si.product_id
        WHERE si.sale_id = :sale_id
        ORDER BY si.sale_item_id ASC
        LIMIT 6
    ");
    $itemsStmt->execute([':sale_id' => $saleId]);
    $sale['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $sale;
}

try {
    $question = trim((string) ($_POST['question'] ?? ''));
    $detected = chatbotDetectIntent($question);
    $chatbotDetected = $detected;
    $intent = (string) ($detected['intent'] ?? '');
    $chatbotUserRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $isCashier = $chatbotUserRole === 'cashier';

    $adminOnlyIntents = [
        'best_sellers',
        'reorder_suggestions',
        'slow_moving',
        'revenue_summary',
        'sales_summary',
        'items_sold_summary',
        'payment_breakdown',
        'top_categories',
        'top_revenue_products',
        'no_sales_this_month',
        'product_unit_sales',
        'system_errors_today',
        'recent_chatbot_errors',
        'last_system_error',
        'stock_movement_lookup',
        'expense_summary',
        'expense_top_category',
        'expense_largest',
        'approval_summary',
        'po_pending',
        'po_received_today',
        'supplier_reorder_advice',
    ];

    if ($isCashier && in_array($intent, $adminOnlyIntents, true)) {
        chatbotJsonResponse([
            'success' => true,
            'intent' => 'restricted_admin_query',
            'question' => $question,
            'answer' => 'That report is available to admins only. I can help with your own sales, revenue, recent transactions, or shift summary instead.',
            'rows' => [],
            'suggestions' => [
                'My sales today',
                'My revenue today',
                'My recent transactions',
                'My shift summary',
            ],
            'actions' => [
                [
                    'label' => 'Open POS',
                    'url'   => '/inventory_system/product_management/pos.php',
                    'icon'  => 'bi-shop',
                ],
                [
                    'label' => 'My Dashboard',
                    'url'   => '/inventory_system/index.php',
                    'icon'  => 'bi-speedometer2',
                ],
            ],
        ]);
    }

    switch ($intent) {
        case 'greeting':
            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $isCashier
                    ? 'Good day. StockWise AI online. Your cashier tools and sales summary are ready.'
                    : 'Good day, boss. StockWise AI online. All systems are operational and ready to assist you.',
                'rows' => [],
            ]);

        case 'system_status':
            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $isCashier
                    ? 'All systems are running smoothly. Your cashier dashboard and sales tracking are fully operational.'
                    : 'All systems are running smoothly, boss. Inventory tracking and sales monitoring are fully operational.',
                'rows' => [],
            ]);

        case 'followup_last_context':
            $context = is_array($_SESSION['chatbot_last_context'] ?? null) ? $_SESSION['chatbot_last_context'] : [];
            $previousAnswer = (string) ($context['answer'] ?? '');
            chatbotJsonResponse([
                'success' => true,
                'intent' => (string) ($context['intent'] ?? 'followup_last_context'),
                'question' => $question,
                'answer' => $previousAnswer !== ''
                    ? 'Here is the list from our last topic again.'
                    : 'I do not have a recent list to show yet. Ask me about low stock, reorders, expenses, approvals, or a transaction first.',
                'rows' => is_array($context['rows'] ?? null) ? $context['rows'] : [],
                'actions' => is_array($context['actions'] ?? null) ? $context['actions'] : [],
            ]);

        case 'stock_movement_lookup':
            $productSearch = isset($detected['product']) ? (string) $detected['product'] : null;
            $rows = chatbotStockMovements($conn, $productSearch, 6);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'I could not find recent stock movement records' . ($productSearch ? ' for "' . $productSearch . '"' : '') . '.'
                    : ($productSearch
                        ? 'Here is why "' . $productSearch . '" stock changed recently. Each row shows the movement, actor, and stock after the change.'
                        : 'Here are the latest stock movements across inventory.'),
                'rows' => array_map(static function (array $row): array {
                    $actor = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                    if ($actor === '') {
                        $actor = (string) ($row['username'] ?? 'System');
                    }
                    $change = (int) ($row['change_qty'] ?? 0);
                    $reference = trim((string) ($row['reference_type'] ?? ''));

                    return [
                        'title' => (string) ($row['product_name'] ?? 'Product'),
                        'meta' => date('M d, Y h:i A', strtotime((string) ($row['timestamp'] ?? 'now'))) . ' | ' . $actor,
                        'value' => ($change > 0 ? '+' : '') . number_format($change) . ' pcs',
                        'note' => 'Stock after: ' . number_format((int) ($row['current_qty'] ?? 0)) . ($reference !== '' ? ' | ' . ucwords(str_replace('_', ' ', $reference)) : ''),
                    ];
                }, $rows),
                'actions' => [
                    [
                        'label' => 'View Stock Audit',
                        'url' => '/inventory_system/admin/stock_movement_audit.php',
                        'icon' => 'bi-activity',
                    ],
                    [
                        'label' => 'Open Products',
                        'url' => '/inventory_system/product_management/manage_product.php',
                        'icon' => 'bi-box-seam',
                    ],
                ],
            ]);

        case 'expense_summary':
            $rows = chatbotExpenseRows($conn, 'summary');
            $summary = $rows[0] ?? ['total' => 0, 'entries' => 0, 'average_amount' => 0];

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'Store expenses for the last 30 days total PHP ' . number_format((float) ($summary['total'] ?? 0), 2) . '.',
                'rows' => [[
                    'title' => '30-day expenses',
                    'meta' => number_format((int) ($summary['entries'] ?? 0)) . ' expense entrie(s)',
                    'value' => 'PHP ' . number_format((float) ($summary['total'] ?? 0), 2),
                    'note' => 'Average: PHP ' . number_format((float) ($summary['average_amount'] ?? 0), 2),
                ]],
            ]);

        case 'expense_top_category':
            $rows = chatbotExpenseRows($conn, 'category');

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No expenses were found in the last 30 days.'
                    : ($rows[0]['category'] ?? 'Expense') . ' is the top expense category in the last 30 days.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['category'] ?? 'Expense'),
                    'meta' => number_format((int) ($row['entries'] ?? 0)) . ' entrie(s)',
                    'value' => 'PHP ' . number_format((float) ($row['total'] ?? 0), 2),
                    'note' => '30-day category spend',
                ], $rows),
            ]);

        case 'expense_largest':
            $rows = chatbotExpenseRows($conn, 'largest');

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No expenses were found in the last 30 days.'
                    : 'These are the largest expense entries in the last 30 days.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['category'] ?? 'Expense'),
                    'meta' => date('M d, Y', strtotime((string) ($row['expense_date'] ?? 'now'))),
                    'value' => 'PHP ' . number_format((float) ($row['amount'] ?? 0), 2),
                    'note' => (string) (($row['notes'] ?? '') !== '' ? $row['notes'] : 'No note'),
                ], $rows),
            ]);

        case 'approval_summary':
            $rows = chatbotApprovalSummary($conn);
            $pendingTotal = array_sum(array_map(static fn(array $row): int => (int) ($row['value'] ?? 0), $rows));

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $pendingTotal === 0
                    ? 'There are no pending approvals right now.'
                    : 'There are ' . number_format($pendingTotal) . ' pending approval item(s) waiting for admin review.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['title'] ?? 'Pending request'),
                    'meta' => 'Approval queue',
                    'value' => number_format((int) ($row['value'] ?? 0)),
                    'note' => (string) ($row['note'] ?? ''),
                ], $rows),
            ]);

        case 'po_pending':
            $rows = chatbotPurchaseOrderRows($conn, 'pending');

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No purchase orders are currently pending.'
                    : 'These purchase orders are still ordered or partially received.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['po_number'] ?? 'PO'),
                    'meta' => (string) ($row['supplier_name'] ?? 'Supplier'),
                    'value' => ucfirst((string) ($row['status'] ?? 'ordered')),
                    'note' => !empty($row['ordered_at']) ? date('M d, Y h:i A', strtotime((string) $row['ordered_at'])) : 'No order date',
                ], $rows),
            ]);

        case 'po_received_today':
            $rows = chatbotPurchaseOrderRows($conn, 'received_today');

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No purchase orders were received today.'
                    : number_format(count($rows)) . ' purchase order(s) were received today.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['po_number'] ?? 'PO'),
                    'meta' => (string) ($row['supplier_name'] ?? 'Supplier'),
                    'value' => ucfirst((string) ($row['status'] ?? 'received')),
                    'note' => !empty($row['received_at']) ? date('M d, Y h:i A', strtotime((string) $row['received_at'])) : 'Received today',
                ], $rows),
            ]);

        case 'supplier_reorder_advice':
            $rows = chatbotSupplierAdvice($conn);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No supplier has urgent low-stock items right now.'
                    : 'Start with ' . ($rows[0]['supplier_name'] ?? 'the top supplier') . ' because they have the largest low-stock shortage.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['supplier_name'] ?? 'Supplier'),
                    'meta' => number_format((int) ($row['product_count'] ?? 0)) . ' low-stock product(s)',
                    'value' => number_format((int) ($row['shortage'] ?? 0)) . ' pcs short',
                    'note' => 'Based on current quantity vs reorder level',
                ], $rows),
            ]);

        case 'transaction_lookup':
            $transactionNo = (string) ($detected['transaction_no'] ?? '');
            $saleId = chatbotTransactionSaleId($transactionNo);
            $sale = chatbotSaleLookup($conn, $saleId, $sessionUserId, $chatbotUserRole);

            if ($sale === null) {
                chatbotJsonResponse([
                    'success' => true,
                    'intent' => $intent,
                    'question' => $question,
                    'answer' => 'I could not find that transaction, or your account is not allowed to view it.',
                    'rows' => [],
                ]);
            }

            $cashierName = trim((string) ($sale['first_name'] ?? '') . ' ' . (string) ($sale['last_name'] ?? ''));
            if ($cashierName === '') {
                $cashierName = (string) ($sale['username'] ?? 'Unknown');
            }
            $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $transactionNo . ' was processed by ' . $cashierName . ' for PHP ' . number_format((float) ($sale['total_amount'] ?? 0), 2) . '. Safe action mode: I can open the sale page, but I will not change the transaction from chat.',
                'rows' => array_merge([[
                    'title' => $transactionNo,
                    'meta' => date('M d, Y h:i A', strtotime((string) ($sale['sale_date'] ?? 'now'))) . ' | ' . strtoupper((string) ($sale['payment_method'] ?? '')),
                    'value' => 'PHP ' . number_format((float) ($sale['total_amount'] ?? 0), 2),
                    'note' => 'Status: ' . ucwords(str_replace('_', ' ', (string) ($sale['status'] ?? 'completed'))),
                ]], array_map(static fn(array $item): array => [
                    'title' => (string) ($item['product_name'] ?? 'Product'),
                    'meta' => ucfirst((string) ($item['unit_type'] ?? 'piece')) . ' x ' . number_format((int) ($item['quantity'] ?? 0)),
                    'value' => number_format((int) ($item['pieces'] ?? 0)) . ' pcs',
                    'note' => 'Line: PHP ' . number_format((float) ($item['line_total'] ?? 0), 2),
                ], $items)),
                'actions' => [
                    [
                        'label' => 'Open Transaction',
                        'url' => '/inventory_system/cashier_sales_history.php?sale_id=' . $saleId,
                        'icon' => 'bi-receipt',
                    ],
                    [
                        'label' => 'Request Return/Void',
                        'url' => '/inventory_system/cashier_sales_history.php?sale_id=' . $saleId,
                        'icon' => 'bi-shield-check',
                    ],
                ],
            ]);

        case 'system_errors_today':
            $rows = DashboardController::chatbotSystemErrorsToday();

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No system errors were logged today.'
                    : 'Yes. The system logged ' . number_format(count($rows)) . ' error(s) today.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => 'System error',
                    'meta' => (string) ($row['timestamp'] ?? 'Unknown time'),
                    'value' => (string) ($row['message'] ?? 'Unknown error'),
                    'note' => 'From php-error.log',
                ], array_slice($rows, 0, 5)),
            ]);

        case 'recent_chatbot_errors':
            $rows = DashboardController::chatbotRecentChatbotErrors(5);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'No recent chatbot errors were found in the system log.'
                    : 'These are the most recent chatbot-related errors from the system log.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => 'Chatbot error',
                    'meta' => (string) ($row['timestamp'] ?? 'Unknown time'),
                    'value' => (string) ($row['message'] ?? 'Unknown error'),
                    'note' => 'Assistant log entry',
                ], $rows),
            ]);

        case 'last_system_error':
            $row = DashboardController::chatbotLastSystemError();

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $row === null
                    ? 'No system errors have been logged yet.'
                    : 'Here is the most recent system error recorded by the application.',
                'rows' => $row === null ? [] : [[
                    'title' => 'Last system error',
                    'meta' => (string) ($row['timestamp'] ?? 'Unknown time'),
                    'value' => (string) ($row['message'] ?? 'Unknown error'),
                    'note' => 'Latest entry from php-error.log',
                ]],
            ]);

        case 'low_stock':
            $rows = DashboardController::chatbotLowStock($conn, 8);
            $answer = $rows === []
                ? 'All active products are currently above their reorder level.'
                : 'These are the products currently at or below their reorder level.';

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $answer,
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['product_name'] ?? ''),
                    'meta' => trim((string) (($row['category_name'] ?? '') !== '' ? $row['category_name'] : 'Uncategorized')),
                    'value' => (int) ($row['quantity'] ?? 0) . ' in stock',
                    'note' => 'Reorder level: ' . (int) ($row['reorder_level'] ?? 0),
                ], $rows),
            ]);

        case 'top_revenue_products':
            $period = (string) ($detected['period'] ?? 'month');
            $rows = DashboardController::topRevenueProducts($conn, $period, 5);
            $answer = $rows === []
                ? 'No product revenue data was found for ' . chatbotFormatPeriodLabel($period) . '.'
                : 'These products generated the highest revenue for ' . chatbotFormatPeriodLabel($period) . '.';

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $answer,
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['product_name'] ?? ''),
                    'meta' => number_format((float) ($row['total_sold'] ?? 0)) . ' pcs sold',
                    'value' => 'PHP ' . number_format((float) ($row['total_revenue'] ?? 0), 2),
                    'note' => 'Revenue leader',
                ], $rows),
            ]);

        case 'no_sales_this_month':
            $rows = DashboardController::noRecentSalesProducts($conn, 30, 8);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'Every active product has sales within the last 30 days.'
                    : 'These active products have not recorded sales in the last 30 days.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['product_name'] ?? ''),
                    'meta' => trim((string) (($row['category_name'] ?? '') !== '' ? $row['category_name'] : 'Uncategorized')),
                    'value' => number_format((int) ($row['quantity'] ?? 0)) . ' in stock',
                    'note' => !empty($row['last_sale_date'])
                        ? 'Last sale: ' . date('M d, Y', strtotime((string) $row['last_sale_date']))
                        : 'No sales in 30 days',
                ], $rows),
            ]);

        case 'cashier_sales_summary':
            $period = (string) ($detected['period'] ?? 'today');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $salesCount = DashboardController::userSalesCount($conn, $userId, $period);
            $revenue = DashboardController::userRevenue($conn, $userId, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'You completed ' . number_format($salesCount) . ' sale(s) for ' . chatbotFormatPeriodLabel($period) . ' with total revenue of PHP ' . number_format($revenue, 2) . '.',
                'rows' => [[
                    'title' => 'My sales summary',
                    'meta' => ucfirst($period),
                    'value' => number_format($salesCount) . ' sale(s)',
                    'note' => 'PHP ' . number_format($revenue, 2) . ' revenue',
                ]],
            ]);

        case 'cashier_revenue_summary':
            $period = (string) ($detected['period'] ?? 'today');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $revenue = DashboardController::userRevenue($conn, $userId, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'Your processed revenue for ' . chatbotFormatPeriodLabel($period) . ' is PHP ' . number_format($revenue, 2) . '.',
                'rows' => [[
                    'title' => 'My revenue',
                    'meta' => ucfirst($period),
                    'value' => 'PHP ' . number_format($revenue, 2),
                    'note' => 'Completed transactions from your account',
                ]],
            ]);

        case 'cashier_items_sold_summary':
            $period = (string) ($detected['period'] ?? 'today');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $itemsSold = DashboardController::userItemsSold($conn, $userId, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'You sold ' . number_format($itemsSold) . ' item(s) for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => [[
                    'title' => 'My items sold',
                    'meta' => ucfirst($period),
                    'value' => number_format($itemsSold) . ' item(s)',
                    'note' => 'Piece-equivalent quantity',
                ]],
            ]);

        case 'cashier_recent_transactions':
            $period = (string) ($detected['period'] ?? 'month');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $rows = DashboardController::userRecentSales($conn, $userId, 5, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'You have no recent transactions for ' . chatbotFormatPeriodLabel($period) . '.'
                    : 'These are your latest transactions for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => 'SALE-' . date('Ymd', strtotime((string) ($row['sale_date'] ?? 'now'))) . '-' . str_pad((string) (int) ($row['sale_id'] ?? 0), 6, '0', STR_PAD_LEFT),
                    'meta' => number_format((int) ($row['item_count'] ?? 0)) . ' item(s)',
                    'value' => 'PHP ' . number_format((float) ($row['total_amount'] ?? 0), 2),
                    'note' => ucfirst((string) ($row['payment_method'] ?? 'Unknown')),
                ], $rows),
            ]);

        case 'cashier_payment_breakdown':
            $period = (string) ($detected['period'] ?? 'month');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $rows = DashboardController::userPaymentBreakdown($conn, $userId, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $rows === []
                    ? 'You have no payment activity for ' . chatbotFormatPeriodLabel($period) . '.'
                    : 'This is your payment breakdown for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => ucfirst((string) ($row['payment_method'] ?? 'Unknown')),
                    'meta' => number_format((int) ($row['count'] ?? 0)) . ' sale(s)',
                    'value' => 'PHP ' . number_format((float) ($row['total'] ?? 0), 2),
                    'note' => 'Your processed payments',
                ], $rows),
            ]);

        case 'cashier_shift_summary':
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $todaySales = DashboardController::userSalesCount($conn, $userId, 'today');
            $todayRevenue = DashboardController::userRevenue($conn, $userId, 'today');
            $todayItems = DashboardController::userItemsSold($conn, $userId, 'today');
            $recent = DashboardController::userRecentSales($conn, $userId, 1, 'today');
            $latest = $recent[0] ?? null;
            $paymentBreakdown = DashboardController::userPaymentBreakdown($conn, $userId, 'month');
            $topPayment = $paymentBreakdown[0] ?? null;
            $avgSale = $todaySales > 0 ? round($todayRevenue / $todaySales, 2) : 0.0;

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'Here is your shift summary: ' . number_format($todaySales) . ' sale(s), PHP ' . number_format($todayRevenue, 2) . ' revenue, and ' . number_format($todayItems) . ' item(s) sold today.',
                'rows' => array_values(array_filter([
                    [
                        'title' => 'Transactions completed',
                        'meta' => 'Today',
                        'value' => number_format($todaySales) . ' sale(s)',
                        'note' => 'Your completed checkouts',
                    ],
                    [
                        'title' => 'Revenue processed',
                        'meta' => 'Today',
                        'value' => 'PHP ' . number_format($todayRevenue, 2),
                        'note' => 'Sales recorded under your account',
                    ],
                    [
                        'title' => 'Average sale',
                        'meta' => 'Today',
                        'value' => 'PHP ' . number_format($avgSale, 2),
                        'note' => 'Average transaction value',
                    ],
                    $topPayment ? [
                        'title' => ucfirst((string) ($topPayment['payment_method'] ?? 'Unknown')),
                        'meta' => 'Top payment method',
                        'value' => 'PHP ' . number_format((float) ($topPayment['total'] ?? 0), 2),
                        'note' => 'Most used this month',
                    ] : null,
                    $latest ? [
                        'title' => 'Last transaction',
                        'meta' => 'SALE-' . date('Ymd', strtotime((string) ($latest['sale_date'] ?? 'now'))) . '-' . str_pad((string) (int) ($latest['sale_id'] ?? 0), 6, '0', STR_PAD_LEFT),
                        'value' => 'PHP ' . number_format((float) ($latest['total_amount'] ?? 0), 2),
                        'note' => date('M d, Y h:i A', strtotime((string) ($latest['sale_date'] ?? 'now'))),
                    ] : null,
                ])),
            ]);

        case 'cashier_last_receipt':
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $latest = DashboardController::userRecentSales($conn, $userId, 1, 'month')[0] ?? null;

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $latest === null
                    ? 'You do not have any recent transaction to reference.'
                    : 'Your latest transaction is SALE-' . date('Ymd', strtotime((string) ($latest['sale_date'] ?? 'now'))) . '-' . str_pad((string) (int) ($latest['sale_id'] ?? 0), 6, '0', STR_PAD_LEFT) . '.',
                'rows' => $latest === null ? [] : [[
                    'title' => 'Latest receipt',
                    'meta' => date('M d, Y h:i A', strtotime((string) ($latest['sale_date'] ?? 'now'))),
                    'value' => 'PHP ' . number_format((float) ($latest['total_amount'] ?? 0), 2),
                    'note' => ucfirst((string) ($latest['payment_method'] ?? 'Unknown')),
                ]],
            ]);

        case 'best_sellers':
            $period = (string) ($detected['period'] ?? 'today');
            $rows = DashboardController::chatbotBestSellers($conn, $period, 5);
            $answer = $rows === []
                ? 'No completed sales were found for ' . chatbotFormatPeriodLabel($period) . '.'
                : 'These are the best-selling products for ' . chatbotFormatPeriodLabel($period) . '.';

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $answer,
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) ($row['product_name'] ?? ''),
                    'meta' => number_format((float) ($row['total_pieces'] ?? 0)) . ' pcs sold',
                    'value' => 'PHP ' . number_format((float) ($row['total_revenue'] ?? 0), 2),
                    'note' => 'Revenue',
                ], $rows),
            ]);

        case 'reorder_suggestions':
            $rows = DashboardController::chatbotReorderSuggestions($conn, 8);
            $answer = $rows === []
                ? 'Nothing needs an immediate reorder based on current stock and reorder levels.'
                : 'These products should be prioritized for restocking based on stock level and recent movement.';

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $answer,
                'rows' => array_map(static function (array $row): array {
                    $coverDays = $row['cover_days'] !== null
                        ? (string) $row['cover_days'] . ' day cover'
                        : 'No recent sales';
                    $stock = (int) ($row['quantity'] ?? 0);
                    $reorderLevel = (int) ($row['reorder_level'] ?? 0);
                    $suggested = (float) ($row['recommended_pieces'] ?? 0);

                    return [
                        'title' => (string) ($row['product_name'] ?? ''),
                        'meta' => 'Stock: ' . $stock . ' | Reorder: ' . $reorderLevel,
                        'value' => 'Suggest ' . number_format($suggested) . ' pcs',
                        'note' => 'Why: stock is ' . $stock . ', reorder level is ' . $reorderLevel . ', cover is ' . $coverDays . '.',
                    ];
                }, $rows),
            ]);

        case 'slow_moving':
            $rows = DashboardController::chatbotSlowMovingProducts($conn, 8);
            $answer = $rows === []
                ? 'No active products were found for slow-moving analysis.'
                : 'These are the slow-moving products based on the last 30 days of sales.';

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $answer,
                'rows' => array_map(static function (array $row): array {
                    $lastSaleDate = !empty($row['last_sale_date'])
                        ? date('M d, Y', strtotime((string) $row['last_sale_date']))
                        : 'No sales in last 30 days';

                    return [
                        'title' => (string) ($row['product_name'] ?? ''),
                        'meta' => trim((string) (($row['category_name'] ?? '') !== '' ? $row['category_name'] : 'Uncategorized')),
                        'value' => number_format((float) ($row['total_pieces_sold_30d'] ?? 0)) . ' pcs in 30 days',
                        'note' => $lastSaleDate,
                    ];
                }, $rows),
            ]);

        case 'revenue_summary':
            $period = (string) ($detected['period'] ?? 'month');
            $totalRevenue = DashboardController::revenue($conn, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'Total revenue for ' . chatbotFormatPeriodLabel($period) . ' is PHP ' . number_format($totalRevenue, 2) . '.',
                'rows' => [[
                    'title' => 'Revenue summary',
                    'meta' => ucfirst($period),
                    'value' => 'PHP ' . number_format($totalRevenue, 2),
                    'note' => 'Completed sales only',
                ]],
            ]);

        case 'sales_summary':
            $period = (string) ($detected['period'] ?? 'today');
            $salesCount = DashboardController::salesCount($conn, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'There were ' . number_format($salesCount) . ' completed sale(s) for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => [[
                    'title' => 'Sales summary',
                    'meta' => ucfirst($period),
                    'value' => number_format($salesCount) . ' sale(s)',
                    'note' => 'Completed transactions',
                ]],
            ]);

        case 'items_sold_summary':
            $period = (string) ($detected['period'] ?? 'today');
            $itemsSold = DashboardController::totalItemsSold($conn, $period);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'A total of ' . number_format($itemsSold) . ' item(s) were sold for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => [[
                    'title' => 'Items sold summary',
                    'meta' => ucfirst($period),
                    'value' => number_format($itemsSold) . ' item(s)',
                    'note' => 'Piece-equivalent quantity sold',
                ]],
            ]);

        case 'out_of_stock_summary':
            $outOfStockCount = DashboardController::outOfStockCount($conn);

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => 'There are currently ' . number_format($outOfStockCount) . ' active product(s) that are out of stock.',
                'rows' => [[
                    'title' => 'Out-of-stock products',
                    'meta' => 'Active products only',
                    'value' => number_format($outOfStockCount) . ' item(s)',
                    'note' => 'Needs restocking',
                ]],
            ]);

        case 'payment_breakdown':
            $period = (string) ($detected['period'] ?? 'month');
            $rows = DashboardController::paymentBreakdown($conn, $period);

            if ($rows === []) {
                chatbotJsonResponse([
                    'success' => true,
                    'intent' => $intent,
                    'question' => $question,
                    'answer' => 'No payment activity was found for ' . chatbotFormatPeriodLabel($period) . '.',
                    'rows' => [],
                ]);
            }

            $topMethod = $rows[0];
            usort($rows, static fn(array $a, array $b): int => (float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
            $topMethod = $rows[0];

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => ucfirst((string) ($topMethod['payment_method'] ?? 'Unknown')) . ' is the top payment method for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => ucfirst((string) ($row['payment_method'] ?? 'Unknown')),
                    'meta' => number_format((float) ($row['count'] ?? 0)) . ' sale(s)',
                    'value' => 'PHP ' . number_format((float) ($row['total'] ?? 0), 2),
                    'note' => 'Collected amount',
                ], $rows),
            ]);

        case 'top_categories':
            $period = (string) ($detected['period'] ?? 'month');
            $rows = DashboardController::chatbotTopCategories($conn, $period, 5);

            if ($rows === []) {
                chatbotJsonResponse([
                    'success' => true,
                    'intent' => $intent,
                    'question' => $question,
                    'answer' => 'No category sales were found for ' . chatbotFormatPeriodLabel($period) . '.',
                    'rows' => [],
                ]);
            }

            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => ($rows[0]['category_name'] ?? 'Uncategorized') . ' is the top-selling category for ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => array_map(static fn(array $row): array => [
                    'title' => (string) (($row['category_name'] ?? '') !== '' ? $row['category_name'] : 'Uncategorized'),
                    'meta' => number_format((float) ($row['total_pieces'] ?? 0)) . ' pcs sold',
                    'value' => 'PHP ' . number_format((float) ($row['total_revenue'] ?? 0), 2),
                    'note' => 'Category revenue',
                ], $rows),
            ]);

        case 'product_unit_sales':
            $product = (string) ($detected['product'] ?? '');
            $unitType = (string) ($detected['unit_type'] ?? 'piece');
            $period = (string) ($detected['period'] ?? 'week');
            $result = DashboardController::chatbotProductUnitSales($conn, $product, $unitType, $period);

            if ($result === null) {
                chatbotJsonResponse([
                    'success' => true,
                    'intent' => $intent,
                    'question' => $question,
                    'answer' => 'No matching ' . $unitType . ' sales were found for "' . $product . '" during ' . chatbotFormatPeriodLabel($period) . '.',
                    'rows' => [],
                ]);
            }

            $unitLabel = ucfirst((string) ($result['unit_type'] ?? $unitType));
            chatbotJsonResponse([
                'success' => true,
                'intent' => $intent,
                'question' => $question,
                'answer' => $result['product_name'] . ' sold ' . number_format((float) ($result['units_sold'] ?? 0)) . ' ' . strtolower($unitLabel) . '(s) during ' . chatbotFormatPeriodLabel($period) . '.',
                'rows' => [[
                    'title' => (string) ($result['product_name'] ?? $product),
                    'meta' => number_format((float) ($result['units_sold'] ?? 0)) . ' ' . strtolower($unitLabel) . '(s)',
                    'value' => number_format((float) ($result['pieces_sold'] ?? 0)) . ' pcs equivalent',
                    'note' => 'PHP ' . number_format((float) ($result['revenue'] ?? 0), 2) . ' revenue',
                ]],
            ]);
    }

    chatbotJsonResponse([
        'success' => false,
        'error' => 'Unsupported chatbot request.',
    ], 400);
} catch (InvalidArgumentException $e) {
    chatbotJsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
        'suggestions' => [
            'What products are low in stock?',
            'Best selling products this month',
            'How many sales today?',
            'Revenue this month',
            'Payment summary today',
            'Pending purchase orders',
            'How much did we spend?',
        ],
    ], 422);
} catch (Throwable $e) {
    error_log('[dashboard_chatbot] ' . $e->getMessage());

    chatbotJsonResponse([
        'success' => false,
        'error' => 'Unable to answer that question right now.',
    ], 500);
}
