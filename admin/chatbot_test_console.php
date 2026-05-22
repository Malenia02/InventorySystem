<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';

Middleware::auth()->role(['admin']);

$csrfToken = Middleware::generateCsrfToken();

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$sampleQuestions = [
    'what products sold the best?',
    'bset selling prodcts today',
    'what stock are low in the inventory?',
    'how much did we earn this month?',
    'cash or gcash breakdown today',
    'what category sold best?',
    'products with no sales',
    'show inventory movement',
    'what POs are pending?',
    'which supplier should I order from?',
    'largest expense',
    'my recent transactions',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Chatbot Test Console</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page chatbot-console-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Assistant Diagnostics</span>
                <h1 class="ops-title">Chatbot Test Console</h1>
                <p class="ops-copy">Test natural owner and cashier questions, then inspect the detected intent, confidence, matched phrases, matched concepts, and response output.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Mode</span>
                    <strong>Debug</strong>
                    <small>admin-only diagnostics</small>
                </div>
                <div class="ops-stat">
                    <span>Endpoint</span>
                    <strong>Live</strong>
                    <small>uses dashboard chatbot API</small>
                </div>
            </div>
        </div>
    </section>

    <section class="ops-panel chatbot-console-shell">
        <div class="chatbot-console-grid">
            <div class="chatbot-console-card">
                <span class="ops-eyebrow">Question Lab</span>
                <h2>Try a sample or type your own</h2>
                <p class="ops-muted">This uses the same backend as the dashboard assistant, but asks for debug metadata.</p>

                <form id="chatbotConsoleForm" class="chatbot-console-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <textarea id="chatbotConsoleQuestion" class="form-control" rows="5" placeholder="Example: what payment methods are used today?"></textarea>
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-3">
                        <button type="button" id="chatbotConsoleClear" class="btn btn-outline-primary">Clear</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i> Test Intent
                        </button>
                    </div>
                </form>

                <div class="chatbot-sample-wrap">
                    <span class="ops-detail-label">Sample prompts</span>
                    <div class="ops-chip-row mt-2">
                        <?php foreach ($sampleQuestions as $sample): ?>
                            <button type="button" class="ops-chip chatbot-sample-question" data-question="<?= e($sample) ?>"><?= e($sample) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="chatbot-console-card chatbot-console-result">
                <div class="d-flex justify-content-between gap-3 align-items-start">
                    <div>
                        <span class="ops-eyebrow">Detection Result</span>
                        <h2 id="chatbotConsoleIntent">No test yet</h2>
                        <p id="chatbotConsoleAnswer" class="ops-muted">Send a prompt to inspect how StockWise AI understands it.</p>
                    </div>
                    <span id="chatbotConsoleConfidence" class="ops-status is-info">Waiting</span>
                </div>

                <div class="chatbot-debug-grid">
                    <div>
                        <span class="ops-detail-label">Period</span>
                        <strong id="chatbotConsolePeriod">-</strong>
                    </div>
                    <div>
                        <span class="ops-detail-label">Success</span>
                        <strong id="chatbotConsoleSuccess">-</strong>
                    </div>
                    <div>
                        <span class="ops-detail-label">Rows</span>
                        <strong id="chatbotConsoleRows">0</strong>
                    </div>
                </div>

                <div class="chatbot-debug-section">
                    <span class="ops-detail-label">Matched phrases</span>
                    <div id="chatbotConsolePhrases" class="chatbot-debug-tags"></div>
                </div>

                <div class="chatbot-debug-section">
                    <span class="ops-detail-label">Matched concepts</span>
                    <div id="chatbotConsoleConcepts" class="chatbot-debug-tags"></div>
                </div>

                <div class="chatbot-debug-section">
                    <span class="ops-detail-label">Raw response</span>
                    <pre id="chatbotConsoleRaw" class="chatbot-debug-raw">{}</pre>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/chatbot-test-console.js"></script>
</body>
</html>
