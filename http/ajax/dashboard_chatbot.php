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

function chatbotDetectIntent(string $question): array
{
    $normalized = chatbotNormalizeQuestion($question);

    if ($normalized === '') {
        throw new InvalidArgumentException('Please type a question for the assistant.');
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
        ];
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
        str_contains($normalized, 'sold best today')
        || str_contains($normalized, 'best seller today')
        || str_contains($normalized, 'best selling today')
        || str_contains($normalized, 'what products sold best today?')
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
    ) {
        return ['intent' => 'reorder_suggestions'];
    }

    if (
        str_contains($normalized, 'slow-moving')
        || str_contains($normalized, 'slow moving')
        || str_contains($normalized, 'dead stock')
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
            'period' => str_contains($normalized, 'week') ? 'week' : 'month',
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
            'period' => str_contains($normalized, 'week') ? 'week' : 'today',
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
    ) {
        return [
            'intent' => 'payment_breakdown',
            'period' => str_contains($normalized, 'today') ? 'today' : (str_contains($normalized, 'week') ? 'week' : (str_contains($normalized, 'year') ? 'year' : 'month')),
        ];
    }

    if (
        str_contains($normalized, 'what product sold best this month')
        || str_contains($normalized, 'top category this month')
        || str_contains($normalized, 'best category this month')
        || str_contains($normalized, 'what category sold best this month')
        || str_contains($normalized, 'what category sold best today')
        || str_contains($normalized, 'what product sold best this week')
        || str_contains($normalized, 'top category this week')
        || str_contains($normalized, 'best category this week')
        || str_contains($normalized, 'what category sold best this week')
        || str_contains($normalized, 'what product sold best this year')
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
    ) {
        return ['intent' => 'no_sales_this_month'];
    }

    throw new InvalidArgumentException('Try one of these: "What products are low in stock?", "What sold best today?", or "How many cases of Coke were sold this week?"');
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

                    return [
                        'title' => (string) ($row['product_name'] ?? ''),
                        'meta' => 'Stock: ' . (int) ($row['quantity'] ?? 0) . ' | Reorder: ' . (int) ($row['reorder_level'] ?? 0),
                        'value' => 'Suggest ' . number_format((float) ($row['recommended_pieces'] ?? 0)) . ' pcs',
                        'note' => $coverDays,
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
    ], 422);
} catch (Throwable $e) {
    error_log('[dashboard_chatbot] ' . $e->getMessage());

    chatbotJsonResponse([
        'success' => false,
        'error' => 'Unable to answer that question right now.',
    ], 500);
}
