ALTER TABLE products
    ADD COLUMN IF NOT EXISTS subcategory_id INT(11) DEFAULT NULL AFTER category_id;

CREATE TABLE IF NOT EXISTS subcategories (
    subcategory_id INT(11) NOT NULL AUTO_INCREMENT,
    category_id INT(11) NOT NULL,
    subcategory_name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subcategory_id),
    UNIQUE KEY uniq_category_subcategory (category_id, subcategory_name),
    KEY idx_subcategory_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pos_config
    ADD COLUMN IF NOT EXISTS shift_edit_window_hours INT(11) NOT NULL DEFAULT 8 AFTER logo,
    ADD COLUMN IF NOT EXISTS shift_unlock_window_hours INT(11) NOT NULL DEFAULT 2 AFTER shift_edit_window_hours;

ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER user_id,
    ADD COLUMN IF NOT EXISTS voided_at DATETIME DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS voided_by INT(11) DEFAULT NULL AFTER voided_at,
    ADD COLUMN IF NOT EXISTS void_reason TEXT DEFAULT NULL AFTER voided_by;

ALTER TABLE sale_items
    ADD COLUMN IF NOT EXISTS returned_quantity INT(11) NOT NULL DEFAULT 0 AFTER quantity;

CREATE TABLE IF NOT EXISTS sale_item_returns (
    return_id INT(11) NOT NULL AUTO_INCREMENT,
    sale_id INT(11) NOT NULL,
    sale_item_id INT(11) NOT NULL,
    product_id INT(11) NOT NULL,
    quantity INT(11) NOT NULL,
    unit_multiplier INT(11) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reason TEXT DEFAULT NULL,
    user_id INT(11) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (return_id),
    KEY idx_sale_item_returns_sale_id (sale_id),
    KEY idx_sale_item_returns_sale_item_id (sale_item_id),
    KEY idx_sale_item_returns_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sale_action_requests (
    request_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id INT(11) NOT NULL,
    sale_item_id INT(11) DEFAULT NULL,
    requester_user_id INT(11) NOT NULL,
    action_type VARCHAR(30) NOT NULL,
    quantity INT(11) DEFAULT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    review_note TEXT DEFAULT NULL,
    reviewer_user_id INT(11) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sale_action_requests_status (status),
    KEY idx_sale_action_requests_sale (sale_id),
    KEY idx_sale_action_requests_requester (requester_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope_type ENUM('username','ip','username_ip') NOT NULL,
    scope_key VARCHAR(191) NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    locked_until DATETIME DEFAULT NULL,
    captcha_required TINYINT(1) NOT NULL DEFAULT 0,
    last_notified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_scope (scope_type, scope_key),
    KEY idx_username (username),
    KEY idx_ip_address (ip_address),
    KEY idx_locked_until (locked_until),
    KEY idx_last_attempt_at (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    selector CHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY selector (selector),
    KEY user_id (user_id),
    KEY expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE stock_in
    ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS adjustment_type VARCHAR(50) DEFAULT NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS supplier_id INT(11) DEFAULT NULL AFTER adjustment_type;

ALTER TABLE stock_out
    ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS adjustment_type VARCHAR(50) DEFAULT NULL AFTER notes;

ALTER TABLE stock_audit_log
    ADD COLUMN IF NOT EXISTS reference_type VARCHAR(50) DEFAULT NULL AFTER action,
    ADD COLUMN IF NOT EXISTS reference_id INT(11) DEFAULT NULL AFTER reference_type,
    ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL AFTER reference_id;

CREATE TABLE IF NOT EXISTS stock_adjustment_requests (
    request_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT(11) NOT NULL,
    requester_user_id INT(11) NOT NULL,
    reviewer_user_id INT(11) DEFAULT NULL,
    direction VARCHAR(20) NOT NULL DEFAULT 'stock_out',
    quantity INT(11) NOT NULL,
    adjustment_type VARCHAR(50) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    notes TEXT DEFAULT NULL,
    supplier_id INT(11) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    review_note TEXT DEFAULT NULL,
    before_quantity INT(11) DEFAULT NULL,
    after_quantity INT(11) DEFAULT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    KEY idx_stock_adjustment_status (status),
    KEY idx_stock_adjustment_requester (requester_user_id),
    KEY idx_stock_adjustment_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS shift_closings (
    shift_closing_id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) DEFAULT NULL,
    shift_date DATE NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME DEFAULT NULL,
    editable_until DATETIME DEFAULT NULL,
    total_transactions INT(11) NOT NULL DEFAULT 0,
    total_items INT(11) NOT NULL DEFAULT 0,
    total_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    starting_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    counted_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    variance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_breakdown_json LONGTEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    last_updated_by INT(11) DEFAULT NULL,
    last_updated_at DATETIME DEFAULT NULL,
    override_reason TEXT DEFAULT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'closed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (shift_closing_id),
    UNIQUE KEY uniq_shift_closings_user_date (user_id, shift_date),
    KEY idx_shift_closings_status (status),
    KEY idx_shift_closings_closed_at (closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS shift_closing_edit_requests (
    request_id INT(11) NOT NULL AUTO_INCREMENT,
    shift_closing_id INT(11) NOT NULL,
    target_user_id INT(11) NOT NULL,
    requested_by INT(11) NOT NULL,
    request_reason TEXT NOT NULL,
    status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    reviewed_by INT(11) DEFAULT NULL,
    review_note TEXT DEFAULT NULL,
    approved_until DATETIME DEFAULT NULL,
    PRIMARY KEY (request_id),
    KEY idx_shift_edit_requests_shift (shift_closing_id),
    KEY idx_shift_edit_requests_status (status),
    KEY idx_shift_edit_requests_target (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id INT(11) NOT NULL AUTO_INCREMENT,
    po_number VARCHAR(40) DEFAULT NULL,
    supplier_id INT(11) NOT NULL,
    created_by INT(11) DEFAULT NULL,
    received_by INT(11) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ordered',
    notes TEXT DEFAULT NULL,
    ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (po_id),
    UNIQUE KEY uniq_purchase_orders_number (po_number),
    KEY idx_purchase_orders_supplier (supplier_id),
    KEY idx_purchase_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    po_item_id INT(11) NOT NULL AUTO_INCREMENT,
    po_id INT(11) NOT NULL,
    product_id INT(11) NOT NULL,
    ordered_quantity INT(11) NOT NULL DEFAULT 0,
    received_quantity INT(11) NOT NULL DEFAULT 0,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (po_item_id),
    KEY idx_purchase_order_items_po (po_id),
    KEY idx_purchase_order_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE expenses
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
    ADD COLUMN IF NOT EXISTS due_date DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS paid_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL;
