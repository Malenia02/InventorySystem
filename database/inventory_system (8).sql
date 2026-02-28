-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 23, 2026 at 05:53 PM
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
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `created_at`) VALUES
(1, 1, 'login_success', 'User logged in: admin', '::1', '2026-01-31 01:27:20'),
(2, 1, 'logout', 'User logged out: admin', '::1', '2026-01-31 01:27:32'),
(3, 1, 'login_success', 'User logged in: admin', '::1', '2026-01-31 01:28:11'),
(4, 1, 'logout', 'User logged out: Role: admin', '::1', '2026-01-31 01:28:29'),
(5, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-01-31 01:58:32'),
(6, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-01-31 05:09:47'),
(7, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-01-31 05:16:35'),
(8, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-01-31 05:49:22'),
(9, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-01-31 06:54:58'),
(10, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-01-31 13:05:40'),
(11, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-03 00:51:31'),
(12, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-03 01:38:44'),
(13, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-03 01:40:47'),
(14, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-20 22:38:44'),
(15, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-20 23:05:16'),
(16, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-20 23:20:52'),
(17, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-20 23:47:48'),
(18, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-20 23:55:25'),
(19, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 04:49:13'),
(20, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 05:04:53'),
(21, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 05:48:54'),
(22, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 06:37:18'),
(23, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 08:46:20'),
(24, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 08:47:03'),
(25, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 11:16:19'),
(29, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 12:59:31'),
(30, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 13:00:38'),
(31, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-22 13:18:02'),
(32, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-23 16:29:49');

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
(1, 'Beverages', 'All kinds of drinks including soft drinks and juices', 'active', '2026-01-17 08:04:52'),
(2, 'Snacks', 'Chips, nuts, and other quick bites', 'active', '2026-01-17 08:04:52'),
(3, 'Dairy', 'Milk, cheese, yogurt, and other dairy products', 'inactive', '2026-01-17 08:04:52'),
(4, 'Vehicle', 'Bread, cakes, pastries, and baked goods', 'active', '2026-01-17 08:04:52'),
(5, 'Household', 'Cleaning supplies, detergents, and daily household items', 'active', '2026-01-17 08:04:52'),
(23, 'test23', NULL, 'inactive', '2026-01-20 10:08:50'),
(24, 'test1', NULL, 'inactive', '2026-01-27 08:42:50'),
(25, 'Pure Gold', NULL, 'active', '2026-02-03 01:48:28'),
(27, 'hays', NULL, 'inactive', '2026-02-03 01:48:41'),
(29, 'hose', NULL, 'inactive', '2026-02-03 01:48:59'),
(31, 'Bakery', NULL, 'active', '2026-02-20 23:06:35'),
(32, 'test2', NULL, 'inactive', '2026-02-20 23:12:35'),
(33, 'rqwrqwrqw', NULL, 'active', '2026-02-20 23:54:44'),
(34, 'rqwrqfsfsa', NULL, 'inactive', '2026-02-20 23:54:58'),
(35, 'tesfsafsafasga', NULL, 'inactive', '2026-02-20 23:58:56'),
(36, 'tesd', NULL, 'active', '2026-02-22 05:06:02'),
(37, 'njjnknkj', NULL, 'active', '2026-02-22 23:44:44');

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
(1, 'SuperMart', '100 Main Street, Manila', '09170001111', 'contact@supermart.ph', '08:00:00', '22:00:00', 5.00, 'PHP', '/inventory_system/uploads/store_logo/supermart_logo.png');

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
(1, 'Coca Cola 500ml', 1, 1, 'BEV001', 25.00, 1, 1, 20.00, 98, '/inventory_system/assets/uploads/products/cola.jpeg', 20, '2026-01-17 08:04:52', 'active'),
(2, 'Pepsi 500ml', 1, 1, 'BEV002', 24.00, 1, 0, NULL, 80, '/inventory_system/assets/uploads/products/pepsi.jpg', 20, '2026-01-17 08:04:52', 'active'),
(3, 'Potato Chips 50g', 2, 4, 'SNK001', 16.00, 1, 0, NULL, 127, '/inventory_system/assets/uploads/products/chips.jpeg', 30, '2026-01-17 08:04:52', 'active'),
(4, 'Cheddar Cheese 200g', 3, 3, 'DRY001', 120.00, 1, 1, 100.00, 43, '/inventory_system/assets/uploads/products/cheddar_cheese_200g/photo_696f509d56d6a6.60469721.jpg', 10, '2026-01-17 08:04:52', 'active'),
(5, 'Whole Milk 1L', 3, 3, 'DRY002', 85.00, 1, 0, NULL, 75, '/inventory_system/assets/uploads/products/whole_milk_1l/photo_696f5093988253.99106180.jpg', 15, '2026-01-17 08:04:52', 'active'),
(6, 'Banana Bread', 4, 2, 'BAK001', 55.00, 1, 0, NULL, 39, '/inventory_system/assets/uploads/products/banana_bread/photo_696f507abbe979.50814976.jpeg', 10, '2026-01-17 08:04:52', 'active'),
(7, 'Dishwashing Liquid 500ml', 5, 5, 'HLD001', 65.00, 1, 0, NULL, 50, '/inventory_system/assets/uploads/products/dishwashing_liquid_500ml/photo_696f50703a4720.19204913.jpeg', 10, '2026-01-17 08:04:52', 'active'),
(8, 'SKULL', 3, NULL, NULL, 24.00, 1, 0, NULL, 0, '/inventory_system/assets/uploads/products/skull/photo_696dea1d1e4f96.87406781.jpg', 10, '2026-01-19 08:23:57', 'active'),
(9, 'test2332', 4, 2, NULL, 200.00, 1, 0, 20.00, 0, '/inventory_system/assets/uploads/products/test/photo_696f4edd44b769.15320118.jpg', 5, '2026-01-20 09:46:05', 'active'),
(10, 'test3', 4, 2, NULL, 23.00, 0, 0, 0.00, 0, '/inventory_system/assets/uploads/products/test3/photo_697654ec3f0df7.03228495.jpeg', 5, '2026-01-22 05:15:14', 'active'),
(11, 'test', 4, 2, 'test32', 20.00, 1, 0, 0.00, 300, '/inventory_system/assets/uploads/products/test4/photo_6971b2d0786988.64058162.jpeg', 5, '2026-01-22 05:17:04', 'active'),
(12, 'test02', 23, 2, 'test5', 23.00, 1, 0, NULL, 23, '/inventory_system/assets/uploads/products/test55/photo_697d8c798b2106.00560647.jpeg', 5, '2026-01-27 13:41:26', 'active'),
(13, 'tesdasdsa2', 4, 6, 'NEW', 0.00, 0, 0, 0.00, 0, '/inventory_system/assets/uploads/products/tesdasdsa/photo_697d8425b44c96.07178638.png', 10, '2026-01-31 04:25:09', 'active'),
(14, 'Fish', 24, 2, '', 232.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/fish/photo_699a8ea71fb3e7.93750407.jpg', 10, '2026-02-22 05:05:43', 'active'),
(16, 'Forest', 31, 2, 'Forest', 23.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/eqwrwqrwq/photo_699a91c03b1f85.24709700.jpg', 10, '2026-02-22 05:18:56', 'active'),
(18, 'Book worm', 25, 6, 'TeST2', 232.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/book_worm/photo_699a9c82ad1711.89026518.jpeg', 10, '2026-02-22 06:04:50', 'active'),
(19, 'dsa', 31, 6, 'dsadg', 10.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/dsa/photo_699aa1c25fc001.58379579.jpeg', 10, '2026-02-22 06:27:14', 'active'),
(20, 'adsdag', 31, 2, 'rqwerqw', 20.00, 1, 0, NULL, 0, '/inventory_system/assets/uploads/products/adsdag/photo_699aa59e6eb439.50350313.jpeg', 10, '2026-02-22 06:43:42', 'active'),
(21, 'rqwrqwrwqfsagas', 31, 2, 'eqwewq', 20.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/rqwrqwrwqfsagas/photo_699aa5b6dd2ed4.58565049.jpeg', 10, '2026-02-22 06:44:06', 'active'),
(22, 'scammer', 32, 1, 'scam02', 22.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/scam/photo_699aebc52573f3.18210320.jpg', 10, '2026-02-22 11:43:01', 'active'),
(23, 'TESTER', 31, 2, 'TESTER', 25.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/tester/photo_699b05d7b57682.20418692.jpeg', 10, '2026-02-22 13:34:15', 'active'),
(24, 'Canibal', 31, 2, 'Sgkdg', 20.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/canibal/photo_699b1051cab5f3.50717223.jpeg', 10, '2026-02-22 14:18:57', 'active'),
(25, 'ace', 31, 8, NULL, 20.00, 0, 0, NULL, 0, 'testace', 10, '2026-02-23 16:46:47', 'active');

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
(1, 85.00, 8.25, 0.00, 'cash', '2026-01-17 08:04:52', 3),
(2, 255.00, 11.00, 10.00, 'card', '2026-01-17 08:04:52', 3);

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
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `unit_price`, `quantity`) VALUES
(1, 1, 1, 20.00, 2),
(2, 1, 3, 15.00, 3),
(3, 2, 4, 100.00, 2),
(4, 2, 6, 55.00, 1);

--
-- Triggers `sale_items`
--
DELIMITER $$
CREATE TRIGGER `trg_sale_items_after_delete` AFTER DELETE ON `sale_items` FOR EACH ROW BEGIN
    -- Restore product quantity
    UPDATE products
    SET quantity = quantity + OLD.quantity
    WHERE product_id = OLD.product_id;

    -- Log the change
    INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
    VALUES (
        OLD.product_id,
        OLD.quantity,
        (SELECT quantity FROM products WHERE product_id = OLD.product_id),
        'sale_deleted',
        (SELECT user_id FROM sales WHERE sale_id = OLD.sale_id)
    );

    -- Update sales total
    UPDATE sales s
    SET s.total_amount = (
        SELECT IFNULL(SUM(
            si.quantity * 
            CASE 
                WHEN p.on_sale = 1 THEN p.sale_price
                ELSE p.price
            END
        ),0)
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
    -- Decrease product quantity
    UPDATE products
    SET quantity = quantity - NEW.quantity
    WHERE product_id = NEW.product_id;

    -- Log the change
    INSERT INTO stock_audit_log (product_id, change_qty, current_qty, action, user_id)
    VALUES (
        NEW.product_id,
        -NEW.quantity,
        (SELECT quantity FROM products WHERE product_id = NEW.product_id),
        'sale',
        (SELECT user_id FROM sales WHERE sale_id = NEW.sale_id)
    );

    -- Update sales total amount
    UPDATE sales s
    SET s.total_amount = (
        SELECT IFNULL(SUM(
            si.quantity * 
            CASE 
                WHEN p.on_sale = 1 THEN p.sale_price
                ELSE p.price
            END
        ),0)
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

--
-- Dumping data for table `stock_audit_log`
--

INSERT INTO `stock_audit_log` (`log_id`, `product_id`, `change_qty`, `current_qty`, `action`, `user_id`, `timestamp`) VALUES
(1, 3, -20, 130, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(2, 3, -20, 130, 'stock_out', 2, '2026-01-17 16:04:52'),
(3, 4, -5, 45, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(4, 4, -5, 45, 'stock_out', 2, '2026-01-17 16:04:52'),
(5, 7, -10, 50, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(6, 7, -10, 50, 'stock_out', 2, '2026-01-17 16:04:52'),
(7, 1, -2, 98, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(8, 1, -2, 98, 'sale', 3, '2026-01-17 16:04:52'),
(9, 3, -3, 127, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(10, 3, -3, 127, 'sale', 3, '2026-01-17 16:04:52'),
(11, 4, -2, 43, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(12, 4, -2, 43, 'sale', 3, '2026-01-17 16:04:52'),
(13, 6, -1, 39, 'manual_adjust', NULL, '2026-01-17 16:04:52'),
(14, 6, -1, 39, 'sale', 3, '2026-01-17 16:04:52'),
(15, 11, 300, 300, 'manual_adjust', NULL, '2026-01-22 13:17:04'),
(16, 11, 300, 300, 'stock_in', 1, '2026-01-22 13:17:04'),
(17, 12, 23, 23, 'manual_adjust', NULL, '2026-01-27 21:41:26'),
(18, 12, 23, 23, 'stock_in', 1, '2026-01-27 21:41:26');

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
(1, 1, 100, '2026-01-17 08:04:52', 2),
(2, 2, 80, '2026-01-17 08:04:52', 2),
(3, 3, 150, '2026-01-17 08:04:52', 2),
(4, 4, 50, '2026-01-17 08:04:52', 2),
(5, 5, 75, '2026-01-17 08:04:52', 2),
(6, 6, 40, '2026-01-17 08:04:52', 2),
(7, 7, 60, '2026-01-17 08:04:52', 2),
(8, 11, 300, '2026-01-22 05:17:04', 1),
(9, 12, 23, '2026-01-27 13:41:26', 1);

--
-- Triggers `stock_in`
--
DELIMITER $$
CREATE TRIGGER `trg_stock_in_after_insert` AFTER INSERT ON `stock_in` FOR EACH ROW BEGIN
    UPDATE products
    SET quantity = quantity + NEW.quantity
    WHERE product_id = NEW.product_id;

    INSERT INTO stock_audit_log
        (product_id, change_qty, current_qty, action, user_id)
    VALUES
        (
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
(1, 3, 20, 'Damaged package', '2026-01-17 08:04:52', 2),
(2, 4, 5, 'Expired', '2026-01-17 08:04:52', 2),
(3, 7, 10, 'Customer return', '2026-01-17 08:04:52', 2);

--
-- Triggers `stock_out`
--
DELIMITER $$
CREATE TRIGGER `trg_stock_out_after_insert` AFTER INSERT ON `stock_out` FOR EACH ROW BEGIN
    -- Decrease product quantity
    UPDATE products
    SET quantity = quantity - NEW.quantity
    WHERE product_id = NEW.product_id;

    -- Log the change
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
DELIMITER $$
CREATE TRIGGER `trg_stock_out_before_insert` BEFORE INSERT ON `stock_out` FOR EACH ROW BEGIN
    DECLARE current_stock INT;

    -- Get current product quantity
    SELECT quantity
    INTO current_stock
    FROM products
    WHERE product_id = NEW.product_id;

    -- Prevent stock going negative
    IF current_stock < NEW.quantity THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Stock-out failed: insufficient stock available';
    END IF;
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
(1, 'Fresh Foods Inc.', 'Michael Brown', '09171234567', 'michael@freshfoods.com', '123 Market Street, Manila'),
(2, 'Bakery Supplies Co.', 'Sara Lee', '09179876543', 'sara@bakerysupplies.com', '45 Baker Lane, Quezon City'),
(3, 'Dairy Delights', 'Anna Cruz', '09172345678', 'anna@dairydelights.ph', '77 Dairy Road, Makati'),
(4, 'Quick Snacks Ltd.', 'John Santos', '09173456789', 'john@quicksnacks.ph', '88 Snack Avenue, Pasig'),
(5, 'Household Hub', 'Maria Lopez', '09174567890', 'maria@householdhub.ph', '22 Clean Street, Manila'),
(6, 'test', 'test', '09', 'test@gmai.com', 'test'),
(7, 'test4', 'test4', 'test4', 'test@gmai2.com', 'test35'),
(8, 'teewtq', 'tqetqetq', '0956656', 'test@gmailc.om', 'wewqrwe'),
(9, 'test654', 'rqweqweqw', 'qweqwewqeqw', 'eqwewqe@gmail.com', 'fafasfsa'),
(10, 'tesrfqwr', 'rqwreqwe', '3625165', 'rqwerqw@gmail.com', 'qieqwjiewq');

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
(1, 'Admin', 'User2', 'admin@gmail.com', 'admin', '$2y$10$5jjpSkUO5r30wbJNLUG0Iu8h6DHtyVmr1oyB2CrkTlLabnU1/48ci', 'admin', '2026-01-17 06:31:48', 'active', '2026-01-19 16:32:18', '/inventory_system/uploads/staff/admin_user/photo_696b2cd3e92ba2.70910251.jpg'),
(2, 'John', 'Doe', 'john@gmail.com', 'john_doe2', '$2y$10$H12mlMcZQn342oy31HxGnuhX.NP4MDg7ltmeUPcHikLgD5DrremnW', 'staff', '2026-01-17 06:32:29', 'active', NULL, '/inventory_system/uploads/staff/john_doe/photo_696dc86434c0d6.58543438.jpg'),
(3, 'Jane2', 'Smith', 'jane@gmail.com', 'jane2', '$2y$10$tdJ3KYjXlKxCyuDp7zylTOM5gWeLDsdXw4kjnJg2wyEj951XiL0iG', 'cashier', '2026-01-17 06:39:52', 'inactive', '2026-01-27 17:02:31', '/inventory_system/uploads/staff/jane_smith/photo_696dc5da98ce32.57671707.jpg'),
(4, 'test', 'test', 'test@gmail.com', 'test', '$2y$10$jOcrboPrdH8Hi0V1qqwwbeiNuKEogLBMFfnBVCQgEM3HCz2.smT62', 'staff', '2026-01-31 01:02:06', 'active', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

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
  ADD KEY `user_id` (`user_id`),
  ADD KEY `stock_out_ibfk_2` (`product_id`);

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
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `pos_config`
--
ALTER TABLE `pos_config`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `stock_audit_log`
--
ALTER TABLE `stock_audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `stock_in`
--
ALTER TABLE `stock_in`
  MODIFY `stockin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `stock_out`
--
ALTER TABLE `stock_out`
  MODIFY `stockout_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

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
  ADD CONSTRAINT `stock_out_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `stock_out_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
