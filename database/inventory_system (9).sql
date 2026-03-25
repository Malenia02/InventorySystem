-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 23, 2026 at 03:41 AM
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
(32, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-23 16:29:49'),
(33, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-25 00:55:56'),
(34, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-25 01:19:47'),
(35, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-25 01:58:24'),
(36, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-26 02:14:37'),
(37, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-26 03:22:09'),
(38, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-26 03:41:07'),
(39, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-26 04:14:49'),
(40, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-28 02:01:01'),
(41, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-28 02:09:34'),
(42, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-28 02:15:51'),
(43, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-28 02:28:59'),
(44, 1, 'logout', 'User logged out: Role: admin', '::1', '2026-02-28 03:52:35'),
(45, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-02-28 03:52:39'),
(46, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 01:51:49'),
(47, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 02:14:43'),
(48, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 02:18:28'),
(49, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 02:28:24'),
(50, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 02:52:14'),
(51, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 03:01:36'),
(52, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 03:42:06'),
(53, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 03:47:02'),
(54, 1, 'login_failed', 'Wrong password for username: admin', '::1', '2026-03-01 03:55:22'),
(55, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 03:55:26'),
(56, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 04:42:38'),
(57, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 04:56:42'),
(58, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 05:08:03'),
(59, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 05:40:29'),
(60, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 05:50:33'),
(61, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 05:59:38'),
(62, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 06:23:04'),
(63, 1, 'logout', 'User logged out: Role: admin', '::1', '2026-03-01 08:29:44'),
(64, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 08:29:48'),
(65, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 08:29:56'),
(66, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-01 08:49:39'),
(67, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 01:44:48'),
(68, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 02:20:48'),
(69, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 02:32:45'),
(70, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 02:36:28'),
(71, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 02:37:33'),
(72, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 02:57:51'),
(73, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 03:05:29'),
(74, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 03:11:38'),
(75, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 03:12:13'),
(76, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 03:53:28'),
(77, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 04:11:01'),
(78, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-03 04:15:27'),
(79, 1, 'login_failed', 'Wrong password for username: admin', '::1', '2026-03-09 11:35:21'),
(80, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-09 11:35:25'),
(81, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-20 03:01:26'),
(82, NULL, 'login_failed', 'Invalid username: louie', '::1', '2026-03-23 00:03:44'),
(83, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-23 00:03:47'),
(84, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-23 00:15:29'),
(85, 1, 'login_failed', 'Wrong password for username: admin', '::1', '2026-03-23 00:21:41'),
(86, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-23 00:21:45'),
(87, 1, 'login_success', 'User logged in: Role: admin', '::1', '2026-03-23 00:22:30'),
(88, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 01:22:07'),
(89, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 01:22:16'),
(90, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 01:25:40'),
(91, NULL, 'login_failed', 'Empty username or password', '::1', '2026-03-23 01:52:13'),
(92, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 01:53:07'),
(93, NULL, 'login_failed', 'Empty username or password', '::1', '2026-03-23 01:53:12'),
(94, NULL, 'login_failed', 'Username not found: dasdasdsa', '::1', '2026-03-23 01:56:15'),
(95, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 01:56:29'),
(96, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 02:10:25'),
(97, 1, 'login_success', 'User logged in: admin', '::1', '2026-03-23 02:10:36');

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
(31, 'Bakeryr', NULL, 'inactive', '2026-02-20 23:06:35'),
(32, 'test2', NULL, 'inactive', '2026-02-20 23:12:35'),
(33, 'rqwrqwrqw', NULL, 'active', '2026-02-20 23:54:44'),
(34, 'rqwrqfsfsa', NULL, 'inactive', '2026-02-20 23:54:58'),
(35, 'tesfsafsafasga', NULL, 'inactive', '2026-02-20 23:58:56'),
(36, 'tesd', NULL, 'active', '2026-02-22 05:06:02'),
(37, 'njjnknkj', NULL, 'active', '2026-02-22 23:44:44'),
(38, 'cat', NULL, 'active', '2026-02-28 04:04:36'),
(39, 'basfd', NULL, 'active', '2026-03-01 05:35:19');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `movement_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `movement_type` enum('IN','OUT','ADJUST') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `price` float(10,2) NOT NULL,
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
(11, 'test', 4, 2, 'test32', 20.00, 1, 0, 0.00, 300, '/inventory_system/assets/uploads/products/test/photo_696f4edd44b769.15320118.jpg', 5, '2026-01-22 05:17:04', 'active'),
(12, 'test02', 23, 2, 'test5', 23.00, 1, 0, NULL, 23, '/inventory_system/assets/uploads/products/test55/photo_697d8c798b2106.00560647.jpeg', 5, '2026-01-27 13:41:26', 'active'),
(13, 'tesdasdsa2', 4, 6, 'NEW', 0.00, 0, 0, 0.00, 0, '/inventory_system/assets/uploads/products/tesdasdsa/photo_697d8425b44c96.07178638.png', 10, '2026-01-31 04:25:09', 'active'),
(14, 'Fish', 24, 2, '', 232.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/fish/photo_699a8ea71fb3e7.93750407.jpg', 10, '2026-02-22 05:05:43', 'active'),
(16, 'Forest', 31, 2, 'Forest', 23.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/eqwrwqrwq/photo_699a91c03b1f85.24709700.jpg', 10, '2026-02-22 05:18:56', 'active'),
(18, 'Book worm', 25, 6, 'TeST2', 232.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/book_worm/photo_699a9c82ad1711.89026518.jpeg', 10, '2026-02-22 06:04:50', 'active'),
(19, 'dsa', 31, 6, 'dsadg', 10.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/dsa/photo_699aa1c25fc001.58379579.jpeg', 10, '2026-02-22 06:27:14', 'active'),
(20, 'adsdag', 31, 2, 'rqwerqw', 20.00, 1, 0, NULL, 30, '/inventory_system/assets/uploads/products/adsdag/photo_699aa59e6eb439.50350313.jpeg', 10, '2026-02-22 06:43:42', 'active'),
(21, 'rqwrqwrwqfsagas', 31, 2, 'eqwewq', 20.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/rqwrqwrwqfsagas/photo_699aa5b6dd2ed4.58565049.jpeg', 10, '2026-02-22 06:44:06', 'active'),
(22, 'scammer', 32, 1, 'scam02', 22.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/scam/photo_699aebc52573f3.18210320.jpg', 10, '2026-02-22 11:43:01', 'active'),
(23, 'TESTER', 31, 2, 'TESTER', 25.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/tester/photo_699b05d7b57682.20418692.jpeg', 10, '2026-02-22 13:34:15', 'active'),
(24, 'Canibal', 31, 2, 'Sgkdg', 20.00, 0, 0, NULL, 0, '/inventory_system/assets/uploads/products/canibal/photo_699b1051cab5f3.50717223.jpeg', 10, '2026-02-22 14:18:57', 'active'),
(25, 'ace', 2, 3, 'stard2', 20.00, 0, 0, NULL, 111, '/inventory_system/assets/uploads/products/ace/photo_699c8477b02054.25402292.jpeg', 10, '2026-02-23 16:46:47', 'active'),
(26, 'fasfasfasfsafsafsa', 31, 2, 'djgadjkas', 20.00, 0, 0, NULL, 20, '/inventory_system/assets/uploads/products/fasfasfasfsafsafsa/photo_699e584414b954.06721461.jpg', 10, '2026-02-25 02:02:44', 'active'),
(27, 'house', 31, 2, 'jpuse', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/house/photo_69a256fcd95049.25278607.png', 10, '2026-02-28 02:46:20', 'active'),
(28, 'EXO', 31, 2, 'EXO2002', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/exo/photo_69a25eba1cfa28.62022866.jpg', 10, '2026-02-28 03:19:22', 'active'),
(29, 'as', 31, 2, 'dsadsa', 2020.00, 0, 0, NULL, 6, '/inventory_system/assets/uploads/products/as/photo_69a25eeb8a2a07.23560052.jpg', 10, '2026-02-28 03:20:11', 'active'),
(31, 'zoom', 31, 2, 'zoom202', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/zoom/photo_69a2607ab68fd9.75495652.jpg', 10, '2026-02-28 03:26:50', 'active'),
(32, 'spuyy', 31, 2, 'asdasdasdas', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/spuyy/photo_69a2609e2d2a81.47720047.jpeg', 10, '2026-02-28 03:27:26', 'active'),
(33, 'ASDFQWFQ', 31, 2, 'EQWEQW', 20.00, 0, 0, NULL, 17, '/inventory_system/assets/uploads/products/asdfqwfq/photo_69a26186422b02.08738533.jpeg', 10, '2026-02-28 03:31:18', 'active'),
(34, 'ASS', 31, 2, 'ASS02', 20.00, 1, 0, NULL, 10, NULL, 10, '2026-02-28 03:36:17', 'active'),
(35, 'dasdfsadfsadasdsa', 31, 2, 'dasdsadsadsadsa', 1.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/dasdfsadfsadasdsa/photo_69a2636131f654.73480670.jpeg', 10, '2026-02-28 03:39:13', 'active'),
(36, 'ASTEST', 31, 2, 'ASTEST', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/astest/photo_69a266a0157e62.57220858.jpeg', 10, '2026-02-28 03:53:04', 'active'),
(37, 'AAAAAAa', 31, 3, 'test254', 2.00, 0, 0, NULL, 5, '/inventory_system/assets/uploads/products/aaaaaa/photo_69a39bf9bd9df7.72695915.png', 10, '2026-03-01 01:52:57', 'active'),
(38, 'aaabbb', 31, 2, 'asdsa', 23.00, 0, 0, NULL, 15, '/inventory_system/assets/uploads/products/aaabbb/photo_69a39eaa8a1113.40854522.jpg', 10, '2026-03-01 02:04:26', 'active'),
(39, 'abc', 31, 4, 'test0z', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/abc/photo_69a3a10549f525.28533695.jpg', 10, '2026-03-01 02:14:29', 'active'),
(40, 'abcd', 31, 2, 'abcd2', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/abcd/photo_69a3a1289d00c1.19450155.jpg', 10, '2026-03-01 02:15:04', 'active'),
(41, 'abcde', 1, 2, 'abcde2', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/abcde/photo_69a3a2206e0d54.03663119.jpg', 10, '2026-03-01 02:19:12', 'active'),
(42, 'abcdef', 31, 2, 'abcdef2', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/abcdef/photo_69a3a2919423a4.83409383.jpg', 10, '2026-03-01 02:21:05', 'active'),
(43, 'abcdefg', 31, 2, 'abcdefg2020', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/abcdefg/photo_69a3a463bdc493.18246361.jpg', 10, '2026-03-01 02:28:51', 'active'),
(44, 'abcefghi', 2, 2, 'abcdefghi', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/abcefghi/photo_69a3a4b12149e6.31845845.jpg', 10, '2026-03-01 02:30:09', 'active'),
(45, 'ANt', 36, 3, 'asftp', 24.00, 0, 0, NULL, 30, '/inventory_system/assets/uploads/products/an/photo_69a3bd108645f8.87275736.jpg', 10, '2026-03-01 02:35:27', 'active'),
(46, 'apt', 38, 3, 'apt20', 10.00, 0, 0, NULL, 5, '/inventory_system/assets/uploads/products/apt/photo_69a3cf386c0827.64649749.jpg', 10, '2026-03-01 05:31:36', 'active'),
(47, 'Aftt', 31, 2, 'gasgsafdsad', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/afsaxfa/photo_69a3d055195be5.30517446.jpg', 10, '2026-03-01 05:36:21', 'active'),
(48, 'qwrqwrwq', 31, 2, 'qwrqwewqdsadas', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/qwrqwrwq/photo_69a3d3cb7f6e30.08297054.jpg', 10, '2026-03-01 05:51:07', 'active'),
(49, 'dasfsagsafsafsadsadsa', 31, 2, 'gsdgsdfdsfsafsa', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/dasfsagsafsafsadsadsa/photo_69a3d429037048.39221655.jpg', 10, '2026-03-01 05:52:41', 'active'),
(50, 'asfasfwqfqwdqwqweqwewq', 31, 2, 'ewqeqweqweqwfqwf', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/asfasfwqfqwdqwqweqwewq/photo_69a3d5b265c475.23128583.jpg', 10, '2026-03-01 05:59:14', 'active'),
(51, 'sadasfasgsafsadsadsa', 31, 2, 'dsadsafasfsafsafsafsafsa', 20.00, 1, 0, NULL, 10, '/inventory_system/assets/uploads/products/sadasfasgsafsadsadsa/photo_69a3d5df26ebb2.40200926.jpg', 10, '2026-03-01 05:59:59', 'active'),
(52, 'asfsafsadsafasfsafsaf', 2, 2, 'safsafasfsafsafsafasfasfsa', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/asfsafsadsafasfsafsaf/photo_69a3d6b89ff255.30637182.jpg', 10, '2026-03-01 06:03:36', 'active'),
(53, 'asfsafwqsafwqrwq', 34, 4, 'qwrqwrwqrqwrqwrqw', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/asfsafwqsafwqrwq/photo_69a3d72063d821.38877976.jpg', 10, '2026-03-01 06:05:20', 'active'),
(54, 'wrqwzzxcxzcxzcxz', 31, 2, 'eteqwrqwrwq', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/wrqwzzxcxzcxzcxz/photo_69a3d84d677276.06382845.jpg', 10, '2026-03-01 06:10:21', 'active'),
(55, 'cocku', 31, 2, 'ckldsf', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/cocku/photo_69a3d955289de6.68391741.jpg', 10, '2026-03-01 06:14:45', 'active'),
(56, 'artuy', 31, 2, 'artuy25', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/artuy/photo_69a3da75be1f03.14158828.jpg', 10, '2026-03-01 06:19:33', 'active'),
(57, 'tqetqwrwq', 31, 2, 'qetqetqetqe', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/tqetqwrwq/photo_69a3db628a74f8.16298277.png', 10, '2026-03-01 06:23:30', 'active'),
(58, 'asfqwfqwrfwqqwr', 31, 2, 'qwrqwrqwrqw', 10.00, 0, 0, NULL, 14, '/inventory_system/assets/uploads/products/asfqwfqwrfwqqwr/photo_69a3dc23c961f3.98602937.jpg', 10, '2026-03-01 06:26:43', 'active'),
(59, 'aarteqytqeteqt', 31, 2, 'rqwrqwrqwdssa', 10.00, 0, 0, NULL, 5, '/inventory_system/assets/uploads/products/aarteqytqeteqt/photo_69a3dd6ed31aa0.62156351.jpg', 10, '2026-03-01 06:32:14', 'active'),
(60, 'catseye', 31, 2, 'casftqwr2', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/catseye/photo_69a3dd9bdade01.97651569.jpeg', 10, '2026-03-01 06:32:59', 'active'),
(61, 'asgasfsadfq', 31, 2, 'wrqwrtqeygq3yq13', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/asgasfsadfq/photo_69a3de2b6d1317.18340560.jpg', 10, '2026-03-01 06:35:23', 'active'),
(62, 'qrqjwriqwrwq', 31, 2, 'rqwtqwqwtqw', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/qrqjwriqwrwq/photo_69a3e071cd9500.27743353.jpg', 10, '2026-03-01 06:45:05', 'active'),
(64, 'qrqjwriqwrwq', 31, 2, 'rqwtqwqwtqw24', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/qrqjwriqwrwq/photo_69a3e07ea241c1.63403377.jpg', 10, '2026-03-01 06:45:18', 'active'),
(65, 'qrqjwriqwrwq', 31, 2, 'rqwtqwqwtqw241', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/qrqjwriqwrwq/photo_69a3e0819a48c7.75702920.jpg', 10, '2026-03-01 06:45:21', 'active'),
(66, 'asgfasgsadqwe', 31, 2, '12r124r1242', 20.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/asgfasgsadqwe/photo_69a3e1a1d95b48.31943747.jpeg', 10, '2026-03-01 06:50:09', 'active'),
(67, 'rqwrqwreqweqwe', 31, 2, 'qwrqwrqwrwqe', 10.00, 0, 0, NULL, 4, '/inventory_system/assets/uploads/products/rqwrqwreqweqwe/photo_69a3e22e0e8a99.16997999.jpg', 10, '2026-03-01 06:52:30', 'active'),
(68, 'rqwrqweqwewq', 31, 2, 'dsadfsadsafas', 10.00, 0, 0, NULL, 5, '/inventory_system/assets/uploads/products/rqwrqweqwewq/photo_69a3e2fc33aaf5.67920586.jpg', 10, '2026-03-01 06:55:56', 'active'),
(69, 'dasfsagfsa', 31, 2, 'fasfsafsa', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/dasfsagfsa/photo_69a3e3546f9d43.79343319.jpg', 10, '2026-03-01 06:57:24', 'active'),
(70, 'eqweqweqwewq', 31, 2, 'eqweqwrwq', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/eqweqweqwewq/photo_69a3e36feff2a2.19679085.jpg', 10, '2026-03-01 06:57:51', 'active'),
(71, 'sansan', 31, 2, '312312321', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/sansan/photo_69a3e45a8625b3.84325977.jpg', 10, '2026-03-01 07:01:46', 'active'),
(72, 'fasgsagsagsafsa', 31, 2, 'fsafsafsaffasfsa', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/fasgsagsagsafsa/photo_69a3e478320e74.30806025.jpg', 10, '2026-03-01 07:02:16', 'active'),
(73, 'chart2', 31, 2, 'qwrqwewdsa', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/chart2/photo_69a3e5b3d067f0.70097536.jpeg', 10, '2026-03-01 07:07:31', 'active'),
(74, 'eqwewqe', 31, 2, 'qweqwewqewq', 10.00, 1, 0, NULL, 10, '/inventory_system/assets/uploads/products/eqwewqewqewqewqe/photo_69a3e66aacf0a7.44765563.jpg', 10, '2026-03-01 07:10:34', 'active'),
(75, 'fasf', 31, 2, 'fass', 10.00, 0, 0, NULL, 10, '/inventory_system/assets/uploads/products/fasfsafsafsadsa/photo_69a3e844a7a860.58554672.jpg', 10, '2026-03-01 07:18:28', 'active'),
(76, 'rqw23', 33, 1, '2124', 10.00, 1, 0, NULL, 100, '/inventory_system/assets/uploads/products/rqwrqwrwqqwe/photo_69a3ea145fe730.22475083.jpg', 5, '2026-03-01 07:26:12', 'active'),
(77, 'dastt', 31, 2, 'fasfasfqwrqw', 10.00, 0, 0, NULL, 32, '/inventory_system/assets/uploads/products/dasdsafasfsa/photo_69a3fd73676986.66752206.jpg', 10, '2026-03-01 08:48:51', 'active'),
(78, 'test214125', 31, 2, 'tewtwet', 124.00, 0, 0, NULL, 124, '/inventory_system/assets/uploads/products/test214125/photo_69a6631fd6f169.63677009.jpg', 5, '2026-03-03 04:27:11', 'inactive'),
(79, 'etq', 31, 2, 'rqweqw', 20.00, 0, 0, NULL, 30, '/inventory_system/assets/uploads/products/etqwrwq/photo_69a663a4087e26.14095137.jpg', 5, '2026-03-03 04:29:24', 'inactive');

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
(2, 255.00, 11.00, 10.00, 'card', '2026-01-17 08:04:52', 3),
(3, 32.40, 2.40, 0.00, 'cash', '2026-03-23 02:31:52', 1);

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
(4, 2, 6, 55.00, 1),
(5, 3, 75, 10.00, 1),
(6, 3, 76, 10.00, 2);

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
(9, 12, 23, '2026-01-27 13:41:26', 1),
(10, 25, 20, '2026-02-25 01:58:37', 1),
(11, 25, 40, '2026-02-25 01:58:47', 1),
(12, 26, 20, '2026-02-25 02:02:44', NULL),
(13, 25, 10, '2026-02-25 02:27:34', 1),
(14, 20, 10, '2026-02-25 02:27:47', 1),
(15, 25, 12, '2026-02-26 02:20:54', 1),
(16, 25, 8, '2026-02-26 02:21:03', 1),
(17, 25, 10, '2026-02-26 02:55:32', 1),
(18, 25, 10, '2026-02-26 03:33:41', 1),
(19, 20, 20, '2026-02-26 03:35:39', 1),
(20, 27, 10, '2026-02-28 02:46:20', NULL),
(21, 25, 1, '2026-02-28 02:47:11', 1),
(22, 28, 10, '2026-02-28 03:19:22', NULL),
(23, 29, 6, '2026-02-28 03:20:11', NULL),
(24, 31, 10, '2026-02-28 03:26:50', NULL),
(25, 32, 10, '2026-02-28 03:27:26', NULL),
(26, 33, 17, '2026-02-28 03:31:18', NULL),
(27, 34, 10, '2026-02-28 03:36:17', 1),
(28, 35, 10, '2026-02-28 03:39:13', 1),
(29, 36, 10, '2026-02-28 03:53:04', 1),
(30, 37, 5, '2026-03-01 01:52:57', 1),
(31, 38, 5, '2026-03-01 02:04:26', 1),
(32, 38, 10, '2026-03-01 02:12:44', 1),
(33, 39, 10, '2026-03-01 02:14:29', 1),
(34, 40, 10, '2026-03-01 02:15:04', 1),
(35, 41, 10, '2026-03-01 02:19:12', 1),
(36, 42, 10, '2026-03-01 02:21:05', 1),
(37, 43, 10, '2026-03-01 02:28:51', 1),
(38, 44, 10, '2026-03-01 02:30:09', 1),
(39, 45, 10, '2026-03-01 02:35:27', 1),
(40, 45, 11, '2026-03-01 04:18:54', 1),
(41, 45, 4, '2026-03-01 04:19:06', 1),
(42, 45, 2, '2026-03-01 04:23:54', 1),
(43, 45, 3, '2026-03-01 05:08:21', 1),
(44, 46, 5, '2026-03-01 05:31:36', 1),
(45, 47, 10, '2026-03-01 05:36:21', 1),
(46, 48, 10, '2026-03-01 05:51:07', 1),
(47, 49, 10, '2026-03-01 05:52:41', 1),
(48, 50, 10, '2026-03-01 05:59:14', 1),
(49, 51, 10, '2026-03-01 05:59:59', 1),
(50, 52, 10, '2026-03-01 06:03:36', 1),
(51, 53, 10, '2026-03-01 06:05:20', 1),
(52, 54, 10, '2026-03-01 06:10:21', 1),
(53, 55, 10, '2026-03-01 06:14:45', 1),
(54, 56, 10, '2026-03-01 06:19:33', 1),
(55, 57, 10, '2026-03-01 06:23:30', 1),
(56, 58, 14, '2026-03-01 06:26:43', 1),
(57, 59, 5, '2026-03-01 06:32:14', 1),
(58, 60, 10, '2026-03-01 06:32:59', 1),
(59, 61, 10, '2026-03-01 06:35:23', 1),
(60, 62, 10, '2026-03-01 06:45:05', 1),
(61, 64, 10, '2026-03-01 06:45:18', 1),
(62, 65, 10, '2026-03-01 06:45:21', 1),
(63, 66, 10, '2026-03-01 06:50:09', 1),
(64, 67, 4, '2026-03-01 06:52:30', 1),
(65, 68, 5, '2026-03-01 06:55:56', 1),
(66, 69, 10, '2026-03-01 06:57:24', 1),
(67, 70, 10, '2026-03-01 06:57:51', 1),
(68, 71, 10, '2026-03-01 07:01:46', 1),
(69, 72, 10, '2026-03-01 07:02:16', 1),
(70, 73, 10, '2026-03-01 07:07:31', 1),
(71, 74, 10, '2026-03-01 07:10:34', 1),
(72, 75, 10, '2026-03-01 07:18:28', 1),
(73, 76, 10, '2026-03-01 07:26:12', 1),
(74, 76, 10, '2026-03-01 07:45:52', 1),
(75, 76, 20, '2026-03-01 08:15:36', 1),
(76, 76, 60, '2026-03-01 08:15:40', 1),
(77, 77, 10, '2026-03-01 08:48:51', 1),
(78, 77, 2, '2026-03-03 03:27:53', 1),
(79, 77, 20, '2026-03-03 03:57:33', 1),
(80, 78, 124, '2026-03-03 04:27:11', 1),
(81, 79, 10, '2026-03-03 04:29:24', 1),
(82, 79, 20, '2026-03-03 04:36:35', 1);

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
(1, 'Admin', 'User2', 'admin@gmail.com', 'admin', '$2y$10$5jjpSkUO5r30wbJNLUG0Iu8h6DHtyVmr1oyB2CrkTlLabnU1/48ci', 'admin', '2026-01-17 06:31:48', 'active', NULL, '/inventory_system/uploads/staff/admin_user/photo_696b2cd3e92ba2.70910251.jpg'),
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
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `fk_inventory_product` (`product_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_config`
--
ALTER TABLE `pos_config`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=80;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `stock_audit_log`
--
ALTER TABLE `stock_audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `stock_in`
--
ALTER TABLE `stock_in`
  MODIFY `stockin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

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
-- Constraints for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE;

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
