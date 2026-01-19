-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 17, 2026 at 07:57 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `inventory_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`, `description`, `status`, `created_at`) VALUES
(1, 'Rice & Grains', 'All types of rice and pasta products', 'active', '2026-01-17 06:31:04'),
(2, 'Canned Goods', 'Canned food items', 'active', '2026-01-17 06:31:04'),
(3, 'Snacks', 'Chips, biscuits, and other snacks', 'active', '2026-01-17 06:31:04'),
(4, 'Beverages', 'Drinks and bottled water', 'active', '2026-01-17 06:31:04'),
(5, 'Frozen & Meat', 'Frozen chicken, hotdogs, and meats', 'active', '2026-01-17 06:31:04');

-- --------------------------------------------------------

--
-- Table structure for table `pos_config`
--

CREATE TABLE `pos_config` (
  `config_id` int(11) NOT NULL,
  `store_name` varchar(150) NOT NULL,
  `store_address` text DEFAULT NULL,
  `store_phone` varchar(50) DEFAULT NULL,
  `store_email` varchar(150) DEFAULT NULL,
  `opening_hours` time DEFAULT NULL,
  `closing_hours` time DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 5.00,
  `currency` varchar(10) DEFAULT 'PHP',
  `logo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pos_config`
--

INSERT INTO `pos_config` (`config_id`, `store_name`, `store_address`, `store_phone`, `store_email`, `opening_hours`, `closing_hours`, `tax_rate`, `currency`, `logo`) VALUES
(1, 'My Grocery Store', '123 Main St., Manila', '09171234567', 'store@example.com', NULL, NULL, 12.00, 'PHP', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `vatable` tinyint(1) NOT NULL DEFAULT 1,
  `on_sale` tinyint(1) NOT NULL DEFAULT 0,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `photo` varchar(255) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT 10,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `product_name`, `category_id`, `supplier_id`, `sku`, `price`, `vatable`, `on_sale`, `sale_price`, `quantity`, `photo`, `reorder_level`, `created_at`, `status`) VALUES
(1, 'Jasmine Rice 5kg', 1, 1, 'RICE-001', 325.00, 1, 0, NULL, 23, NULL, 10, '2026-01-17 06:32:55', 'active'),
(2, 'Spaghetti Pasta 1kg', 1, 2, 'GRAIN-002', 95.00, 1, 0, NULL, 40, NULL, 10, '2026-01-17 06:32:55', 'active'),
(3, 'Instant Noodles Pack', 1, 3, 'GRAIN-003', 65.00, 1, 1, 50.00, 58, NULL, 10, '2026-01-17 06:32:55', 'active'),
(4, 'Canned Sardines', 2, 1, 'CANNED-001', 24.00, 1, 0, NULL, 65, NULL, 10, '2026-01-17 06:32:55', 'active'),
(5, 'Corned Beef 150g', 2, 2, 'CANNED-002', 52.00, 1, 1, 40.00, 64, NULL, 10, '2026-01-17 06:32:55', 'active'),
(6, 'Potato Chips', 3, 2, 'SNACK-001', 55.00, 1, 0, NULL, 100, NULL, 10, '2026-01-17 06:32:55', 'active'),
(7, 'Chocolate Biscuits', 3, 3, 'SNACK-002', 38.00, 1, 0, NULL, 45, NULL, 10, '2026-01-17 06:32:55', 'active'),
(8, 'Bottled Water 1L', 4, 1, 'BEV-001', 20.00, 0, 0, NULL, 150, NULL, 10, '2026-01-17 06:32:55', 'active'),
(9, 'Softdrink 1.5L', 4, 2, 'BEV-002', 72.00, 1, 0, NULL, 60, NULL, 10, '2026-01-17 06:32:55', 'active'),
(10, 'Frozen Chicken 1kg', 5, 3, 'MEAT-001', 265.00, 1, 0, NULL, 21, NULL, 10, '2026-01-17 06:32:55', 'active'),
(11, 'Hotdog Pack', 5, 2, 'MEAT-002', 145.00, 1, 0, NULL, 33, NULL, 10, '2026-01-17 06:32:55', 'active');

--
-- Triggers `products`
--
DELIMITER $$
CREATE TRIGGER `trg_products_after_update` AFTER UPDATE ON `products` FOR EACH ROW BEGIN
    IF OLD.quantity <> NEW.quantity THEN
        INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
        VALUES (
            NEW.product_id,
            NEW.quantity - OLD.quantity,
            NEW.quantity,
            'manual_adjust',
            NULL
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','card','gcash','other') DEFAULT 'cash',
  `sale_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `total_amount`, `tax`, `discount`, `payment_method`, `sale_date`, `user_id`) VALUES
(3, 500.00, 60.00, 0.00, 'cash', '2026-01-17 06:40:27', 3),
(4, 350.00, 42.00, 0.00, 'card', '2026-01-17 06:40:27', 3),
(5, 500.00, 60.00, 0.00, 'cash', '2026-01-17 06:40:35', 3),
(6, 350.00, 42.00, 0.00, 'card', '2026-01-17 06:40:35', 3);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `sale_items`
--
DELIMITER $$
CREATE TRIGGER `trg_sale_items_after_delete` AFTER DELETE ON `sale_items` FOR EACH ROW BEGIN
    -- restore product quantity
    UPDATE products
    SET quantity = quantity + OLD.quantity
    WHERE product_id = OLD.product_id;

    -- log stock audit
    INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
    VALUES (
        OLD.product_id,
        OLD.quantity,
        (SELECT quantity FROM products WHERE product_id = OLD.product_id),
        'sale_deleted',
        (SELECT user_id FROM sales WHERE sale_id = OLD.sale_id)
    );

    -- update sales total
    UPDATE sales s
    SET s.total_amount = (
        SELECT IFNULL(SUM(
            si.quantity * 
            CASE 
                WHEN p.on_sale = 1 THEN p.sale_price 
                ELSE p.price 
            END
        ), 0)
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = OLD.sale_id
    )
    WHERE s.sale_id = OLD.sale_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sale_items_after_insert` AFTER INSERT ON `sale_items` FOR EACH ROW BEGIN
    -- update product quantity
    UPDATE products
    SET quantity = quantity - NEW.quantity
    WHERE product_id = NEW.product_id;

    -- log stock audit
    INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
    VALUES (
        NEW.product_id,
        -NEW.quantity,
        (SELECT quantity FROM products WHERE product_id = NEW.product_id),
        'sale',
        (SELECT user_id FROM sales WHERE sale_id = NEW.sale_id)
    );

    -- update sales total including VAT if applicable
    UPDATE sales s
    SET s.total_amount = (
        SELECT IFNULL(SUM(
            si.quantity * 
            CASE 
                WHEN p.on_sale = 1 THEN p.sale_price 
                ELSE p.price 
            END
        ), 0)
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = NEW.sale_id
    )
    WHERE s.sale_id = NEW.sale_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_audit_log`
--

CREATE TABLE `stock_audit_log` (
  `log_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `change_qty` int(11) NOT NULL,
  `current_qty` int(11) NOT NULL,
  `action` enum('sale','stock_in','stock_out','manual_adjust') NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_in`
--

CREATE TABLE `stock_in` (
  `stockin_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `stockin_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_in`
--

INSERT INTO `stock_in` (`stockin_id`, `product_id`, `quantity`, `stockin_date`, `user_id`) VALUES
(1, 1, 10, '2026-01-17 06:40:43', 2),
(2, 2, 20, '2026-01-17 06:40:43', 2),
(3, 3, 30, '2026-01-17 06:40:43', 2),
(4, 4, 15, '2026-01-17 06:40:43', 2),
(5, 5, 25, '2026-01-17 06:40:43', 2),
(6, 6, 40, '2026-01-17 06:40:43', 2),
(7, 7, 25, '2026-01-17 06:40:43', 2),
(8, 8, 50, '2026-01-17 06:40:43', 2),
(9, 9, 30, '2026-01-17 06:40:43', 2),
(10, 10, 10, '2026-01-17 06:40:43', 2),
(11, 11, 15, '2026-01-17 06:40:43', 2);

--
-- Triggers `stock_in`
--
DELIMITER $$
CREATE TRIGGER `trg_stock_in_after_insert` AFTER INSERT ON `stock_in` FOR EACH ROW BEGIN
    -- update product quantity
    UPDATE products
    SET quantity = quantity + NEW.quantity
    WHERE product_id = NEW.product_id;

    -- log stock audit
    INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
    VALUES (
        NEW.product_id,
        NEW.quantity,
        (SELECT quantity FROM products WHERE product_id = NEW.product_id),
        'stock_in',
        NEW.user_id
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_out`
--

CREATE TABLE `stock_out` (
  `stockout_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `stockout_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_out`
--

INSERT INTO `stock_out` (`stockout_id`, `product_id`, `quantity`, `reason`, `stockout_date`, `user_id`) VALUES
(1, 1, 2, 'Sale', '2026-01-17 06:40:51', 2),
(2, 3, 2, 'Sale', '2026-01-17 06:40:51', 2),
(3, 5, 1, 'Sale', '2026-01-17 06:40:51', 2),
(4, 7, 5, 'Sale', '2026-01-17 06:40:51', 2),
(5, 10, 1, 'Sale', '2026-01-17 06:40:51', 2);

--
-- Triggers `stock_out`
--
DELIMITER $$
CREATE TRIGGER `trg_stock_out_after_insert` AFTER INSERT ON `stock_out` FOR EACH ROW BEGIN
    -- update product quantity
    UPDATE products
    SET quantity = quantity - NEW.quantity
    WHERE product_id = NEW.product_id;

    -- log stock audit
    INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
    VALUES (
        NEW.product_id,
        -NEW.quantity,
        (SELECT quantity FROM products WHERE product_id = NEW.product_id),
        'stock_out',
        NEW.user_id
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `phone`, `email`, `address`) VALUES
(1, 'Golden Harvest', 'Anna Cruz', '09171234567', 'anna@goldharvest.com', '123 Harvest St.'),
(2, 'Healthy Foods Co.', 'Mark Reyes', '09179876543', 'mark@healthyfoods.com', '456 Green Ave.'),
(3, 'Quick Snacks Inc.', 'Liza Santos', '09175678901', 'liza@quicksnacks.com', '789 Snack Rd.');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff','cashier') DEFAULT 'staff',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active',
  `deactivated_at` datetime DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `email`, `username`, `password`, `role`, `created_at`, `status`, `deactivated_at`, `photo`) VALUES
(1, 'Admin', 'User', 'admin@gmail.com', 'admin', '$2y$10$5jjpSkUO5r30wbJNLUG0Iu8h6DHtyVmr1oyB2CrkTlLabnU1/48ci', 'admin', '2026-01-17 06:31:48', 'active', NULL, '/inventory_system/uploads/staff/admin_user/photo_696b2cd3e92ba2.70910251.jpg'),
(2, 'John', 'Doe', 'john@gmail.com', 'john_doe', '$2y$10$H12mlMcZQn342oy31HxGnuhX.NP4MDg7ltmeUPcHikLgD5DrremnW', 'staff', '2026-01-17 06:32:29', 'active', NULL, '/inventory_system/uploads/staff/john_doe/photo_696b2cfd7bc206.53522272.jpg'),
(3, 'Jane', 'Smith', 'jane@gmail.com', 'jane_smith', '$2y$10$RVSzG66JBxkSryM6gvBxm./Jtr0yWLl8YunQb6aOgg6gu56.SNBGO', 'cashier', '2026-01-17 06:39:52', 'active', NULL, '/inventory_system/uploads/staff/jane_smith/photo_696b2eb8461dc7.37429784.jpg');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `pos_config`
--
ALTER TABLE `pos_config`
  ADD PRIMARY KEY (`config_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sale_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`sale_item_id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `stock_audit_log`
--
ALTER TABLE `stock_audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `stock_in`
--
ALTER TABLE `stock_in`
  ADD PRIMARY KEY (`stockin_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `stock_out`
--
ALTER TABLE `stock_out`
  ADD PRIMARY KEY (`stockout_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pos_config`
--
ALTER TABLE `pos_config`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_audit_log`
--
ALTER TABLE `stock_audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_in`
--
ALTER TABLE `stock_in`
  MODIFY `stockin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `stock_out`
--
ALTER TABLE `stock_out`
  MODIFY `stockout_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`),
  ADD CONSTRAINT `products_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`);

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Constraints for table `stock_audit_log`
--
ALTER TABLE `stock_audit_log`
  ADD CONSTRAINT `stock_audit_log_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_in`
--
ALTER TABLE `stock_in`
  ADD CONSTRAINT `stock_in_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `stock_in_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `stock_out`
--
ALTER TABLE `stock_out`
  ADD CONSTRAINT `stock_out_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
