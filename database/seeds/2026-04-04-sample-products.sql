INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Royal Cola 500ml', 1, 1, 1, 'SMP-BEV-001',
    25.00, 270.00, 520.00,
    1, 1, 5.00, 8.00, 10.00,
    144, 12, 2,
    '/inventory_system/assets/img/product-1.jpg', 24, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-BEV-001');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Wilkins Pure 500ml', 1, 3, 1, 'SMP-BEV-002',
    12.00, 130.00, 250.00,
    1, 0, NULL, NULL, NULL,
    240, 12, 2,
    '/inventory_system/assets/img/product-2.jpg', 24, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-BEV-002');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Kopiko Brown 180ml', 1, 4, 1, 'SMP-BEV-003',
    18.00, 200.00, 380.00,
    1, 1, NULL, 5.00, 7.00,
    96, 10, 2,
    '/inventory_system/assets/img/product-3.jpg', 20, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-BEV-003');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Zest-O Orange 250ml', 1, 9, 1, 'SMP-BEV-004',
    10.00, 110.00, 210.00,
    1, 1, NULL, NULL, 7.00,
    120, 12, 2,
    '/inventory_system/assets/img/product-4.jpg', 24, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-BEV-004');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Piattos Sour Cream 40g', 2, 2, 3, 'SMP-SNK-001',
    18.00, 200.00, 760.00,
    1, 1, NULL, 5.00, 8.00,
    200, 10, 4,
    '/inventory_system/assets/img/test1.png', 30, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-SNK-001');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Choco Mucho Bar', 2, 5, 3, 'SMP-SNK-002',
    12.00, 135.00, 500.00,
    1, 1, 3.00, 5.00, 7.00,
    180, 12, 4,
    '/inventory_system/assets/img/test2.png', 24, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-SNK-002');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Mega Sardines Tomato 155g', 3, NULL, 1, 'SMP-CAN-001',
    28.00, 320.00, 1260.00,
    1, 0, NULL, NULL, NULL,
    160, 10, 4,
    '/inventory_system/assets/img/product-5.jpg', 20, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-CAN-001');

INSERT INTO products (
    product_name, category_id, subcategory_id, supplier_id, sku,
    price, box_price, case_price,
    vatable, on_sale, sale_price, box_sale_price, case_sale_price,
    quantity, pieces_per_box, boxes_per_case,
    photo, reorder_level, status
)
SELECT
    'Pinoy Tasty Loaf 600g', 4, NULL, 1, 'SMP-BRD-001',
    38.00, 420.00, 800.00,
    1, 1, NULL, 4.00, 6.00,
    80, 10, 2,
    '/inventory_system/assets/img/test3.png', 12, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SMP-BRD-001');

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 144, 144, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-BEV-001'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 144
        AND sal.current_qty = 144
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 240, 240, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-BEV-002'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 240
        AND sal.current_qty = 240
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 96, 96, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-BEV-003'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 96
        AND sal.current_qty = 96
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 120, 120, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-BEV-004'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 120
        AND sal.current_qty = 120
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 200, 200, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-SNK-001'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 200
        AND sal.current_qty = 200
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 180, 180, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-SNK-002'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 180
        AND sal.current_qty = 180
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 160, 160, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-CAN-001'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 160
        AND sal.current_qty = 160
  );

INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id, timestamp)
SELECT p.product_id, 80, 80, 'stock_in', 1, NOW()
FROM products p
WHERE p.sku = 'SMP-BRD-001'
  AND NOT EXISTS (
      SELECT 1
      FROM stock_audit_log sal
      WHERE sal.product_id = p.product_id
        AND sal.action = 'stock_in'
        AND sal.change_qty = 80
        AND sal.current_qty = 80
  );
