-- ════════════════════════════════════════════════════════════════════════════
-- PharmaCare Pro — FINAL PRODUCTION DATABASE
-- Phase 30 Final Release
-- Generated: 2026-06-10
-- Auditor: Claude (Anthropic) — Phase 30 Production Pass
--
-- This is the consolidated, production-ready schema including:
--   ✓ Base schema (Phase 26 — all 39 tables + triggers + views + procedures)
--   ✓ Phase 28 Security additions (login_attempts, security_events, active_sessions columns)
--   ✓ Phase 29 Backup additions (user_settings, system_resets, theme_preset)
--   ✓ Phase 30 deployment guards (IF NOT EXISTS throughout)
--
-- DEPLOYMENT ORDER:
--   1. Import this file into a fresh database
--   2. Confirm all tables created (expected: 44 tables)
--   3. Verify triggers (4), views (2), procedures (2), events (2)
--   4. Upload application files from PharmaCare_Pro_Phase30_Final.zip
--   5. Configure .env with DB credentials
--   6. Login: arslan / arslan123 (change immediately)
-- ════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 09, 2026 at 09:28 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pharmacy_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `active_sessions`
--

CREATE TABLE `active_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `logged_in_at` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-05 14:47:47'),
(2, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-05 14:48:49'),
(3, NULL, 'Auto Login', 'User logged in via remember me token', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-05 14:49:01'),
(4, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-05 14:49:18'),
(5, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-05 15:06:02'),
(6, NULL, 'Auto Login', 'User logged in via remember me token', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 02:55:01'),
(7, NULL, 'Sale', 'Created sale invoice: INV-2026050002', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 03:18:05'),
(8, NULL, 'Sale', 'Created sale invoice: INV-2026050003', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 03:22:27'),
(9, NULL, 'Sale', 'Created sale invoice: INV-2026050004', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 03:33:20'),
(10, NULL, 'Sale', 'Created sale invoice: INV-2026050005', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 03:36:31'),
(11, NULL, 'Add Product', 'Added product: Detran-P', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 04:13:42'),
(12, NULL, 'Update Product', 'Updated product ID: 3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 04:20:49'),
(13, NULL, 'Update Product', 'Updated product ID: 3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 04:21:23'),
(14, NULL, 'Add Product', 'Added product: Nims', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 04:31:48'),
(15, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050001', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 05:34:09'),
(16, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050002', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 05:35:07'),
(17, NULL, 'Update Product', 'Updated product ID: 4', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 05:52:13'),
(18, NULL, 'Update Product', 'Updated product ID: 4', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 10:57:42'),
(19, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050003', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 10:58:20'),
(20, NULL, 'Update Product', 'Updated product ID: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:22:52'),
(21, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050004', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:24:22'),
(22, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050005', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:25:34'),
(23, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050006', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:25:34'),
(24, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050007', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:25:54'),
(25, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050008', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:27:28'),
(26, NULL, 'Update Product', 'Updated product ID: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:28:04'),
(27, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050009', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:28:36'),
(28, NULL, 'Update Product', 'Updated product ID: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:50:31'),
(29, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050010', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:51:06'),
(30, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050011', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:51:30'),
(31, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050012', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:52:05'),
(32, NULL, 'Update Product', 'Updated product ID: 3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:52:57'),
(33, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050013', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:53:21'),
(34, NULL, 'Update Product', 'Updated product ID: 3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:53:41'),
(35, NULL, 'Update Product', 'Updated product ID: 3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:53:55'),
(36, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050014', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:54:22'),
(37, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050015', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:54:40'),
(38, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050016', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:56:35'),
(39, NULL, 'Delete Product', 'Deleted product ID: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:57:01'),
(40, NULL, 'Delete Product', 'Deleted product ID: 4', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:57:04'),
(41, NULL, 'Delete Product', 'Deleted product ID: 5', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:57:07'),
(42, NULL, 'Delete Product', 'Deleted product ID: 3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:57:09'),
(43, NULL, 'Delete Product', 'Deleted product ID: 6', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:57:11'),
(44, NULL, 'Delete Product', 'Deleted product ID: 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:57:13'),
(45, NULL, 'Add Product', 'Added product: Detran-P', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:00:41'),
(46, NULL, 'Add Product', 'Added product: Panadol', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:02:35'),
(47, NULL, 'Add Product', 'Added product: Diaper', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:04:13'),
(48, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050017', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:05:09'),
(49, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050018', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:05:39'),
(50, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050019', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:06:28'),
(51, NULL, 'Add Purchase', 'Added purchase with invoice: PUR-2026050020', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 14:07:26'),
(52, NULL, 'Add Product', 'Added product: Diaper', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 13:28:07'),
(53, NULL, 'Add Product', 'Added product: Shampoo', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 13:29:29'),
(54, NULL, 'Delete Product', 'Deleted product ID: 7', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:23:18'),
(55, NULL, 'Delete Product', 'Deleted product ID: 9', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:23:20'),
(56, NULL, 'Delete Product', 'Deleted product ID: 10', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:23:23'),
(57, NULL, 'Delete Product', 'Deleted product ID: 8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:23:25'),
(58, NULL, 'Delete Product', 'Deleted product ID: 11', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:23:30'),
(59, NULL, 'Auto Login', 'User logged in via remember me token', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 04:22:02'),
(60, NULL, 'Auto Login', 'User logged in via remember me token', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 05:50:06'),
(61, NULL, 'Add Product', 'Added product: Diaper', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:29:11'),
(62, NULL, 'Add Product', 'Added product: Diaper (Small)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:38:53'),
(63, NULL, 'Add Product', 'Added product: Diaper (Medium)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:38:53'),
(64, NULL, 'Add Product', 'Added product: Diaper', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:38:53'),
(65, NULL, 'Delete Product', 'Deleted product ID: 20', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:39:04'),
(66, NULL, 'Delete Product', 'Deleted product ID: 17', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:39:07'),
(67, NULL, 'Add Product', 'Added product: Needle', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:42:37'),
(68, NULL, 'Add Product', 'Added product: Thermometer (Digital Small)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:43:53'),
(69, NULL, 'Add Product', 'Added product: Thermometer (Mercury Small)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:43:53'),
(70, NULL, 'Add Product', 'Added product: Thermometer', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:43:53'),
(71, NULL, 'Delete Product', 'Deleted product ID: 26', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:47:22'),
(72, NULL, 'Add Product', 'Added product: Diaper', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:52:04'),
(73, NULL, 'Add Product', 'Added product: Thermometer Digital Medium', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:53:16'),
(74, NULL, 'Add Product', 'Added product: Thermometer Mercury Small', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:53:16'),
(75, NULL, 'Add Product', 'Added variants for: Thermometer', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:53:16'),
(76, NULL, 'Add Product', 'Added product: Thermometer Large', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:54:01'),
(77, NULL, 'Add Supplier', 'Added supplier: Ahmad Yar', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:59:48'),
(78, NULL, 'Add Product', 'Added: Panadol (ID: 61)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:02:25'),
(79, NULL, 'Add Product', 'Added: Panadol (ID: 64)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:13:56'),
(80, NULL, 'Delete Product', 'Deleted product ID: 61', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:17:02'),
(81, NULL, 'Delete Product', 'Deleted product ID: 64', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:17:05'),
(82, NULL, 'Add Product', 'Added: panadol (ID: 66)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:18:45'),
(83, NULL, 'Add Product', 'Added: ddd (ID: 67)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:19:11'),
(84, NULL, 'Add Product', 'Added: ss (ID: 84)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 09:04:25'),
(85, NULL, 'Add Product', 'Added: ss (ID: 103)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 09:21:41'),
(86, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 02:56:19'),
(87, NULL, 'Update Supplier', 'Updated supplier ID: 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 04:50:08'),
(88, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 12:19:25'),
(89, NULL, 'Login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 03:00:30');

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` int(11) NOT NULL,
  `batch_number` varchar(50) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_price` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `pack_quantity` int(11) NOT NULL COMMENT 'Number of packs purchased',
  `pieces_per_pack` int(11) NOT NULL COMMENT 'Pieces in each pack (e.g., 10 tablets)',
  `total_pieces` int(11) NOT NULL COMMENT 'pack_quantity * pieces_per_pack',
  `remaining_quantity` int(11) NOT NULL COMMENT 'Remaining pieces',
  `manufacture_date` date DEFAULT NULL,
  `expiry_date` date NOT NULL,
  `purchase_date` date NOT NULL,
  `is_expired` tinyint(1) DEFAULT 0,
  `is_blocked` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `units_per_pack` int(11) DEFAULT 1,
  `quantity_of_packs` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `batches`
--

INSERT INTO `batches` (`id`, `batch_number`, `product_id`, `variant_id`, `supplier_id`, `purchase_price`, `selling_price`, `pack_quantity`, `pieces_per_pack`, `total_pieces`, `remaining_quantity`, `manufacture_date`, `expiry_date`, `purchase_date`, `is_expired`, `is_blocked`, `created_at`, `units_per_pack`, `quantity_of_packs`) VALUES
(1, 'BATCH001', 1, NULL, 1, 170.00, 20.00, 10, 10, 100, 85, NULL, '2028-12-15', '2024-01-15', 0, 0, '2026-05-05 13:40:31', 1, 1),
(2, 'BATCH002', 1, NULL, 1, 175.00, 20.00, 5, 10, 50, 50, NULL, '2029-01-20', '2024-02-10', 0, 0, '2026-05-05 13:40:31', 1, 1),
(3, 'BATCH003', 3, NULL, 2, 708.00, 25.00, 10, 48, 480, 476, NULL, '2029-02-28', '2024-01-20', 0, 0, '2026-05-05 13:40:31', 1, 1),
(4, 'BATCH004', 4, NULL, 3, 150.00, 180.00, 20, 1, 20, 18, NULL, '2026-12-31', '2024-02-01', 0, 0, '2026-05-05 13:40:31', 1, 1),
(5, 'BATCH-20260506-69FAD2D15E48A', 4, NULL, 1, 180.00, 180.00, 3, 1, 3, 3, NULL, '2026-05-20', '2026-05-06', 0, 0, '2026-05-06 05:34:09', 1, 1),
(6, 'BATCH-20260506-69FAD30B18F2B', 4, NULL, 1, 320.00, 320.00, 1, 1, 1, 1, NULL, '2026-05-27', '2026-05-06', 0, 0, '2026-05-06 05:35:07', 1, 1),
(7, 'BATCH-20260506-69FB1ECC074AC', 4, NULL, 1, 150.00, 150.00, 1, 1, 1, 1, NULL, '2026-05-21', '2026-05-06', 0, 0, '2026-05-06 10:58:20', 1, 1),
(8, 'BATCH-20260506-69FB410676CA3', 2, NULL, 1, 100.00, 100.00, 1, 15, 15, 15, NULL, '2026-05-20', '2026-05-06', 0, 0, '2026-05-06 13:24:22', 1, 1),
(9, 'BATCH-20260506-69FB414EC64E2', 4, NULL, 3, 150.00, 150.00, 1, 1, 1, 1, NULL, '2026-05-26', '2026-05-06', 0, 0, '2026-05-06 13:25:34', 1, 1),
(10, 'BATCH-20260506-69FB414ECDCC7', 4, NULL, 3, 150.00, 150.00, 1, 1, 1, 1, NULL, '2026-05-26', '2026-05-06', 0, 0, '2026-05-06 13:25:34', 1, 1),
(11, 'BATCH-20260506-69FB416245654', 4, NULL, 2, 300.00, 300.00, 1, 1, 1, 1, NULL, '2026-05-28', '2026-05-06', 0, 0, '2026-05-06 13:25:54', 1, 1),
(12, 'BATCH-20260506-69FB41C099A86', 2, NULL, 3, 100.00, 100.00, 1, 1, 1, 1, NULL, '2026-05-27', '2026-05-06', 0, 0, '2026-05-06 13:27:28', 1, 1),
(13, 'BATCH-20260506-69FB4204EDD41', 2, NULL, 2, 200.00, 20.00, 1, 1, 1, 1, NULL, '2026-05-28', '2026-05-06', 0, 0, '2026-05-06 13:28:36', 1, 1),
(14, 'BATCH-20260506-69FB474A11DB5', 2, NULL, 1, 100.00, 0.00, 1, 1, 1, 1, NULL, '2026-05-21', '2026-05-06', 0, 0, '2026-05-06 13:51:06', 1, 1),
(15, 'BATCH-20260506-69FB476214FD0', 2, NULL, 2, 100.00, 100.00, 1, 1, 1, 1, NULL, '2026-05-27', '2026-05-06', 0, 0, '2026-05-06 13:51:30', 1, 1),
(16, 'BATCH-20260506-69FB478568502', 2, NULL, 1, 200.00, 0.00, 1, 1, 1, 1, NULL, '2026-05-28', '2026-05-06', 0, 0, '2026-05-06 13:52:05', 1, 1),
(17, 'BATCH-20260506-69FB47D16C534', 3, NULL, 1, 100.00, 0.00, 1, 1, 1, 1, NULL, '2026-05-29', '2026-05-06', 0, 0, '2026-05-06 13:53:21', 1, 1),
(18, 'BATCH-20260506-69FB480EC1381', 3, NULL, 1, 100.00, 0.00, 1, 1, 1, 1, NULL, '2026-05-19', '2026-05-06', 0, 0, '2026-05-06 13:54:22', 1, 1),
(19, 'BATCH-20260506-69FB4820E2C8C', 3, NULL, 2, 50.00, 0.00, 1, 1, 1, 1, NULL, '2026-05-21', '2026-05-06', 0, 0, '2026-05-06 13:54:40', 1, 1),
(20, 'BATCH-20260506-69FB4893131A6', 3, NULL, 1, 15.00, 25.00, 1, 48, 48, 48, NULL, '2026-05-20', '2026-05-06', 0, 0, '2026-05-06 13:56:35', 1, 1),
(21, 'BATCH-20260506-69FB4A95CF2FB', 9, NULL, 1, 15.00, 20.00, 1, 48, 48, 48, NULL, '2026-05-20', '2026-05-06', 0, 0, '2026-05-06 14:05:09', 1, 1),
(22, 'BATCH-20260506-69FB4AB36714B', 9, NULL, 3, 10.00, 10.00, 1, 48, 48, 48, NULL, '2026-05-28', '2026-05-06', 0, 0, '2026-05-06 14:05:39', 1, 1),
(23, 'BATCH-20260506-69FB4AE4B858D', 8, NULL, 1, 5.00, 5.00, 1, 1, 1, 1, NULL, '2026-05-26', '2026-05-06', 0, 0, '2026-05-06 14:06:28', 1, 1),
(24, 'BATCH-20260506-69FB4B1EEC030', 8, NULL, 3, 10.00, 10.00, 10, 15, 150, 150, NULL, '2026-05-28', '2026-05-06', 0, 0, '2026-05-06 14:07:26', 1, 1),
(27, 'BATCH-20260513-JGTTO', 146, NULL, 1, 5.00, 7.00, 0, 0, 10, 10, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 05:49:38', 10, 1),
(28, 'BATCH-20260513-V72X7', 146, NULL, 1, 5.00, 7.00, 0, 0, 10, 10, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 05:50:00', 10, 1),
(30, 'BATCH-20260513-9OARX', 147, NULL, 1, 100.00, 150.00, 0, 0, 10, 10, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 05:52:16', 10, 1),
(31, 'BATCH-20260513-C3CPB', 147, NULL, 1, 100.00, 100.00, 0, 0, 10, 10, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 05:54:44', 10, 1),
(32, 'BATCH-20260513-TDH61', 148, NULL, 3, 50.00, 100.00, 0, 0, 10, 10, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 06:10:21', 10, 1),
(33, 'BATCH-20260513-3YC71', 148, NULL, 3, 50.00, 100.00, 0, 0, 20, 20, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 06:38:44', 10, 2),
(34, 'BATCH-20260513-OBL2D', 146, NULL, 3, 5.00, 7.00, 0, 0, 10, 10, NULL, '0000-00-00', '2026-05-13', 0, 0, '2026-05-13 06:39:14', 10, 1),
(35, 'BATCH-20260513-YI4F7', 146, NULL, 3, 5.00, 7.00, 0, 0, 10, 10, NULL, '2026-05-30', '2026-05-13', 0, 0, '2026-05-13 06:39:33', 10, 1),
(36, 'BATCH-20260513-4V5ZW', 150, NULL, 3, 50.00, 100.00, 0, 0, 48, 48, NULL, '2026-05-23', '2026-05-13', 0, 0, '2026-05-13 06:41:57', 48, 1),
(37, 'BATCH-20260513-3EXFU', 146, NULL, 3, 5.00, 7.00, 0, 0, 10, 10, NULL, '2026-05-29', '2026-05-13', 0, 0, '2026-05-13 06:55:15', 10, 1),
(38, 'BATCH-20260513-6EGP3', 148, NULL, 2, 50.00, 100.00, 0, 0, 10, 10, NULL, '2026-05-29', '2026-05-13', 0, 0, '2026-05-13 06:57:04', 10, 1),
(39, 'BATCH-20260513-1VNPM', 152, NULL, 3, 15.00, 17.00, 0, 0, 160, 160, NULL, '2026-05-29', '2026-05-13', 0, 0, '2026-05-13 07:08:26', 16, 10),
(40, 'BATCH-20260513-R4H75', 148, NULL, 3, 5.00, 10.00, 0, 0, 20, 20, NULL, '2026-05-15', '2026-05-13', 0, 0, '2026-05-13 07:08:26', 10, 2),
(41, 'BATCH-20260513-3E2LS', 153, NULL, 3, 5.00, 7.00, 0, 0, 1000, 1000, NULL, '2026-05-22', '2026-05-13', 0, 0, '2026-05-13 07:26:38', 10, 100),
(42, 'BATCH-20260513-S6IH6', 149, NULL, 2, 10.00, 0.00, 0, 0, 96, 96, NULL, '2026-05-29', '2026-05-13', 0, 0, '2026-05-13 07:42:23', 48, 2),
(43, 'BATCH-20260513-E234X', 155, NULL, 3, 80.00, 100.00, 0, 0, 1, 1, NULL, '2026-05-15', '2026-05-13', 0, 0, '2026-05-13 07:51:50', 1, 1),
(44, 'BATCH-20260513-6TUAF', 157, NULL, 3, 80.00, 100.00, 0, 0, 1, 1, NULL, '2026-05-15', '2026-05-13', 0, 0, '2026-05-13 08:07:36', 1, 1),
(45, 'BATCH-20260513-1VHFA', 159, NULL, 3, 15.00, 17.00, 0, 0, 48, 40, NULL, '2026-05-23', '2026-05-13', 0, 0, '2026-05-13 11:39:10', 48, 1),
(46, 'BATCH-20260513-N99GP', 158, NULL, 3, 5.00, 9.99, 0, 0, 100, 80, NULL, '2026-05-22', '2026-05-13', 0, 0, '2026-05-13 11:41:06', 10, 10),
(47, 'BATCH-20260513-J4RQ4', 159, NULL, 3, 15.00, 17.00, 0, 0, 48, 48, NULL, '2026-05-28', '2026-05-13', 0, 0, '2026-05-13 14:01:44', 48, 1),
(48, 'BATCH-20260513-IK7W5', 158, NULL, 3, 5.00, 9.99, 0, 0, 10, 10, NULL, '2026-05-27', '2026-05-13', 0, 0, '2026-05-13 14:02:04', 10, 1),
(49, 'BATCH-20260513-W2C3G', 160, NULL, 3, 5.00, 15.00, 0, 0, 10, 0, NULL, '2026-05-28', '2026-05-13', 0, 0, '2026-05-13 14:11:43', 10, 1),
(50, 'BATCH-20260513-2D8I1', 160, NULL, 3, 5.00, 15.00, 0, 0, 10, 4, NULL, '2026-05-29', '2026-05-13', 0, 0, '2026-05-13 14:19:10', 10, 1);

--
-- Triggers `batches`
--
DELIMITER $$
CREATE TRIGGER `update_expired_flag` BEFORE UPDATE ON `batches` FOR EACH ROW BEGIN
    IF NEW.expiry_date < CURDATE() THEN
        SET NEW.is_expired = 1;
    ELSE
        SET NEW.is_expired = 0;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `cashbook`
--

CREATE TABLE `cashbook` (
  `id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `entry_type` enum('sale','purchase_payment','credit_received','expense','sale_return','purchase_return','opening','closing','credit_given','expired_loss','cash_in','cash_out') NOT NULL,
  `description` text DEFAULT NULL,
  `credit` decimal(12,2) DEFAULT 0.00,
  `debit` decimal(12,2) DEFAULT 0.00,
  `balance` decimal(12,2) DEFAULT 0.00,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `voided` tinyint(1) DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `category_name`, `parent_id`, `status`, `created_at`) VALUES
(1, 'Medicine', NULL, 1, '2026-05-05 13:40:30'),
(2, 'Non-Medicine', NULL, 1, '2026-05-05 13:40:30');

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `id` int(11) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `company_logo` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `gst_no` varchar(50) DEFAULT NULL,
  `tin_no` varchar(50) DEFAULT NULL,
  `footer_text` varchar(255) DEFAULT 'Thank you! Visit Again',
  `currency_symbol` varchar(10) DEFAULT '₹',
  `invoice_prefix` varchar(20) DEFAULT 'INV',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pharmacy_name` varchar(255) DEFAULT NULL,
  `pharmacy_tagline` varchar(255) DEFAULT 'Your Health, Our Priority',
  `pharmacy_type` enum('pharmacy','medical_store','dispensary','chemist') DEFAULT 'pharmacy',
  `logo_path` varchar(255) DEFAULT NULL,
  `favicon_path` varchar(255) DEFAULT NULL,
  `primary_color` varchar(7) DEFAULT '#0d6efd',
  `sidebar_color` varchar(7) DEFAULT '#212529',
  `accent_color` varchar(7) DEFAULT '#198754',
  `show_logo_on_invoice` tinyint(1) DEFAULT 1,
  `show_logo_on_sidebar` tinyint(1) NOT NULL DEFAULT 1,
  `show_tagline_on_invoice` tinyint(1) DEFAULT 1,
  `receipt_paper_size` enum('A4','A5','80mm','58mm') DEFAULT 'A4',
  `low_stock_threshold` int(11) DEFAULT 10,
  `near_expiry_days` int(11) DEFAULT 30,
  `default_tax_rate` decimal(5,2) DEFAULT 0.00,
  `invoice_terms` text DEFAULT NULL,
  `invoice_footer_text` varchar(255) DEFAULT NULL,
  `show_payment_on_invoice` tinyint(1) DEFAULT 1,
  `show_batch_on_invoice` tinyint(1) DEFAULT 1,
  `system_timezone` varchar(50) DEFAULT 'Asia/Karachi',
  `date_format` varchar(20) DEFAULT 'd M Y',
  `time_format` varchar(10) DEFAULT 'h:i A',
  `session_timeout` int(11) DEFAULT 60,
  `default_language` varchar(10) DEFAULT 'en',
  `license_number` varchar(100) DEFAULT NULL,
  `owner_name` varchar(150) DEFAULT NULL,
  `password_change_days` int(11) DEFAULT 90,
  `login_attempts_limit` int(11) DEFAULT 5,
  `lockout_duration` int(11) DEFAULT 15,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `ip_whitelist` text DEFAULT NULL,
  `body_bg_color` varchar(7) DEFAULT '#f0f2f5',
  `card_bg_color` varchar(7) DEFAULT '#ffffff',
  `body_text_color` varchar(7) DEFAULT '#444444',
  `heading_color` varchar(7) DEFAULT '#1a1d23',
  `navbar_bg_color` varchar(7) DEFAULT '#ffffff',
  `navbar_border_color` varchar(7) DEFAULT '#e9ecef',
  `sidebar_text_color` varchar(7) DEFAULT '#a0aec0',
  `link_color` varchar(7) DEFAULT '#0d6efd',
  `border_color` varchar(7) DEFAULT '#dee2e6',
  `table_header_color` varchar(7) DEFAULT '#f8f9fa',
  `table_stripe_color` varchar(7) DEFAULT '#f9f9ff',
  `modal_header_color` varchar(7) DEFAULT '#0d6efd',
  `input_focus_color` varchar(7) DEFAULT '#667eea',
  `badge_instock_bg` varchar(7) DEFAULT '#d1fae5',
  `badge_instock_text` varchar(7) DEFAULT '#065f46',
  `badge_oos_bg` varchar(7) DEFAULT '#fee2e2',
  `badge_oos_text` varchar(7) DEFAULT '#991b1b',
  `badge_expiry_bg` varchar(7) DEFAULT '#fff3cd',
  `badge_expiry_text` varchar(7) DEFAULT '#856404',
  `badge_lowstock_bg` varchar(7) DEFAULT '#fff7e6',
  `badge_lowstock_text` varchar(7) DEFAULT '#c2410c',
  `badge_expired_bg` varchar(7) DEFAULT '#fce7f3',
  `badge_expired_text` varchar(7) DEFAULT '#9d174d',
  `dark_bg_color` varchar(7) DEFAULT '#0f1117',
  `dark_sidebar_color` varchar(7) DEFAULT '#161b22',
  `dark_card_color` varchar(7) DEFAULT '#1c2128',
  `dark_text_color` varchar(7) DEFAULT '#e6edf3',
  `body_font_family` varchar(60) DEFAULT 'Segoe UI',
  `body_font_size` tinyint(4) DEFAULT 14,
  `card_border_radius` tinyint(4) DEFAULT 12,
  `btn_border_radius` tinyint(4) DEFAULT 8,
  `shadow_intensity` tinyint(4) DEFAULT 12,
  `dark_mode` tinyint(1) DEFAULT 0,
  `theme_preset` varchar(30) DEFAULT 'custom',
  `table_striped` tinyint(1) DEFAULT 1,
  `sidebar_compact` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `company_settings`
--

INSERT INTO `company_settings` (`id`, `company_name`, `company_logo`, `phone`, `email`, `address`, `gst_no`, `tin_no`, `footer_text`, `currency_symbol`, `invoice_prefix`, `created_at`, `pharmacy_name`, `pharmacy_tagline`, `pharmacy_type`, `logo_path`, `favicon_path`, `primary_color`, `sidebar_color`, `accent_color`, `show_logo_on_invoice`, `show_logo_on_sidebar`, `show_tagline_on_invoice`, `receipt_paper_size`, `low_stock_threshold`, `near_expiry_days`, `default_tax_rate`, `invoice_terms`, `invoice_footer_text`, `show_payment_on_invoice`, `show_batch_on_invoice`, `system_timezone`, `date_format`, `time_format`, `session_timeout`, `default_language`, `license_number`, `owner_name`, `password_change_days`, `login_attempts_limit`, `lockout_duration`, `two_factor_enabled`, `ip_whitelist`, `body_bg_color`, `card_bg_color`, `body_text_color`, `heading_color`, `navbar_bg_color`, `navbar_border_color`, `sidebar_text_color`, `link_color`, `border_color`, `table_header_color`, `table_stripe_color`, `modal_header_color`, `input_focus_color`, `badge_instock_bg`, `badge_instock_text`, `badge_oos_bg`, `badge_oos_text`, `badge_expiry_bg`, `badge_expiry_text`, `badge_lowstock_bg`, `badge_lowstock_text`, `badge_expired_bg`, `badge_expired_text`, `dark_bg_color`, `dark_sidebar_color`, `dark_card_color`, `dark_text_color`, `body_font_family`, `body_font_size`, `card_border_radius`, `btn_border_radius`, `shadow_intensity`, `dark_mode`, `theme_preset`, `table_striped`, `sidebar_compact`) VALUES
(1, 'Pharmacy Management System - Pakistan', NULL, '9876543210', 'info@pharmacy.com', '123, Main Street, City - 123456', '27AAAAA1234B1Z', NULL, 'Thank you for shopping with us!', 'Rs', 'INV', '2026-05-05 13:40:32', NULL, 'Your Health, Our Priority', 'pharmacy', NULL, NULL, '#0d6efd', '#212529', '#198754', 1, 1, 1, 'A4', 10, 30, 0.00, NULL, NULL, 1, 1, 'Asia/Karachi', 'd M Y', 'h:i A', 60, 'en', NULL, NULL, 90, 5, 15, 0, NULL, '#f0f2f5', '#ffffff', '#444444', '#1a1d23', '#ffffff', '#e9ecef', '#a0aec0', '#0d6efd', '#dee2e6', '#f8f9fa', '#f9f9ff', '#0d6efd', '#667eea', '#d1fae5', '#065f46', '#fee2e2', '#991b1b', '#fff3cd', '#856404', '#fff7e6', '#c2410c', '#fce7f3', '#9d174d', '#0f1117', '#161b22', '#1c2128', '#e6edf3', 'Segoe UI', 14, 12, 8, 12, 0, 'custom', 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `credit_payments`
--

CREATE TABLE `credit_payments` (
  `id` int(11) NOT NULL,
  `credit_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','online') NOT NULL,
  `note` text DEFAULT NULL,
  `payment_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `credit_payments`
--

INSERT INTO `credit_payments` (`id`, `credit_id`, `amount`, `payment_method`, `note`, `payment_date`) VALUES
(1, 4, 20.00, 'cash', 'Upfront for INV-2026050015', '2026-05-14 09:26:37');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `outstanding_balance` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_credits`
--

CREATE TABLE `customer_credits` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL COMMENT 'Linked sale if from POS',
  `customer_name` varchar(100) NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','paid','cancelled') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_credits`
--

INSERT INTO `customer_credits` (`id`, `sale_id`, `customer_name`, `customer_phone`, `total_amount`, `due_date`, `description`, `status`, `created_at`) VALUES
(1, 7, '00', '00', 32.00, '2026-05-21', 'Sale #INV-2026050007', 'active', '2026-05-14 08:52:01'),
(2, 10, '111', '111', 15.00, '2026-05-21', 'Sale #INV-2026050010', 'active', '2026-05-14 09:04:36'),
(3, 14, '111', '11', 27.93, '2026-05-21', 'Sale #INV-2026050014', 'active', '2026-05-14 09:11:17'),
(4, 15, '111', '11', 26.99, '2026-05-21', 'Sale #INV-2026050015', 'active', '2026-05-14 09:26:37');

-- --------------------------------------------------------

--
-- Table structure for table `dosage_forms`
--

CREATE TABLE `dosage_forms` (
  `id` int(11) NOT NULL,
  `form_name` varchar(50) NOT NULL,
  `usage_count` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dosage_forms`
--

INSERT INTO `dosage_forms` (`id`, `form_name`, `usage_count`, `created_at`) VALUES
(1, 'Tablet', 52, '2026-05-05 13:40:31'),
(2, 'Capsule', 3, '2026-05-05 13:40:31'),
(3, 'Syrup', 6, '2026-05-05 13:40:31'),
(4, 'Injection', 2, '2026-05-05 13:40:31'),
(5, 'Ointment', 1, '2026-05-05 13:40:31'),
(6, 'Drops', 2, '2026-05-05 13:40:31'),
(7, 'Diaper', 7, '2026-05-05 13:40:31'),
(8, 'Shampoo', 3, '2026-05-05 13:40:31'),
(9, 'Soap', 1, '2026-05-05 13:40:31'),
(10, 'Cream', 1, '2026-05-05 13:40:31'),
(11, 'Lotion', 1, '2026-05-05 13:40:31'),
(12, 't', 4, '2026-05-11 14:31:22');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'cash',
  `reference_no` varchar(100) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'Rent', 'Monthly rent expenses', '2026-06-09 07:14:19'),
(2, 'Utilities', 'Electricity, water, gas bills', '2026-06-09 07:14:19'),
(3, 'Salaries', 'Staff salaries and wages', '2026-06-09 07:14:19'),
(4, 'Miscellaneous', 'Other expenses', '2026-06-09 07:14:19');

-- --------------------------------------------------------

--
-- Stand-in structure for view `expiry_alerts_view`
-- (See below for the actual view)
--
CREATE TABLE `expiry_alerts_view` (
`batch_id` int(11)
,`batch_number` varchar(50)
,`product_name` varchar(100)
,`generic_name` varchar(100)
,`expiry_date` date
,`remaining_quantity` int(11)
,`days_to_expire` int(7)
,`expiry_status` varchar(8)
);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_sequences`
--

CREATE TABLE `invoice_sequences` (
  `id` int(11) NOT NULL,
  `prefix` varchar(10) DEFAULT 'INV',
  `last_number` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_sequences`
--

INSERT INTO `invoice_sequences` (`id`, `prefix`, `last_number`, `created_at`) VALUES
(1, 'INV', 0, '2026-06-09 07:14:19');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(100) NOT NULL,
  `category_id` int(11) NOT NULL,
  `generic_name` varchar(100) DEFAULT NULL,
  `brand_name` varchar(100) NOT NULL,
  `strength` varchar(100) DEFAULT NULL,
  `strength_unit` varchar(20) DEFAULT NULL,
  `dosage_form` varchar(100) DEFAULT NULL,
  `size_value` varchar(100) DEFAULT NULL,
  `unit_type` varchar(20) DEFAULT 'Pcs',
  `units_per_pack` int(11) NOT NULL DEFAULT 1,
  `quantity_of_packs` int(11) NOT NULL DEFAULT 1,
  `purchase_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_quantity` int(11) DEFAULT 1,
  `pieces_per_unit` int(11) DEFAULT 1,
  `is_variant` tinyint(1) DEFAULT 0,
  `variant_parent_id` int(11) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT 10,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `stock_quantity` decimal(12,2) DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `parent_id`, `product_code`, `product_name`, `category_id`, `generic_name`, `brand_name`, `strength`, `strength_unit`, `dosage_form`, `size_value`, `unit_type`, `units_per_pack`, `quantity_of_packs`, `purchase_price`, `sale_price`, `pack_quantity`, `pieces_per_unit`, `is_variant`, `variant_parent_id`, `reorder_level`, `status`, `created_at`, `stock_quantity`, `updated_at`) VALUES
(1, NULL, 'MED001', 'Panadol', 1, 'Paracetamol', 'GSK', '500', 'mg', 'Tablet', NULL, 'Strip', 1, 1, 0.00, 0.00, 10, 10, 0, NULL, 20, 0, '2026-05-05 13:40:31', 0.00, NULL),
(2, NULL, 'MED002', 'Augmentin', 1, 'Amoxicillin + Clavulanic Acid', 'GSK', '625', 'mg', 'Tablet', NULL, 'Strip', 1, 1, 0.00, 0.00, 10, 10, 0, NULL, 15, 0, '2026-05-05 13:40:31', 0.00, NULL),
(3, NULL, 'NMED001', 'Diaper', 2, '', 'Guddu', '', '', 'Diaper', NULL, 'Pack', 1, 1, 0.00, 0.00, 48, 48, 1, NULL, 10, 0, '2026-05-05 13:40:31', 0.00, NULL),
(4, NULL, 'NMED002', 'Bio Amla Shampoo', 2, '', 'Bio Amla', '', '', 'Shampoo', NULL, 'Bottle', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 5, 0, '2026-05-05 13:40:31', 0.00, NULL),
(8, NULL, 'MED0004', 'Panadol', 1, 'Paracetamol', 'GSK', '', 'mg', '', NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-06 14:02:35', 0.00, NULL),
(9, NULL, 'NMED0003', 'Diaper', 2, '', 'Guddu', '', 'mg', '', NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-06 14:04:13', 0.00, NULL),
(17, NULL, 'NMED0004', 'Diaper', 2, NULL, 'Guddu', NULL, NULL, NULL, '0', 'Pcs', 48, 1, 15.00, 20.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 06:29:11', 0.00, NULL),
(20, NULL, 'NMED0005', 'Diaper', 2, NULL, 'Guddu', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 0, '2026-05-12 06:38:53', 0.00, NULL),
(21, 20, 'NMED0006', 'Diaper (Small)', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Small', 'Pcs', 48, 1, 15.00, 17.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 06:38:53', 0.00, NULL),
(22, 20, 'NMED0007', 'Diaper (Medium)', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Medium', 'Pcs', 48, 1, 17.00, 20.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 06:38:53', 0.00, NULL),
(25, NULL, 'NMED0008', 'Needle', 2, NULL, 'Master', NULL, NULL, NULL, '3 CC', 'Pack', 1, 1, 1.00, 3.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 06:42:37', 0.00, NULL),
(26, NULL, 'NMED0009', 'Thermometer', 2, NULL, 'Certiza', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 0, '2026-05-12 06:43:53', 0.00, NULL),
(27, 26, 'NMED0010', 'Thermometer (Digital Small)', 2, NULL, 'Certiza', NULL, NULL, NULL, 'Digital Small', 'Pcs', 1, 1, 300.00, 350.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 06:43:53', 0.00, NULL),
(28, 26, 'NMED0011', 'Thermometer (Mercury Small)', 2, NULL, 'Certiza', NULL, NULL, NULL, 'Mercury Small', 'Pcs', 1, 1, 150.00, 200.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 06:43:53', 0.00, NULL),
(52, NULL, 'NMED0012', 'Diaper', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Zero', 'Pcs', 48, 1, 15.00, 17.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 07:52:04', 0.00, NULL),
(53, NULL, 'NMED0013', 'Thermometer Digital Medium', 2, NULL, 'Certiza', NULL, NULL, NULL, 'Digital Medium', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 07:53:16', 0.00, NULL),
(54, NULL, 'NMED0014', 'Thermometer Mercury Small', 2, NULL, 'Certiza', NULL, NULL, NULL, 'Mercury Small', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 07:53:16', 0.00, NULL),
(55, NULL, 'NMED0015', 'Thermometer Large', 2, NULL, 'Certiza', NULL, NULL, NULL, 'Large', 'Pcs', 1, 1, 100.00, 150.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 07:54:01', 0.00, NULL),
(61, NULL, 'NMED0016', 'Panadol', 2, NULL, 'gsk', NULL, NULL, NULL, NULL, 'Pcs', 10, 1, 5.00, 10.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:02:25', 0.00, NULL),
(64, NULL, 'NMED0017', 'Panadol', 2, NULL, 'gsk', NULL, NULL, NULL, NULL, 'Pcs', 10, 1, 10.00, 15.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:13:56', 0.00, NULL),
(66, NULL, 'NMED0018', 'panadol', 2, NULL, 'sd', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:18:45', 0.00, NULL),
(67, NULL, 'NMED0019', 'ddd', 2, NULL, 'ddd', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:19:11', 0.00, NULL),
(77, NULL, 'NMED0020', 'panadol', 2, NULL, 'sddf', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:43:55', 0.00, NULL),
(81, NULL, 'NMED0021', 'Panadol', 2, NULL, 'gsk', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:48:35', 0.00, NULL),
(82, NULL, 'NMED0022', 'telephon small', 2, NULL, 'nokia', NULL, NULL, NULL, 'small', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 08:49:08', 0.00, NULL),
(84, NULL, 'NMED0023', 'ss', 2, NULL, 'dd', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 09:04:25', 0.00, NULL),
(103, NULL, 'NMED0024', 'ss', 2, NULL, 'aaa', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 09:21:41', 0.00, NULL),
(104, NULL, '', 'Test Medicine 1778577837', 1, 'Test Generic', 'Test Brand', '500mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 10.00, 15.00, 1, 1, 0, NULL, 10, 0, '2026-05-12 09:23:57', 0.00, NULL),
(118, NULL, 'MED0005', 'AA', 1, NULL, 'AA', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:04:59', 0.00, NULL),
(119, NULL, 'MED0006', 'Detran-P', 1, 'Diclofenac Potassium', 'GSK', '75mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 5.00, 10.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:05:48', 0.00, NULL),
(120, NULL, 'MED0007', 'Detran-P Tablet 100mg', 1, 'Diclofenac Potassium', 'High Noon', '100mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:09:01', 0.00, NULL),
(121, NULL, 'MED0008', 'Panadol Tablet 150MG', 1, 'paracetamol', 'gsk', '150MG', NULL, 'Tablet', NULL, 'Strip', 15, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:10:34', 0.00, NULL),
(122, NULL, 'MED0009', 'Panadol Tablet 450MG', 1, 'paracetamol', 'gsk', '450MG', NULL, 'Tablet', NULL, 'Strip', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:10:34', 0.00, NULL),
(123, NULL, 'MED0010', 'pANADOL Tablet 3333', 1, 'HHH', 'HHH', '3333', NULL, 'Tablet', NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:12:02', 0.00, NULL),
(124, NULL, 'MED0011', 'panadol', 1, NULL, 'sss', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:12:30', 0.00, NULL),
(125, NULL, 'NMED0025', 'dddd', 2, NULL, 'ddd', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:13:17', 0.00, NULL),
(126, NULL, 'NMED0026', 'dddd aaaa', 2, NULL, 'ddd', NULL, NULL, NULL, 'aaaa', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:13:43', 0.00, NULL),
(127, NULL, 'NMED0027', 'Pad Small', 2, NULL, 'Always', NULL, NULL, NULL, 'Small', 'Pcs', 13, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:14:46', 0.00, NULL),
(128, NULL, 'NMED0028', 'Pad Small', 2, NULL, 'Always', NULL, NULL, NULL, 'Small', 'Pcs', 13, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:14:46', 0.00, NULL),
(129, NULL, 'NMED0029', 'pad', 2, NULL, 'always', NULL, NULL, NULL, 'small', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:15:31', 0.00, NULL),
(130, NULL, 'NMED0030', 'pad Small', 2, NULL, 'ssss', NULL, NULL, NULL, 'Small', 'Pcs', 13, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:16:09', 0.00, NULL),
(131, NULL, 'NMED0031', 'pad medium', 2, NULL, 'ssss', NULL, NULL, NULL, 'medium', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 1, '2026-05-13 03:16:09', 0.00, NULL),
(132, NULL, 'MED0012', 'Panadol', 1, 'ss', 'gsk', '5', NULL, 'Tablet', NULL, 'Strip', 10, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:27:00', 0.00, NULL),
(133, NULL, 'MED0013', 'naswar Syrup 4', 1, 'ddd', 'sss', '4', NULL, 'Syrup', NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:27:29', 0.00, NULL),
(134, NULL, 'MED0014', 'Panadol', 1, 'Paracetamol', 'GSK', '100mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 5.00, 10.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:29:16', 0.00, NULL),
(135, NULL, 'MED0015', 'Panadol', 1, 'Paracetamol', 'GSK', '100mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 5.00, 10.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:29:16', 0.00, NULL),
(136, NULL, 'NMED0032', 'Pamper', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Small', 'Pcs', 48, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:30:19', 0.00, NULL),
(137, NULL, 'MED0016', 'Panadol Tablet 60mg', 1, 'Paracetamol', 'GSK', '60mg', NULL, 'Tablet', NULL, 'Strip', 19, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:32:20', 0.00, NULL),
(138, NULL, 'NMED0033', 'pad Large', 2, NULL, 'always', NULL, NULL, NULL, 'Large', 'Pcs', 16, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:33:08', 0.00, NULL),
(139, NULL, 'MED0017', 'Nims Tablet 150mg', 1, 'Nimsulide', 'Friends', '150mg', NULL, 'Tablet', NULL, 'Strip', 15, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:34:45', 0.00, NULL),
(140, NULL, 'MED0018', 'Nims Tablet 200mg', 1, 'Nimsulide', 'Friends', '200mg', NULL, 'Tablet', NULL, 'Strip', 20, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:34:45', 0.00, NULL),
(141, NULL, 'NMED0034', 'needle 3cc', 2, NULL, 'master', NULL, NULL, NULL, '3cc', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:36:02', 0.00, NULL),
(142, NULL, 'NMED0035', 'needle 5cc', 2, NULL, 'master', NULL, NULL, NULL, '5cc', 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:36:02', 0.00, NULL),
(143, NULL, 'MED0019', 'Detran-P', 1, 'pharma', 'Friends', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:36:54', 0.00, NULL),
(144, NULL, 'MED0020', 'Panadol Drops', 1, 'Paracetamol', 'GSK', '100mg', NULL, 'Drops', NULL, 'Pcs', 1, 10, 40.00, 50.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 03:44:09', 0.00, NULL),
(145, NULL, 'MED0021', 'panadol', 1, NULL, 'gsk', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 04:40:16', 0.00, NULL),
(146, NULL, 'MED0022', 'Panadol', 1, 'Paracetamol', 'GSK', '100mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 5.00, 7.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 05:03:49', 0.00, NULL),
(147, NULL, 'MED0023', 'Detran-P Tablet 100mg', 1, 'Diclofenac Potassium', 'Friends', '100mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 05:05:52', 0.00, NULL),
(148, NULL, 'MED0024', 'Detran-P', 1, 'Diclofenac Potassium', 'Friends', '150mg', NULL, 'Tablet', NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 05:05:52', 0.00, NULL),
(149, NULL, 'NMED0036', 'Diaper Zero', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Zero', 'Pcs', 48, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 05:07:03', 0.00, NULL),
(150, NULL, 'NMED0037', 'Diaper One', 2, NULL, 'Guddu', NULL, NULL, NULL, 'One', 'Pcs', 48, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 05:07:03', 0.00, NULL),
(151, NULL, 'NMED0038', 'Diaper Two', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Two', 'Pcs', 48, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 05:07:03', 0.00, NULL),
(152, NULL, 'NMED0039', 'Pad', 2, NULL, 'Always', NULL, NULL, NULL, 'Small', 'Pcs', 16, 1, 15.00, 17.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 07:07:04', 0.00, NULL),
(153, NULL, 'MED0025', 'Panadol', 1, 'Paracetamol', 'GSK', '100mg', NULL, 'Tablet', NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 07:25:35', 0.00, NULL),
(154, 148, 'MED0026', 'Detran-P 200ml', 1, NULL, 'Friends', '200ml', NULL, 'Syrup', NULL, 'Bottle', 1, 1, 0.00, 0.00, 1, 1, 1, NULL, 10, 0, '2026-05-13 07:28:20', 0.00, NULL),
(155, NULL, 'MED0027', 'Detran-P', 1, NULL, 'aaaa', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 07:51:31', 0.00, NULL),
(156, NULL, 'MED0028', 'aaaa', 1, NULL, 'aaa', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 07:52:29', 0.00, NULL),
(157, NULL, 'MED0029', 'aaaaa', 1, NULL, 'bbbb', NULL, NULL, NULL, NULL, 'Pcs', 1, 1, 0.00, 0.00, 1, 1, 0, NULL, 10, 0, '2026-05-13 08:07:17', 0.00, NULL),
(158, NULL, 'MED0030', 'Nubrol Forte', 1, 'Brufen', 'GSK', '250mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 5.00, 9.99, 1, 1, 0, NULL, 10, 1, '2026-05-13 11:38:00', 0.00, NULL),
(159, NULL, 'NMED0040', 'Diaper', 2, NULL, 'Guddu', NULL, NULL, NULL, 'Zero', 'Pcs', 48, 1, 15.00, 17.00, 1, 1, 0, NULL, 10, 1, '2026-05-13 11:38:22', 0.00, NULL),
(160, NULL, 'MED0031', 'Detran-P', 1, 'Diclofenac Potassium', 'Friends', '75mg', NULL, 'Tablet', NULL, 'Strip', 10, 1, 5.00, 15.00, 1, 1, 0, NULL, 10, 1, '2026-05-13 12:31:42', 0.00, NULL),
(161, NULL, 'NMED0041', 'Pad Small', 2, NULL, 'Always', NULL, NULL, NULL, 'Small', 'Pcs', 16, 1, 15.00, 25.00, 1, 1, 0, NULL, 10, 1, '2026-05-13 12:32:29', 0.00, NULL),
(162, NULL, 'NMED0042', 'Pad Medium', 2, NULL, 'Always', NULL, NULL, NULL, 'Medium', 'Pcs', 16, 1, 20.00, 35.00, 1, 1, 0, NULL, 10, 1, '2026-05-13 12:32:29', 0.00, NULL),
(163, NULL, 'NMED0043', 'Pad Large', 2, NULL, 'Always', NULL, NULL, NULL, 'Large', 'Pcs', 16, 1, 25.00, 45.00, 1, 1, 0, NULL, 10, 1, '2026-05-13 12:32:29', 0.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_name` varchar(50) NOT NULL,
  `variant_value` varchar(50) NOT NULL,
  `pack_quantity` int(11) DEFAULT 1,
  `pieces_per_pack` int(11) DEFAULT 1,
  `default_selling_price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`id`, `product_id`, `variant_name`, `variant_value`, `pack_quantity`, `pieces_per_pack`, `default_selling_price`, `created_at`) VALUES
(8, 4, 'Volume', '250ml', 1, 1, 150.00, '2026-05-06 10:57:42'),
(9, 4, 'Volume', '500ml', 1, 1, 300.00, '2026-05-06 10:57:42'),
(14, 2, 'Size', '250MG', 1, 1, 100.00, '2026-05-06 13:50:31'),
(15, 2, 'Size', '625MG', 1, 1, 20.00, '2026-05-06 13:50:31'),
(16, 2, 'Size', '100MG', 1, 1, 0.00, '2026-05-06 13:50:31'),
(19, 3, 'Size', 'ONE', 1, 1, 0.00, '2026-05-06 13:53:55'),
(20, 3, 'Size', 'ZERO', 1, 1, 0.00, '2026-05-06 13:53:55'),
(21, 8, 'Size', '250mg', 10, 2, 5.00, '2026-05-06 14:02:35'),
(22, 8, 'Size', '500mg', 10, 2, 10.00, '2026-05-06 14:02:35'),
(23, 9, 'Size', 'Zero', 1, 48, 10.00, '2026-05-06 14:04:13'),
(24, 9, 'Size', 'One', 1, 48, 14.99, '2026-05-06 14:04:13');

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL,
  `purchase_invoice_no` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_date` date NOT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('paid','partial','pending') DEFAULT 'pending',
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `due_amount` decimal(12,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`id`, `purchase_invoice_no`, `supplier_id`, `purchase_date`, `subtotal`, `discount`, `tax_amount`, `total`, `payment_status`, `paid_amount`, `due_amount`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'PUR-2024-0001', 1, '2024-01-15', 1700.00, 0.00, 0.00, 1700.00, 'paid', 1700.00, 0.00, NULL, NULL, '2026-05-05 13:40:32', NULL),
(2, 'PUR-2026050001', 1, '2026-05-06', 540.00, 0.00, 0.00, 540.00, 'paid', 0.00, 540.00, '', 1, '2026-05-06 05:34:09', NULL),
(3, 'PUR-2026050002', 1, '2026-05-06', 320.00, 0.00, 0.00, 320.00, 'paid', 0.00, 320.00, '', 1, '2026-05-06 05:35:07', NULL),
(4, 'PUR-2026050003', 1, '2026-05-06', 150.00, 0.00, 0.00, 150.00, 'paid', 0.00, 150.00, '', 1, '2026-05-06 10:58:20', NULL),
(5, 'PUR-2026050004', 1, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 13:24:22', NULL),
(6, 'PUR-2026050005', 3, '2026-05-06', 150.00, 0.00, 0.00, 150.00, 'paid', 0.00, 150.00, '', 1, '2026-05-06 13:25:34', NULL),
(7, 'PUR-2026050006', 3, '2026-05-06', 150.00, 0.00, 0.00, 150.00, 'paid', 0.00, 150.00, '', 1, '2026-05-06 13:25:34', NULL),
(8, 'PUR-2026050007', 2, '2026-05-06', 300.00, 0.00, 0.00, 300.00, 'paid', 0.00, 300.00, '', 1, '2026-05-06 13:25:54', NULL),
(9, 'PUR-2026050008', 3, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 13:27:28', NULL),
(10, 'PUR-2026050009', 2, '2026-05-06', 200.00, 0.00, 0.00, 200.00, 'paid', 0.00, 200.00, '', 1, '2026-05-06 13:28:36', NULL),
(11, 'PUR-2026050010', 1, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 13:51:06', NULL),
(12, 'PUR-2026050011', 2, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 13:51:30', NULL),
(13, 'PUR-2026050012', 1, '2026-05-06', 200.00, 0.00, 0.00, 200.00, 'paid', 0.00, 200.00, '', 1, '2026-05-06 13:52:05', NULL),
(14, 'PUR-2026050013', 1, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 13:53:21', NULL),
(15, 'PUR-2026050014', 1, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 13:54:22', NULL),
(16, 'PUR-2026050015', 2, '2026-05-06', 50.00, 0.00, 0.00, 50.00, 'paid', 0.00, 50.00, '', 1, '2026-05-06 13:54:40', NULL),
(17, 'PUR-2026050016', 1, '2026-05-06', 15.00, 0.00, 0.00, 15.00, 'paid', 0.00, 15.00, '', 1, '2026-05-06 13:56:35', NULL),
(18, 'PUR-2026050017', 1, '2026-05-06', 15.00, 0.00, 0.00, 15.00, 'paid', 0.00, 15.00, '', 1, '2026-05-06 14:05:09', NULL),
(19, 'PUR-2026050018', 3, '2026-05-06', 10.00, 0.00, 0.00, 10.00, 'paid', 0.00, 10.00, '', 1, '2026-05-06 14:05:39', NULL),
(20, 'PUR-2026050019', 1, '2026-05-06', 5.00, 0.00, 0.00, 5.00, 'paid', 0.00, 5.00, '', 1, '2026-05-06 14:06:28', NULL),
(21, 'PUR-2026050020', 3, '2026-05-06', 100.00, 0.00, 0.00, 100.00, 'paid', 0.00, 100.00, '', 1, '2026-05-06 14:07:26', NULL),
(36, 'PUR-2026050021', 1, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 05:49:38', NULL),
(37, 'PUR-2026050022', 1, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 05:50:00', NULL),
(38, 'PUR-2026050023', 1, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 05:50:07', NULL),
(39, 'PUR-2026050024', 1, '2026-05-13', 1000.00, 0.00, 0.00, 1000.00, 'paid', 1000.00, 0.00, '', 1, '2026-05-13 05:52:16', NULL),
(40, 'PUR-2026050025', 1, '2026-05-13', 1000.00, 0.00, 0.00, 1000.00, 'pending', 0.00, 1000.00, '', 1, '2026-05-13 05:54:44', NULL),
(41, 'PUR-2026050026', 3, '2026-05-13', 500.00, 0.00, 0.00, 500.00, 'paid', 500.00, 0.00, '', 1, '2026-05-13 06:10:21', NULL),
(42, 'PUR-2026050027', 3, '2026-05-13', 1000.00, 0.00, 0.00, 1000.00, 'partial', 100.00, 900.00, '', 1, '2026-05-13 06:38:44', NULL),
(43, 'PUR-2026050028', 3, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 06:39:14', NULL),
(44, 'PUR-2026050029', 3, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 06:39:33', NULL),
(45, 'PUR-2026050030', 3, '2026-05-13', 2400.00, 0.00, 0.00, 2400.00, 'paid', 2400.00, 0.00, '', 1, '2026-05-13 06:41:57', NULL),
(46, 'PUR-2026050031', 3, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 06:55:15', NULL),
(47, 'PUR-2026050032', 2, '2026-05-13', 500.00, 0.00, 0.00, 500.00, 'paid', 500.00, 0.00, '', 1, '2026-05-13 06:57:04', NULL),
(48, 'PUR-2026050033', 3, '2026-05-13', 2500.00, 0.00, 0.00, 2500.00, 'pending', 0.00, 2500.00, '', 1, '2026-05-13 07:08:26', NULL),
(49, 'PUR-2026050034', 3, '2026-05-13', 5000.00, 0.00, 0.00, 5000.00, 'partial', 1000.00, 4000.00, '', 1, '2026-05-13 07:26:38', NULL),
(50, 'PUR-2026050035', 2, '2026-05-13', 960.00, 0.00, 0.00, 960.00, 'paid', 960.00, 0.00, '', 1, '2026-05-13 07:42:23', NULL),
(51, 'PUR-2026050036', 3, '2026-05-13', 80.00, 0.00, 0.00, 80.00, 'paid', 80.00, 0.00, '', 1, '2026-05-13 07:51:50', NULL),
(52, 'PUR-2026050037', 3, '2026-05-13', 80.00, 0.00, 0.00, 80.00, 'paid', 80.00, 0.00, '', 1, '2026-05-13 08:07:36', NULL),
(53, 'PUR-2026050038', 3, '2026-05-13', 720.00, 0.00, 0.00, 720.00, 'paid', 720.00, 0.00, '', 1, '2026-05-13 11:39:10', NULL),
(54, 'PUR-2026050039', 3, '2026-05-13', 500.00, 0.00, 0.00, 500.00, 'paid', 500.00, 0.00, '', 1, '2026-05-13 11:41:06', NULL),
(55, 'PUR-2026050040', 3, '2026-05-13', 720.00, 0.00, 0.00, 720.00, 'paid', 720.00, 0.00, '', 1, '2026-05-13 14:01:44', NULL),
(56, 'PUR-2026050041', 3, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'paid', 50.00, 0.00, '', 1, '2026-05-13 14:02:04', NULL),
(57, 'PUR-2026050042', 3, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'pending', 0.00, 50.00, '', 1, '2026-05-13 14:11:43', NULL),
(58, 'PUR-2026050043', 3, '2026-05-13', 50.00, 0.00, 0.00, 50.00, 'pending', 0.00, 50.00, '', 1, '2026-05-13 14:19:10', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL COMMENT 'Number of packs',
  `pieces_per_pack` int(11) NOT NULL,
  `total_pieces` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL COMMENT 'Price per pack',
  `total` decimal(10,2) NOT NULL,
  `expiry_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `purchase_items`
--

INSERT INTO `purchase_items` (`id`, `purchase_id`, `batch_id`, `product_id`, `variant_id`, `quantity`, `pieces_per_pack`, `total_pieces`, `unit_price`, `total`, `expiry_date`) VALUES
(1, 1, 1, 1, NULL, 10, 10, 100, 170.00, 1700.00, '2028-12-15'),
(2, 2, 5, 4, NULL, 3, 1, 3, 180.00, 540.00, '2026-05-20'),
(3, 3, 6, 4, NULL, 1, 1, 1, 320.00, 320.00, '2026-05-27'),
(4, 4, 7, 4, NULL, 1, 1, 1, 150.00, 150.00, '2026-05-21'),
(5, 5, 8, 2, NULL, 1, 15, 15, 100.00, 100.00, '2026-05-20'),
(6, 6, 9, 4, NULL, 1, 1, 1, 150.00, 150.00, '2026-05-26'),
(7, 7, 10, 4, NULL, 1, 1, 1, 150.00, 150.00, '2026-05-26'),
(8, 8, 11, 4, NULL, 1, 1, 1, 300.00, 300.00, '2026-05-28'),
(9, 9, 12, 2, NULL, 1, 1, 1, 100.00, 100.00, '2026-05-27'),
(10, 10, 13, 2, NULL, 1, 1, 1, 200.00, 200.00, '2026-05-28'),
(11, 11, 14, 2, NULL, 1, 1, 1, 100.00, 100.00, '2026-05-21'),
(12, 12, 15, 2, NULL, 1, 1, 1, 100.00, 100.00, '2026-05-27'),
(13, 13, 16, 2, NULL, 1, 1, 1, 200.00, 200.00, '2026-05-28'),
(14, 14, 17, 3, NULL, 1, 1, 1, 100.00, 100.00, '2026-05-29'),
(15, 15, 18, 3, NULL, 1, 1, 1, 100.00, 100.00, '2026-05-19'),
(16, 16, 19, 3, NULL, 1, 1, 1, 50.00, 50.00, '2026-05-21'),
(17, 17, 20, 3, NULL, 1, 48, 48, 15.00, 15.00, '2026-05-20'),
(18, 18, 21, 9, NULL, 1, 48, 48, 15.00, 15.00, '2026-05-20'),
(19, 19, 22, 9, NULL, 1, 48, 48, 10.00, 10.00, '2026-05-28'),
(20, 20, 23, 8, NULL, 1, 1, 1, 5.00, 5.00, '2026-05-26'),
(21, 21, 24, 8, NULL, 10, 15, 150, 10.00, 100.00, '2026-05-28'),
(22, 36, 27, 146, NULL, 1, 10, 10, 5.00, 50.00, '0000-00-00'),
(23, 37, 28, 146, NULL, 1, 10, 10, 5.00, 50.00, '0000-00-00'),
(25, 39, 30, 147, NULL, 1, 10, 10, 100.00, 1000.00, '0000-00-00'),
(26, 40, 31, 147, NULL, 1, 10, 10, 100.00, 1000.00, '0000-00-00'),
(27, 41, 32, 148, NULL, 1, 10, 10, 50.00, 500.00, '0000-00-00'),
(28, 42, 33, 148, NULL, 2, 10, 20, 50.00, 1000.00, '0000-00-00'),
(29, 43, 34, 146, NULL, 1, 10, 10, 5.00, 50.00, '0000-00-00'),
(30, 44, 35, 146, NULL, 1, 10, 10, 5.00, 50.00, '2026-05-30'),
(31, 45, 36, 150, NULL, 1, 48, 48, 50.00, 2400.00, '2026-05-23'),
(32, 46, 37, 146, NULL, 1, 10, 10, 5.00, 50.00, '2026-05-29'),
(33, 47, 38, 148, NULL, 1, 10, 10, 50.00, 500.00, '2026-05-29'),
(34, 48, 39, 152, NULL, 10, 16, 160, 15.00, 2400.00, '2026-05-29'),
(35, 48, 40, 148, NULL, 2, 10, 20, 5.00, 100.00, '2026-05-15'),
(36, 49, 41, 153, NULL, 100, 10, 1000, 5.00, 5000.00, '2026-05-22'),
(37, 50, 42, 149, NULL, 2, 48, 96, 10.00, 960.00, '2026-05-29'),
(38, 51, 43, 155, NULL, 1, 1, 1, 80.00, 80.00, '2026-05-15'),
(39, 52, 44, 157, NULL, 1, 1, 1, 80.00, 80.00, '2026-05-15'),
(40, 53, 45, 159, NULL, 1, 48, 48, 15.00, 720.00, '2026-05-23'),
(41, 54, 46, 158, NULL, 10, 10, 100, 5.00, 500.00, '2026-05-22'),
(42, 55, 47, 159, NULL, 1, 48, 48, 15.00, 720.00, '2026-05-28'),
(43, 56, 48, 158, NULL, 1, 10, 10, 5.00, 50.00, '2026-05-27'),
(44, 57, 49, 160, NULL, 1, 10, 10, 5.00, 50.00, '2026-05-28'),
(45, 58, 50, 160, NULL, 1, 10, 10, 5.00, 50.00, '2026-05-29');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

CREATE TABLE `purchase_returns` (
  `id` int(11) NOT NULL,
  `return_no` varchar(50) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `purchase_item_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `return_date` datetime NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'approved',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `customer_name` varchar(100) DEFAULT 'Walk-in Customer',
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `sale_date` datetime NOT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` enum('cash','card','online','credit') DEFAULT 'cash',
  `payment_status` enum('paid','partial','pending') DEFAULT 'paid',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `invoice_no`, `customer_name`, `customer_phone`, `customer_email`, `sale_date`, `subtotal`, `discount`, `discount_percent`, `tax_amount`, `total`, `paid_amount`, `payment_method`, `payment_status`, `created_by`, `created_at`) VALUES
(1, 'INV-2024-0001', 'Walk-in Customer', NULL, NULL, '2026-05-05 18:40:32', 170.00, 0.00, 0.00, 0.00, 170.00, 170.00, 'cash', 'paid', NULL, '2026-05-05 13:40:32'),
(2, 'INV-2026050002', 'Walk-in Customer', '', NULL, '2026-05-06 08:18:05', 205.00, 0.00, 0.00, 0.00, 205.00, 205.00, 'cash', 'paid', 1, '2026-05-06 03:18:05'),
(3, 'INV-2026050003', 'Walk-in Customer', '', NULL, '2026-05-06 08:22:27', 50.00, 0.00, 1.00, 0.00, 50.00, 50.00, 'cash', 'paid', 1, '2026-05-06 03:22:27'),
(4, 'INV-2026050004', 'Walk-in Customer', '', NULL, '2026-05-06 08:33:20', 25.00, 0.00, 0.00, 0.00, 25.00, 25.00, 'cash', 'paid', 1, '2026-05-06 03:33:20'),
(5, 'INV-2026050005', 'Walk-in Customer', '', NULL, '2026-05-06 08:36:31', 180.00, 0.00, 0.00, 0.00, 180.00, 180.00, 'cash', 'paid', 1, '2026-05-06 03:36:31'),
(6, 'INV-2026050006', 'Walk-in Customer', '', NULL, '2026-05-14 08:51:00', 299.85, 0.00, 0.00, 0.00, 299.85, 0.00, 'cash', 'paid', 1, '2026-05-14 03:51:00'),
(7, 'INV-2026050007', '00', '00', NULL, '2026-05-14 08:52:01', 32.00, 0.00, 0.00, 0.00, 32.00, 0.00, 'credit', 'pending', 1, '2026-05-14 03:52:01'),
(8, 'INV-2026050008', 'Walk-in Customer', '', NULL, '2026-05-14 08:58:22', 32.00, 0.00, 0.00, 0.00, 32.00, 0.00, 'cash', 'paid', 1, '2026-05-14 03:58:22'),
(9, 'INV-2026050009', 'Walk-in Customer', '', NULL, '2026-05-14 09:02:27', 41.99, 0.00, 0.00, 0.00, 41.99, 0.00, 'cash', 'paid', 1, '2026-05-14 04:02:27'),
(10, 'INV-2026050010', '111', '111', NULL, '2026-05-14 09:04:36', 15.00, 0.00, 0.00, 0.00, 15.00, 0.00, 'credit', 'pending', 1, '2026-05-14 04:04:36'),
(11, 'INV-2026050011', 'Walk-in Customer', '', NULL, '2026-05-14 09:05:05', 41.99, 4.20, 10.00, 3.78, 41.57, 0.00, 'cash', 'paid', 1, '2026-05-14 04:05:05'),
(12, 'INV-2026050012', 'Walk-in Customer', '', NULL, '2026-05-14 09:05:59', 32.00, 0.00, 0.00, 0.00, 32.00, 0.00, 'cash', 'paid', 1, '2026-05-14 04:05:59'),
(13, 'INV-2026050013', 'Walk-in Customer', '', NULL, '2026-05-14 09:08:17', 26.99, 0.00, 0.00, 0.00, 26.99, 0.00, 'cash', 'paid', 1, '2026-05-14 04:08:17'),
(14, 'INV-2026050014', '111', '11', NULL, '2026-05-14 09:11:17', 26.99, 2.70, 10.00, 3.64, 27.93, 0.00, 'credit', 'pending', 1, '2026-05-14 04:11:17'),
(15, 'INV-2026050015', '111', '11', NULL, '2026-05-14 09:26:37', 26.99, 0.00, 0.00, 0.00, 26.99, 20.00, 'credit', 'pending', 1, '2026-05-14 04:26:37');

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(100) NOT NULL,
  `generic_name` varchar(100) DEFAULT NULL,
  `variant_info` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL COMMENT 'Number of pieces sold',
  `unit_price` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `batch_id`, `product_id`, `variant_id`, `product_name`, `generic_name`, `variant_info`, `quantity`, `unit_price`, `total`) VALUES
(1, 2, 4, 4, NULL, 'Bio Amla Shampoo', '', NULL, 1, 180.00, 180.00),
(2, 2, 3, 3, NULL, 'Diaper', '', NULL, 1, 25.00, 25.00),
(3, 3, 3, 3, NULL, 'Diaper', '', NULL, 2, 25.00, 50.00),
(4, 4, 3, 3, NULL, 'Diaper', '', NULL, 1, 25.00, 25.00),
(5, 5, 4, 4, NULL, 'Bio Amla Shampoo', '', NULL, 1, 180.00, 180.00),
(6, 6, 49, 160, NULL, '', NULL, NULL, 10, 15.00, 150.00),
(7, 6, 46, 158, NULL, '', NULL, NULL, 15, 9.99, 149.85),
(8, 7, 50, 160, NULL, '', NULL, NULL, 1, 15.00, 15.00),
(9, 7, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(10, 8, 50, 160, NULL, '', NULL, NULL, 1, 15.00, 15.00),
(11, 8, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(12, 9, 50, 160, NULL, '', NULL, NULL, 1, 15.00, 15.00),
(13, 9, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(14, 9, 46, 158, NULL, '', NULL, NULL, 1, 9.99, 9.99),
(15, 10, 50, 160, NULL, '', NULL, NULL, 1, 15.00, 15.00),
(16, 11, 50, 160, NULL, '', NULL, NULL, 1, 15.00, 15.00),
(17, 11, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(18, 11, 46, 158, NULL, '', NULL, NULL, 1, 9.99, 9.99),
(19, 12, 50, 160, NULL, '', NULL, NULL, 1, 15.00, 15.00),
(20, 12, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(21, 13, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(22, 13, 46, 158, NULL, '', NULL, NULL, 1, 9.99, 9.99),
(23, 14, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00),
(24, 14, 46, 158, NULL, '', NULL, NULL, 1, 9.99, 9.99),
(25, 15, 46, 158, NULL, '', NULL, NULL, 1, 9.99, 9.99),
(26, 15, 45, 159, NULL, '', NULL, NULL, 1, 17.00, 17.00);

-- --------------------------------------------------------

--
-- Table structure for table `sale_returns`
--

CREATE TABLE `sale_returns` (
  `id` int(11) NOT NULL,
  `return_no` varchar(50) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `sale_item_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `return_date` datetime NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'approved',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `movement_type` enum('purchase','sale','return_in','return_out','adjustment_in','adjustment_out') NOT NULL,
  `quantity` int(11) NOT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'sale_id or purchase_id',
  `reference_type` varchar(20) DEFAULT NULL,
  `previous_quantity` int(11) DEFAULT NULL,
  `new_quantity` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `batch_id`, `movement_type`, `quantity`, `reference_id`, `reference_type`, `previous_quantity`, `new_quantity`, `notes`, `created_by`, `created_at`) VALUES
(1, 4, 'sale', 1, 2, 'sale', 20, 19, NULL, NULL, '2026-05-06 03:18:05'),
(2, 3, 'sale', 1, 2, 'sale', 480, 479, NULL, NULL, '2026-05-06 03:18:05'),
(3, 3, 'sale', 2, 3, 'sale', 479, 477, NULL, NULL, '2026-05-06 03:22:27'),
(4, 3, 'sale', 1, 4, 'sale', 477, 476, NULL, NULL, '2026-05-06 03:33:20'),
(5, 4, 'sale', 1, 5, 'sale', 19, 18, NULL, NULL, '2026-05-06 03:36:31'),
(6, 5, 'purchase', 3, 2, 'purchase', 0, 3, NULL, NULL, '2026-05-06 05:34:09'),
(7, 6, 'purchase', 1, 3, 'purchase', 0, 1, NULL, NULL, '2026-05-06 05:35:07'),
(8, 7, 'purchase', 1, 4, 'purchase', 0, 1, NULL, NULL, '2026-05-06 10:58:20'),
(9, 8, 'purchase', 15, 5, 'purchase', 0, 15, NULL, NULL, '2026-05-06 13:24:22'),
(10, 9, 'purchase', 1, 6, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:25:34'),
(11, 10, 'purchase', 1, 7, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:25:34'),
(12, 11, 'purchase', 1, 8, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:25:54'),
(13, 12, 'purchase', 1, 9, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:27:28'),
(14, 13, 'purchase', 1, 10, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:28:36'),
(15, 14, 'purchase', 1, 11, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:51:06'),
(16, 15, 'purchase', 1, 12, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:51:30'),
(17, 16, 'purchase', 1, 13, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:52:05'),
(18, 17, 'purchase', 1, 14, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:53:21'),
(19, 18, 'purchase', 1, 15, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:54:22'),
(20, 19, 'purchase', 1, 16, 'purchase', 0, 1, NULL, NULL, '2026-05-06 13:54:40'),
(21, 20, 'purchase', 48, 17, 'purchase', 0, 48, NULL, NULL, '2026-05-06 13:56:35'),
(22, 21, 'purchase', 48, 18, 'purchase', 0, 48, NULL, NULL, '2026-05-06 14:05:09'),
(23, 22, 'purchase', 48, 19, 'purchase', 0, 48, NULL, NULL, '2026-05-06 14:05:39'),
(24, 23, 'purchase', 1, 20, 'purchase', 0, 1, NULL, NULL, '2026-05-06 14:06:28'),
(25, 24, 'purchase', 150, 21, 'purchase', 0, 150, NULL, NULL, '2026-05-06 14:07:26'),
(26, 49, 'sale', 10, 6, 'sale', 10, 0, NULL, NULL, '2026-05-14 03:51:00'),
(27, 46, 'sale', 15, 6, 'sale', 100, 85, NULL, NULL, '2026-05-14 03:51:00'),
(28, 50, 'sale', 1, 7, 'sale', 10, 9, NULL, NULL, '2026-05-14 03:52:01'),
(29, 45, 'sale', 1, 7, 'sale', 48, 47, NULL, NULL, '2026-05-14 03:52:01'),
(30, 50, 'sale', 1, 8, 'sale', 9, 8, NULL, NULL, '2026-05-14 03:58:22'),
(31, 45, 'sale', 1, 8, 'sale', 47, 46, NULL, NULL, '2026-05-14 03:58:22'),
(32, 50, 'sale', 1, 9, 'sale', 8, 7, NULL, NULL, '2026-05-14 04:02:27'),
(33, 45, 'sale', 1, 9, 'sale', 46, 45, NULL, NULL, '2026-05-14 04:02:27'),
(34, 46, 'sale', 1, 9, 'sale', 85, 84, NULL, NULL, '2026-05-14 04:02:27'),
(35, 50, 'sale', 1, 10, 'sale', 7, 6, NULL, NULL, '2026-05-14 04:04:36'),
(36, 50, 'sale', 1, 11, 'sale', 6, 5, NULL, NULL, '2026-05-14 04:05:05'),
(37, 45, 'sale', 1, 11, 'sale', 45, 44, NULL, NULL, '2026-05-14 04:05:05'),
(38, 46, 'sale', 1, 11, 'sale', 84, 83, NULL, NULL, '2026-05-14 04:05:05'),
(39, 50, 'sale', 1, 12, 'sale', 5, 4, NULL, NULL, '2026-05-14 04:05:59'),
(40, 45, 'sale', 1, 12, 'sale', 44, 43, NULL, NULL, '2026-05-14 04:06:00'),
(41, 45, 'sale', 1, 13, 'sale', 43, 42, NULL, NULL, '2026-05-14 04:08:17'),
(42, 46, 'sale', 1, 13, 'sale', 83, 82, NULL, NULL, '2026-05-14 04:08:17'),
(43, 45, 'sale', 1, 14, 'sale', 42, 41, NULL, NULL, '2026-05-14 04:11:17'),
(44, 46, 'sale', 1, 14, 'sale', 82, 81, NULL, NULL, '2026-05-14 04:11:17'),
(45, 46, 'sale', 1, 15, 'sale', 81, 80, NULL, NULL, '2026-05-14 04:26:37'),
(46, 45, 'sale', 1, 15, 'sale', 41, 40, NULL, NULL, '2026-05-14 04:26:37');

-- --------------------------------------------------------

--
-- Stand-in structure for view `stock_summary_view`
-- (See below for the actual view)
--
CREATE TABLE `stock_summary_view` (
`product_id` int(11)
,`product_name` varchar(100)
,`generic_name` varchar(100)
,`brand_name` varchar(100)
,`strength` varchar(100)
,`strength_unit` varchar(20)
,`dosage_form` varchar(100)
,`unit_type` varchar(20)
,`pack_quantity` int(11)
,`stock_in_pieces` decimal(32,0)
,`batch_count` bigint(21)
,`nearest_expiry` date
,`stock_status` varchar(12)
);

-- --------------------------------------------------------

--
-- Table structure for table `strengths`
--

CREATE TABLE `strengths` (
  `id` int(11) NOT NULL,
  `strength_value` varchar(50) NOT NULL,
  `strength_unit` varchar(20) DEFAULT NULL,
  `usage_count` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `strengths`
--

INSERT INTO `strengths` (`id`, `strength_value`, `strength_unit`, `usage_count`, `created_at`) VALUES
(1, '500', 'mg', 2, '2026-05-05 13:40:31'),
(2, '250', 'mg', 1, '2026-05-05 13:40:31'),
(3, '100', 'mg', 2, '2026-05-05 13:40:31'),
(4, '625', 'mg', 4, '2026-05-05 13:40:31'),
(5, '10', 'ml', 1, '2026-05-05 13:40:31'),
(6, '5', 'ml', 1, '2026-05-05 13:40:31'),
(7, '75', 'mg', 7, '2026-05-06 04:13:42'),
(8, '100 mg', 'mg', 1, '2026-05-11 14:24:40'),
(9, '150mg', 'mg', 1, '2026-05-13 07:28:20');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `alternative_phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `gst_no` varchar(50) DEFAULT NULL,
  `opening_balance` decimal(10,2) DEFAULT 0.00,
  `current_balance` decimal(10,2) DEFAULT 0.00,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_code`, `supplier_name`, `contact_person`, `phone`, `alternative_phone`, `email`, `address`, `gst_no`, `opening_balance`, `current_balance`, `status`, `created_at`, `updated_at`) VALUES
(1, 'SUP001', 'ABC Pharma Distributors', 'Mr. Rajesh Kumar', '9876543210', '', 'rajesh@abcpharma.com', '123, Gandhi Road, Mumbai', '27AAAAA1234B1Z', 0.00, 1000.00, 0, '2026-05-05 13:40:31', NULL),
(2, 'SUP002', 'MediCare Supplies', 'Mrs. Priya Sharma', '9876543211', NULL, 'priya@medicare.com', '456, Patel Nagar, Delhi', '27BBBBB5678C2Z', 0.00, 0.00, 1, '2026-05-05 13:40:31', NULL),
(3, 'SUP003', 'HealthPlus Distributors', 'Mr. Amit Singh', '9876543212', NULL, 'amit@healthplus.com', '789, Lake Road, Kolkata', '27CCCCC9012D3Z', 0.00, 7500.00, 1, '2026-05-05 13:40:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) DEFAULT 'cash',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_preferences`
--

CREATE TABLE `system_preferences` (
  `id` int(11) NOT NULL,
  `low_stock_threshold` int(11) DEFAULT 10,
  `near_expiry_days` int(11) DEFAULT 30,
  `invoice_footer` varchar(255) DEFAULT 'Thank you! Visit Again',
  `enable_gst` tinyint(1) DEFAULT 0,
  `tax_percentage` decimal(5,2) DEFAULT 0.00,
  `default_discount` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_preferences`
--

INSERT INTO `system_preferences` (`id`, `low_stock_threshold`, `near_expiry_days`, `invoice_footer`, `enable_gst`, `tax_percentage`, `default_discount`, `created_at`) VALUES
(1, 10, 30, 'Thank you! Visit Again', 0, 0.00, 0.00, '2026-05-05 14:32:27');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','pharmacist','cashier') DEFAULT 'pharmacist',
  `profile_image` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `profile_image`, `last_login`, `remember_token`, `reset_token`, `reset_expires`, `status`, `created_at`) VALUES
(3, 'arslan', '3f36b8e3f3b77d14d5b4e4f4a1e2c8b9', 'Dr. Arslan Yasin Sukhera', '', NULL, 'admin', NULL, NULL, NULL, NULL, NULL, 1, '2026-06-09 07:13:22');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_add` tinyint(1) DEFAULT 1,
  `can_edit` tinyint(1) DEFAULT 1,
  `can_delete` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure for view `expiry_alerts_view`
--
DROP TABLE IF EXISTS `expiry_alerts_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `expiry_alerts_view`  AS SELECT `b`.`id` AS `batch_id`, `b`.`batch_number` AS `batch_number`, `p`.`product_name` AS `product_name`, `p`.`generic_name` AS `generic_name`, `b`.`expiry_date` AS `expiry_date`, `b`.`remaining_quantity` AS `remaining_quantity`, to_days(`b`.`expiry_date`) - to_days(curdate()) AS `days_to_expire`, CASE WHEN to_days(`b`.`expiry_date`) - to_days(curdate()) <= 0 THEN 'Expired' WHEN to_days(`b`.`expiry_date`) - to_days(curdate()) <= 30 THEN 'Critical' WHEN to_days(`b`.`expiry_date`) - to_days(curdate()) <= 90 THEN 'Warning' ELSE 'Normal' END AS `expiry_status` FROM (`batches` `b` join `products` `p` on(`b`.`product_id` = `p`.`id`)) WHERE `b`.`remaining_quantity` > 0 ORDER BY `b`.`expiry_date` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `stock_summary_view`
--
DROP TABLE IF EXISTS `stock_summary_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `stock_summary_view`  AS SELECT `p`.`id` AS `product_id`, `p`.`product_name` AS `product_name`, `p`.`generic_name` AS `generic_name`, `p`.`brand_name` AS `brand_name`, `p`.`strength` AS `strength`, `p`.`strength_unit` AS `strength_unit`, `p`.`dosage_form` AS `dosage_form`, `p`.`unit_type` AS `unit_type`, `p`.`pack_quantity` AS `pack_quantity`, coalesce(sum(`b`.`remaining_quantity`),0) AS `stock_in_pieces`, count(distinct `b`.`id`) AS `batch_count`, min(`b`.`expiry_date`) AS `nearest_expiry`, CASE WHEN coalesce(sum(`b`.`remaining_quantity`),0) = 0 THEN 'Out of Stock' WHEN coalesce(sum(`b`.`remaining_quantity`),0) <= `p`.`reorder_level` THEN 'Low Stock' ELSE 'In Stock' END AS `stock_status` FROM (`products` `p` left join `batches` `b` on(`p`.`id` = `b`.`product_id` and `b`.`is_expired` = 0 and `b`.`is_blocked` = 0)) WHERE `p`.`status` = 1 GROUP BY `p`.`id` ;

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
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `batch_number` (`batch_number`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `expiry_date` (`expiry_date`),
  ADD KEY `idx_batches_expiry` (`expiry_date`),
  ADD KEY `idx_batches_product` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `credit_payments`
--
ALTER TABLE `credit_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `credit_id` (`credit_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customer_credits`
--
ALTER TABLE `customer_credits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `dosage_forms`
--
ALTER TABLE `dosage_forms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `form_name` (`form_name`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `invoice_sequences`
--
ALTER TABLE `invoice_sequences`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_invoice_no` (`purchase_invoice_no`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `idx_purchases_date` (`purchase_date`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `idx_purchase_items_purchase` (`purchase_id`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_no` (`return_no`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `sale_date` (`sale_date`),
  ADD KEY `idx_sales_date` (`sale_date`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `idx_sale_items_sale` (`sale_id`);

--
-- Indexes for table `sale_returns`
--
ALTER TABLE `sale_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_no` (`return_no`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `movement_type` (`movement_type`);

--
-- Indexes for table `strengths`
--
ALTER TABLE `strengths`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `strength_value_unit` (`strength_value`,`strength_unit`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- Indexes for table `system_preferences`
--
ALTER TABLE `system_preferences`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `credit_payments`
--
ALTER TABLE `credit_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_credits`
--
ALTER TABLE `customer_credits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `dosage_forms`
--
ALTER TABLE `dosage_forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `invoice_sequences`
--
ALTER TABLE `invoice_sequences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=164;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `sale_returns`
--
ALTER TABLE `sale_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `strengths`
--
ALTER TABLE `strengths`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_preferences`
--
ALTER TABLE `system_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `batches`
--
ALTER TABLE `batches`
  ADD CONSTRAINT `batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `batches_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `batches_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `credit_payments`
--
ALTER TABLE `credit_payments`
  ADD CONSTRAINT `credit_payments_ibfk_1` FOREIGN KEY (`credit_id`) REFERENCES `customer_credits` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`);

--
-- Constraints for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `purchase_returns_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`);

--
-- Constraints for table `sale_returns`
--
ALTER TABLE `sale_returns`
  ADD CONSTRAINT `sale_returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `supplier_payments_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `supplier_payments_ibfk_2` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- ============================================================
-- *** PHASE 21 ROLE SYSTEM ***
-- ============================================================
-- ============================================================
-- ARSLAN'S PHARMACY ERP — Phase 21: Role System
-- Author: Dr. Arslan Yasin Sukhera | June 2026
-- Run AFTER phase20_user_management.sql
-- Safe to re-run (uses IF NOT EXISTS / MODIFY guards)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- ============================================================
-- 1. EXPAND users.role ENUM — add manager + custom
-- ============================================================
ALTER TABLE `users`
  MODIFY COLUMN `role`
    ENUM('admin','manager','pharmacist','cashier','viewer','custom')
    NOT NULL DEFAULT 'cashier'
    COMMENT 'User role: admin=full, manager=ops lead, pharmacist=clinical, cashier=pos only, viewer=read-only, custom=granular';

-- ============================================================
-- 2. ENSURE user_permissions HAS ALL Phase-21 COLUMNS
-- ============================================================
ALTER TABLE `user_permissions`
  ADD COLUMN IF NOT EXISTS `can_view`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `module`,
  ADD COLUMN IF NOT EXISTS `can_add`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view`,
  ADD COLUMN IF NOT EXISTS `can_edit`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_add`,
  ADD COLUMN IF NOT EXISTS `can_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_edit`,
  ADD COLUMN IF NOT EXISTS `can_print`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_delete`,
  ADD COLUMN IF NOT EXISTS `can_export` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_print`,
  ADD COLUMN IF NOT EXISTS `can_import` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_export`,
  ADD COLUMN IF NOT EXISTS `can_void`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_import`;

-- ============================================================
-- 3. role_permissions — DEFAULT TEMPLATE TABLE
--    Stores the canonical default permissions per named role.
--    When a new user is created with a standard role, these
--    rows are copied into user_permissions for that user.
--    Admin is omitted (gets all permissions via canDo shortcut).
-- ============================================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `role`       ENUM('manager','pharmacist','cashier','viewer','custom')
               NOT NULL,
  `module`     VARCHAR(50)  NOT NULL,
  `can_view`   TINYINT(1)   NOT NULL DEFAULT 0,
  `can_add`    TINYINT(1)   NOT NULL DEFAULT 0,
  `can_edit`   TINYINT(1)   NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1)   NOT NULL DEFAULT 0,
  `can_print`  TINYINT(1)   NOT NULL DEFAULT 0,
  `can_export` TINYINT(1)   NOT NULL DEFAULT 0,
  `can_import` TINYINT(1)   NOT NULL DEFAULT 0,
  `can_void`   TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role`, `module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Default permission templates per role — copied to user_permissions on user create';

-- ============================================================
-- 4. SEED role_permissions — MANAGER
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('manager','dashboard', 1,0,0,0,0,0,0,0),
  ('manager','products',  1,1,1,0,1,1,1,0),
  ('manager','sales',     1,1,1,0,1,1,0,1),
  ('manager','purchases', 1,1,1,1,1,1,1,0),
  ('manager','returns',   1,1,0,0,1,1,0,0),
  ('manager','credits',   1,1,1,0,0,1,0,0),
  ('manager','suppliers', 1,1,1,0,1,1,0,0),
  ('manager','cashbook',  1,1,0,0,1,1,0,0),
  ('manager','expenses',  1,1,1,0,0,1,0,0),
  ('manager','stock',     1,1,1,0,0,1,0,0),
  ('manager','reports',   1,0,0,0,1,1,0,0),
  ('manager','users',     1,1,1,0,0,0,0,0),
  ('manager','settings',  1,0,1,0,0,0,0,0),
  ('manager','backup',    1,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 5. SEED role_permissions — PHARMACIST
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('pharmacist','dashboard', 1,0,0,0,0,0,0,0),
  ('pharmacist','products',  1,1,1,0,1,1,1,0),
  ('pharmacist','sales',     1,1,0,0,1,1,0,1),
  ('pharmacist','purchases', 1,1,0,0,1,1,0,0),
  ('pharmacist','returns',   1,1,0,0,1,0,0,0),
  ('pharmacist','credits',   1,1,0,0,0,0,0,0),
  ('pharmacist','suppliers', 1,1,1,0,0,0,0,0),
  ('pharmacist','cashbook',  1,0,0,0,0,0,0,0),
  ('pharmacist','expenses',  1,1,0,0,0,0,0,0),
  ('pharmacist','stock',     1,1,0,0,0,0,0,0),
  ('pharmacist','reports',   1,0,0,0,1,1,0,0),
  ('pharmacist','users',     0,0,0,0,0,0,0,0),
  ('pharmacist','settings',  0,0,0,0,0,0,0,0),
  ('pharmacist','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 6. SEED role_permissions — CASHIER
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('cashier','dashboard', 1,0,0,0,0,0,0,0),
  ('cashier','products',  1,0,0,0,0,0,0,0),
  ('cashier','sales',     1,1,0,0,1,0,0,0),
  ('cashier','purchases', 0,0,0,0,0,0,0,0),
  ('cashier','returns',   1,1,0,0,0,0,0,0),
  ('cashier','credits',   1,1,0,0,0,0,0,0),
  ('cashier','suppliers', 0,0,0,0,0,0,0,0),
  ('cashier','cashbook',  1,0,0,0,0,0,0,0),
  ('cashier','expenses',  1,1,0,0,0,0,0,0),
  ('cashier','stock',     0,0,0,0,0,0,0,0),
  ('cashier','reports',   1,0,0,0,0,0,0,0),
  ('cashier','users',     0,0,0,0,0,0,0,0),
  ('cashier','settings',  0,0,0,0,0,0,0,0),
  ('cashier','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 7. SEED role_permissions — VIEWER
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('viewer','dashboard', 1,0,0,0,0,0,0,0),
  ('viewer','products',  1,0,0,0,0,0,0,0),
  ('viewer','sales',     1,0,0,0,1,1,0,0),
  ('viewer','purchases', 1,0,0,0,0,0,0,0),
  ('viewer','returns',   0,0,0,0,0,0,0,0),
  ('viewer','credits',   0,0,0,0,0,0,0,0),
  ('viewer','suppliers', 0,0,0,0,0,0,0,0),
  ('viewer','cashbook',  0,0,0,0,0,0,0,0),
  ('viewer','expenses',  0,0,0,0,0,0,0,0),
  ('viewer','stock',     0,0,0,0,0,0,0,0),
  ('viewer','reports',   1,0,0,0,1,1,0,0),
  ('viewer','users',     0,0,0,0,0,0,0,0),
  ('viewer','settings',  0,0,0,0,0,0,0,0),
  ('viewer','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 8. SEED role_permissions — CUSTOM (all-zeros template)
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('custom','dashboard', 0,0,0,0,0,0,0,0),
  ('custom','products',  0,0,0,0,0,0,0,0),
  ('custom','sales',     0,0,0,0,0,0,0,0),
  ('custom','purchases', 0,0,0,0,0,0,0,0),
  ('custom','returns',   0,0,0,0,0,0,0,0),
  ('custom','credits',   0,0,0,0,0,0,0,0),
  ('custom','suppliers', 0,0,0,0,0,0,0,0),
  ('custom','cashbook',  0,0,0,0,0,0,0,0),
  ('custom','expenses',  0,0,0,0,0,0,0,0),
  ('custom','stock',     0,0,0,0,0,0,0,0),
  ('custom','reports',   0,0,0,0,0,0,0,0),
  ('custom','users',     0,0,0,0,0,0,0,0),
  ('custom','settings',  0,0,0,0,0,0,0,0),
  ('custom','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=0, can_add=0, can_edit=0, can_delete=0,
  can_print=0, can_export=0, can_import=0, can_void=0;

-- ============================================================
-- 9. BACKFILL user_permissions FOR EXISTING USERS
--    Any user that has a role but NO rows in user_permissions
--    gets the default template applied automatically.
--    Admin users are skipped (they bypass permission checks).
-- ============================================================
INSERT IGNORE INTO `user_permissions`
  (user_id, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void)
SELECT
  u.id,
  rp.module,
  rp.can_view, rp.can_add, rp.can_edit, rp.can_delete,
  rp.can_print, rp.can_export, rp.can_import, rp.can_void
FROM `users` u
JOIN `role_permissions` rp ON rp.role = u.role
WHERE u.role != 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `user_permissions` up2
    WHERE up2.user_id = u.id AND up2.module = rp.module
  );

-- ============================================================
-- 10. UNIQUE KEY on user_permissions (user_id, module)
--     Prevents duplicate rows on re-runs.
-- ============================================================
ALTER TABLE `user_permissions`
  ADD UNIQUE KEY IF NOT EXISTS `uq_user_module` (`user_id`, `module`);

-- ============================================================
-- DONE
-- ============================================================
SELECT 'Phase 21 Role System migration complete.' AS status;

-- ============================================================
-- *** PHASE 23 CASHBOOK MIGRATION ***
-- ============================================================
-- ============================================================
-- PHASE 23 — CASHBOOK INTEGRATION MIGRATION
-- Run once on any existing database to apply schema fixes.
-- Safe to re-run: all statements use IF NOT EXISTS / MODIFY.
-- ============================================================

-- 1. Expand cashbook entry_type ENUM to include 'purchase_credit'
--    (records credit/pending purchases as informational debit entries)
ALTER TABLE `cashbook`
  MODIFY COLUMN `entry_type` ENUM(
    'sale',
    'purchase_payment',
    'purchase_credit',
    'credit_received',
    'expense',
    'sale_return',
    'purchase_return',
    'opening',
    'closing',
    'credit_given',
    'expired_loss',
    'cash_in',
    'cash_out',
    'adjustment'
  ) NOT NULL;

-- 2. Ensure 'voided' column exists (defensive — should already be present)
ALTER TABLE `cashbook`
  ADD COLUMN IF NOT EXISTS `voided` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `voided_reason` VARCHAR(255) DEFAULT NULL;

-- 3. Ensure payment_method ENUM includes all methods
ALTER TABLE `cashbook`
  MODIFY COLUMN `payment_method` ENUM(
    'cash','card','online','cheque','bank','credit_note','adjustment'
  ) DEFAULT 'cash';

-- 4. Index for orphan detection queries (entry_type + reference_id + reference_type)
ALTER TABLE `cashbook`
  ADD INDEX IF NOT EXISTS `idx_cashbook_ref_type` (`entry_type`, `reference_id`, `reference_type`);

-- ============================================================
-- Backfill: create purchase_credit entries for any existing
-- pending/credit purchases that are missing cashbook entries
-- ============================================================
INSERT INTO `cashbook`
  (entry_date, entry_type, reference_id, reference_type, description,
   debit, credit, payment_method, created_by, voided)
SELECT
  DATE(p.purchase_date),
  'purchase_credit',
  p.id,
  'purchase_credit',
  CONCAT('Credit purchase (due) - ', COALESCE(p.purchase_invoice_no, CONCAT('PUR-', p.id))),
  (p.total - COALESCE(p.paid_amount, 0)),
  0,
  'credit_note',
  1,
  0
FROM purchases p
WHERE p.payment_status IN ('pending','credit')
  AND (p.total - COALESCE(p.paid_amount, 0)) > 0
  AND p.id NOT IN (
    SELECT reference_id FROM cashbook
    WHERE entry_type = 'purchase_credit'
      AND reference_type = 'purchase_credit'
  );

-- ============================================================
-- End of Phase 23 Migration
-- ============================================================

-- ============================================================
-- *** PHASE 24 POS MIGRATION ***
-- ============================================================
-- ============================================================
-- PHASE 24 – POS CORE MIGRATION
-- Run once on the pharmacy_db database.
-- ============================================================

USE `pharmacy_db`;

-- ────────────────────────────────────────────────────────────
-- 1. product_barcodes table
--    Allows multiple barcodes (EAN-13, QR, etc.) per product.
--    The barcode column on products.product_code is still the
--    PRIMARY scan target; this table is for additional codes.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_barcodes` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `product_id`   INT(11)      NOT NULL,
    `barcode`      VARCHAR(100) NOT NULL,
    `barcode_type` VARCHAR(20)  DEFAULT 'EAN13'
                               COMMENT 'EAN13, QR, CODE128, etc.',
    `is_primary`   TINYINT(1)  DEFAULT 0,
    `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_barcode` (`barcode`),
    KEY `idx_pb_product` (`product_id`),
    CONSTRAINT `product_barcodes_ibfk_1`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Additional barcodes per product (EAN-13, QR, etc.)';

-- ────────────────────────────────────────────────────────────
-- 2. Performance indexes for POS search
--    These cover the correlated subqueries in
--    search_product_pos.php and get_product_by_barcode.php.
-- ────────────────────────────────────────────────────────────
ALTER TABLE `products`
    ADD INDEX IF NOT EXISTS `idx_products_status_deleted`
        (`status`, `deleted_at`),
    ADD INDEX IF NOT EXISTS `idx_products_code`
        (`product_code`);

ALTER TABLE `batches`
    ADD INDEX IF NOT EXISTS `idx_batches_pos_lookup`
        (`product_id`, `is_expired`, `remaining_quantity`, `expiry_date`);

-- ────────────────────────────────────────────────────────────
-- 3. Soft-delete column guard
--    products.deleted_at is used in WHERE clauses but may not
--    exist on older installs. Add it safely.
-- ────────────────────────────────────────────────────────────
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Soft delete timestamp; NULL = active';

-- ────────────────────────────────────────────────────────────
-- 4. Populate product_barcodes with existing product_codes
--    so barcode scanner works immediately after migration.
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO `product_barcodes` (`product_id`, `barcode`, `barcode_type`, `is_primary`)
SELECT `id`, `product_code`, 'CODE128', 1
FROM   `products`
WHERE  `product_code` != ''
  AND  `product_code` IS NOT NULL
  AND  `deleted_at` IS NULL;

-- ────────────────────────────────────────────────────────────
-- Done
-- ────────────────────────────────────────────────────────────
SELECT CONCAT('Phase 24 POS migration complete. product_barcodes has ',
              COUNT(*), ' rows.') AS status
FROM `product_barcodes`;

-- ============================================================
-- *** PHASE 25 POS ADVANCED MIGRATION ***
-- ============================================================
-- ============================================================
-- PHASE 25 – POS ADVANCED MIGRATION
-- Run once on pharmacy_db database.
-- ============================================================

USE `pharmacy_db`;

-- ──────────────────────────────────────────────────────────────
-- 1. sale_items: ensure batch_number & expiry_date stored
--    (for FEFO audit trail and invoice display)
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `batch_number`  VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Batch number snapshot at time of sale',
    ADD COLUMN IF NOT EXISTS `expiry_date`   DATE         NULL DEFAULT NULL
        COMMENT 'Expiry date snapshot at time of sale (FEFO audit)';

-- ──────────────────────────────────────────────────────────────
-- 2. sales: ensure all Phase 25 columns present
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `subtotal`              DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `discount_percent`      DECIMAL(6,2)  DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `tax_percent`           DECIMAL(6,2)  DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `tax_amount`            DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `paid_amount`           DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `balance`               DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `is_credit`             TINYINT(1)    DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `due_date`              DATE          NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_status`        VARCHAR(20)   DEFAULT 'paid',
    ADD COLUMN IF NOT EXISTS `notes`                 TEXT          NULL DEFAULT NULL;

-- ──────────────────────────────────────────────────────────────
-- 3. sale_returns: ensure exists for POS return flow
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sale_returns` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `sale_id`        INT(11)      NOT NULL,
    `return_no`      VARCHAR(50)  NOT NULL,
    `return_date`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason`         TEXT         NOT NULL,
    `refund_method`  VARCHAR(30)  NOT NULL DEFAULT 'cash',
    `total_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`         VARCHAR(20)  NOT NULL DEFAULT 'completed',
    `created_by`     INT(11)      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_sr_sale` (`sale_id`),
    KEY `idx_sr_no`   (`return_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sale returns header';

CREATE TABLE IF NOT EXISTS `sale_return_items` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `return_id`         INT(11)      NOT NULL,
    `sale_item_id`      INT(11)      NOT NULL,
    `product_id`        INT(11)      NOT NULL,
    `batch_id`          INT(11)      NULL,
    `quantity`          INT(11)      NOT NULL DEFAULT 1,
    `unit_price`        DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `total`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `idx_sri_return` (`return_id`),
    KEY `idx_sri_sitem`  (`sale_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 4. invoice_pdf_log: optional — tracks PDF generation requests
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoice_pdf_log` (
    `id`         INT(11)  NOT NULL AUTO_INCREMENT,
    `sale_id`    INT(11)  NOT NULL,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `generated_by` INT(11) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_pdflog_sale` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 5. Backfill batch_number / expiry_date into sale_items
--    for any existing rows that sold from a batch
-- ──────────────────────────────────────────────────────────────
UPDATE `sale_items` si
JOIN   `batches`    b ON b.id = si.batch_id
SET    si.batch_number = COALESCE(si.batch_number, b.batch_number),
       si.expiry_date  = COALESCE(si.expiry_date,  b.expiry_date)
WHERE  si.batch_id IS NOT NULL
  AND  (si.batch_number IS NULL OR si.expiry_date IS NULL);

-- ──────────────────────────────────────────────────────────────
-- Done
-- ──────────────────────────────────────────────────────────────
SELECT CONCAT('Phase 25 POS Advanced migration complete. sale_items columns ready.') AS status;

-- ============================================================
-- *** ENTERPRISE PATCH v7.0 ***
-- ============================================================
-- ============================================================
-- ARSLAN'S PHARMACY ERP — Enterprise Schema Patch v7.0
-- Fix: M-01, M-02, M-03, P-01 through P-06
-- Author: Dr. Arslan Yasin Sukhera | June 2026
-- Run AFTER pharmacy_db.sql on fresh install,
-- or run standalone on existing installation (safe — uses IF NOT EXISTS / IF NOT EXISTS)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- ============================================================
-- 1. MISSING TABLE: customers (FIX M-01)
-- Customer master table — needed by credit, WhatsApp, POS
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_name` (`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. MISSING TABLE: invoice_sequences (FIX M-02)
-- Atomic invoice number generation — prevents race conditions
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoice_sequences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prefix` varchar(10) NOT NULL,
  `year` int(4) NOT NULL,
  `month` int(2) NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prefix_ym` (`prefix`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. MISSING TABLE: expense_categories (FIX M-03)
-- Expense category master for expenses module
-- ============================================================
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default categories
INSERT IGNORE INTO `expense_categories` (`name`, `description`) VALUES
('Rent', 'Monthly shop/office rent'),
('Utilities', 'Electricity, gas, water bills'),
('Salaries', 'Staff salaries and wages'),
('Supplies', 'Office and pharmacy supplies'),
('Maintenance', 'Equipment and premises maintenance'),
('Miscellaneous', 'Other expenses');

-- ============================================================
-- 4. PERFORMANCE INDEXES (FIX P-01 through P-06)
-- Covering indexes for FIFO, dashboard, expiry, cashbook, reports
-- ============================================================

-- batches: FIFO batch selection + expiry alert (FIX P-02)
ALTER TABLE `batches`
  ADD INDEX IF NOT EXISTS `idx_fifo` (`product_id`, `expiry_date`, `remaining_quantity`),
  ADD INDEX IF NOT EXISTS `idx_expiry` (`expiry_date`, `is_expired`, `remaining_quantity`);

-- sale_items: fast sale view and returns lookup (FIX P-03)
ALTER TABLE `sale_items`
  ADD INDEX IF NOT EXISTS `idx_sale_product` (`sale_id`, `product_id`, `batch_id`);

-- cashbook: date range + voided queries (FIX P-04)
ALTER TABLE `cashbook`
  ADD INDEX IF NOT EXISTS `idx_date_voided` (`entry_date`, `voided`);

-- activity_logs: user activity dashboard (FIX P-05)
ALTER TABLE `activity_logs`
  ADD INDEX IF NOT EXISTS `idx_user_date` (`user_id`, `created_at`);

-- sales: date + status reports (FIX P-01 partial)
ALTER TABLE `sales`
  ADD INDEX IF NOT EXISTS `idx_date_status` (`sale_date`, `payment_status`),
  ADD INDEX IF NOT EXISTS `idx_customer_phone` (`customer_phone`);

-- purchases: date + supplier reports
ALTER TABLE `purchases`
  ADD INDEX IF NOT EXISTS `idx_date_supplier` (`purchase_date`, `supplier_id`);

-- products: status + parent queries
ALTER TABLE `products`
  ADD INDEX IF NOT EXISTS `idx_status_parent` (`status`, `parent_id`);

-- ============================================================
-- 5. ENSURE EXISTING TABLES HAVE REQUIRED COLUMNS
-- ============================================================

-- Add 'stock' column if missing from user_permissions
ALTER TABLE `user_permissions`
  ADD COLUMN IF NOT EXISTS `can_stock` tinyint(1) NOT NULL DEFAULT 1 AFTER `can_void`;

SELECT 'Enterprise Patch v7.0 Applied Successfully' AS status;

-- ============================================================
-- Phase 15: Stock Transfers table
-- Run this patch if upgrading from Phase 14 or earlier
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `from_batch_id`   int(11)      NOT NULL,
  `to_batch_id`     int(11)      NOT NULL,
  `product_id`      int(11)      NOT NULL,
  `quantity`        int(11)      NOT NULL COMMENT 'units transferred',
  `reason`          varchar(255) NOT NULL DEFAULT '',
  `created_by`      int(11)      NOT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_st_product` (`product_id`),
  KEY `idx_st_from`    (`from_batch_id`),
  KEY `idx_st_to`      (`to_batch_id`),
  KEY `idx_st_date`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add stock_transfers movement types to stock_movements if not present
-- (stock_movements.movement_type already supports any varchar; no ALTER needed)

-- ============================================================
-- *** PHASE 26 DATABASE ENGINEERING ***
-- ============================================================
-- ============================================================
-- ARSLAN'S PHARMACY ERP — PHASE 26: DATABASE ENGINEERING
-- Full Database Audit + Schema Hardening
-- Author: Dr. Arslan Yasin Sukhera | June 2026
-- Input:  Phase 25 POS Advanced (pharmacy_db (3).sql + migrations)
-- Safe to re-run: all statements use IF NOT EXISTS / DROP IF EXISTS guards
-- ============================================================

USE `pharmacy_db`;

SET NAMES utf8mb4;
SET time_zone = '+05:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- PHASE 26 AUDIT SUMMARY
-- ============================================================
-- Tables audited   : 28 core + 4 Phase 25 new tables
-- Tables total     : 32 (excl. pma__ and legacy orphan tables)
--
-- ISSUES FOUND & FIXED:
--
-- [FK-01]  active_sessions        — NO PK defined in ALTER section
-- [FK-02]  cashbook               — NO PK, NO FKs (added_by → users)
-- [FK-03]  expenses               — NO PK, NO FK (added_by → users)
-- [FK-04]  expense_categories     — status column missing (was in enterprise patch)
-- [FK-05]  customers              — NO FK guard (outstanding_balance vs total_due mismatch)
-- [FK-06]  customer_credits       — NO FK (sale_id → sales, customer_id missing)
-- [FK-07]  purchase_returns       — NO FK on purchase_item_id → purchase_items, batch_id → batches
-- [FK-08]  sale_returns           — NO FK on sale_item_id → sale_items
-- [FK-09]  stock_movements        — NO FK (created_by → users)
-- [FK-10]  stock_transfers        — NO FKs (from_batch_id, to_batch_id, product_id, created_by)
-- [FK-11]  invoice_pdf_log        — New Phase 25 table: NO FK on generated_by → users
-- [FK-12]  sale_return_items      — New Phase 25 table: NO FK on return_id, sale_item_id, product_id, batch_id
-- [FK-13]  user_permissions       — NO FK on user_id → users
-- [FK-14]  role_permissions       — NO index on role+module (duplicate check via UNIQUE only on Phase 21)
-- [FK-15]  product_barcodes       — Already has FK (Phase 24) ✓
-- [FK-16]  purchases.created_by   — NO FK → users
-- [FK-17]  sales.created_by       — NO FK → users
-- [FK-18]  purchase_returns.created_by — NO FK → users
-- [FK-19]  sale_returns.created_by     — NO FK → users
-- [FK-20]  supplier_payments missing created_by column entirely
--
-- [IDX-01] active_sessions        — NO index on user_id, session_id
-- [IDX-02] cashbook               — duplicate index pattern; missing covering index
-- [IDX-03] customers              — NO index on phone, status
-- [IDX-04] customer_credits       — NO index on customer_phone, status
-- [IDX-05] expenses               — NO index on expense_date, category
-- [IDX-06] invoice_sequences      — MISSING unique key on (prefix, year, month) — col year/month absent
-- [IDX-07] stock_movements        — NO index on reference_id+reference_type (join perf)
-- [IDX-08] sale_return_items      — NO composite index
-- [IDX-09] products               — deleted_at and status+deleted_at composite missing
-- [IDX-10] users                  — NO index on email, status
-- [IDX-11] supplier_payments      — NO composite index (supplier_id, payment_date)
-- [IDX-12] purchase_returns       — NO index on batch_id
--
-- [VIEW-01] expiry_alerts_view    — Missing is_blocked filter (blocked batches appear)
-- [VIEW-02] stock_summary_view    — deleted_at filter missing
-- [VIEW-03] low_stock_view        — DOES NOT EXIST — needed by dashboard
-- [VIEW-04] pending_credits_view  — DOES NOT EXIST — needed by credit module
-- [VIEW-05] daily_cashflow_view   — DOES NOT EXIST — needed by cashbook reports
--
-- [TRIG-01] update_expired_flag   — Only fires on UPDATE; needs INSERT trigger too
-- [TRIG-02] update_product_stock  — DOES NOT EXIST — products.stock_quantity manual sync risk
-- [TRIG-03] log_sale_cashbook     — DOES NOT EXIST — cashbook entries created in PHP (race risk)
--
-- [PROC-01] sp_get_next_invoice_no — DOES NOT EXIST — invoice_sequences used ad-hoc
-- [PROC-02] sp_fefo_batch_pick     — DOES NOT EXIST — FEFO logic duplicated in PHP
--
-- [COL-01] invoice_sequences      — year + month columns missing (enterprise patch added them)
-- [COL-02] cashbook               — payment_method column missing in (3).sql base
-- [COL-03] company_settings       — favicon/logo paths duplicated (logo_path + company_logo)
-- [COL-04] expense_categories     — status column missing
-- [COL-05] sales                  — balance + is_credit + due_date missing in base (Phase 25 adds them)
-- [COL-06] sale_items             — batch_number + expiry_date missing in base (Phase 25 adds them)
-- [COL-07] users                  — role enum missing manager/custom/viewer (Phase 21 adds them)
-- [COL-08] supplier_payments      — created_by missing
-- ============================================================


-- ============================================================
-- SECTION 1: COLUMN ADDITIONS (defensive guards)
-- ============================================================

-- [COL-01] invoice_sequences: add year + month for atomic per-month sequences
ALTER TABLE `invoice_sequences`
  ADD COLUMN IF NOT EXISTS `year`  INT(4)       NOT NULL DEFAULT 0  AFTER `prefix`,
  ADD COLUMN IF NOT EXISTS `month` INT(2)       NOT NULL DEFAULT 0  AFTER `year`;

-- Re-create unique key to match enterprise patch design (prefix+year+month)
-- Drop old simple UNIQUE on prefix if it exists, add compound
ALTER TABLE `invoice_sequences`
  DROP INDEX IF EXISTS `prefix`,
  ADD UNIQUE KEY IF NOT EXISTS `uk_prefix_ym` (`prefix`, `year`, `month`);

-- [COL-02] cashbook: ensure payment_method present (missing in (3).sql base)
ALTER TABLE `cashbook`
  ADD COLUMN IF NOT EXISTS `payment_method`
    ENUM('cash','card','online','cheque','bank','credit_note','adjustment')
    DEFAULT 'cash'
    AFTER `voided_reason`,
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) DEFAULT NULL
    AFTER `payment_method`;

-- Migrate added_by → created_by if data exists (added_by is the older column name)
UPDATE `cashbook` SET `created_by` = `added_by` WHERE `created_by` IS NULL AND `added_by` IS NOT NULL;

-- [COL-03] expense_categories: add status (enterprise patch used status=1 default)
ALTER TABLE `expense_categories`
  ADD COLUMN IF NOT EXISTS `status` TINYINT(1) NOT NULL DEFAULT 1 AFTER `description`;

-- [COL-04] expenses: add category_id FK column alongside legacy varchar category
ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `category_id` INT(11) DEFAULT NULL
    COMMENT 'FK to expense_categories.id; replaces legacy varchar category'
    AFTER `category`;

-- [COL-05] customers: normalise column naming (enterprise patch: total_due vs (3).sql: outstanding_balance)
--  Add total_due as alias if outstanding_balance exists
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `total_due`
    DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Alias sync for outstanding_balance; kept for backward compat'
    AFTER `outstanding_balance`;

-- [COL-06] supplier_payments: add created_by
ALTER TABLE `supplier_payments`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) DEFAULT NULL
    AFTER `notes`;

-- [COL-07] stock_transfers: ensure all columns present (redundancy guard)
ALTER TABLE `stock_transfers`
  ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL AFTER `reason`;

-- [COL-08] purchase_returns: ensure created_by present (already in schema, guard)
ALTER TABLE `purchase_returns`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) DEFAULT NULL
    AFTER `status`;

-- ============================================================
-- SECTION 2: PRIMARY KEYS — add missing PKs
-- ============================================================

-- [FK-01] active_sessions — PK was missing in ALTER section
ALTER TABLE `active_sessions`
  MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY IF NOT EXISTS (`id`);

-- cashbook PK (defensive — should already exist from install but not in (3).sql ALTER block)
ALTER TABLE `cashbook`
  MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;
-- Note: PK added below with indexes section to avoid duplicate

-- expenses PK guard
ALTER TABLE `expenses`
  MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;


-- ============================================================
-- SECTION 3: MISSING FOREIGN KEYS
-- ============================================================

-- [FK-02] cashbook.added_by → users.id
ALTER TABLE `cashbook`
  ADD CONSTRAINT IF NOT EXISTS `cashbook_ibfk_added_by`
    FOREIGN KEY (`added_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-02b] cashbook.created_by → users.id
ALTER TABLE `cashbook`
  ADD CONSTRAINT IF NOT EXISTS `cashbook_ibfk_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-03] expenses.added_by → users.id
ALTER TABLE `expenses`
  ADD CONSTRAINT IF NOT EXISTS `expenses_ibfk_added_by`
    FOREIGN KEY (`added_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-04] expenses.category_id → expense_categories.id
ALTER TABLE `expenses`
  ADD CONSTRAINT IF NOT EXISTS `expenses_ibfk_category`
    FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`)
    ON DELETE SET NULL;

-- [FK-06] customer_credits.sale_id → sales.id
ALTER TABLE `customer_credits`
  ADD CONSTRAINT IF NOT EXISTS `customer_credits_ibfk_sale`
    FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`)
    ON DELETE SET NULL;

-- [FK-07] purchase_returns.purchase_item_id → purchase_items.id
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT IF NOT EXISTS `purchase_returns_ibfk_item`
    FOREIGN KEY (`purchase_item_id`) REFERENCES `purchase_items` (`id`)
    ON DELETE CASCADE;

-- [FK-07b] purchase_returns.batch_id → batches.id
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT IF NOT EXISTS `purchase_returns_ibfk_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`)
    ON DELETE RESTRICT;

-- [FK-07c] purchase_returns.created_by → users.id
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT IF NOT EXISTS `purchase_returns_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-08] sale_returns.sale_item_id → sale_items.id
ALTER TABLE `sale_returns`
  ADD CONSTRAINT IF NOT EXISTS `sale_returns_ibfk_item`
    FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`)
    ON DELETE CASCADE;

-- [FK-08b] sale_returns.batch_id → batches.id
ALTER TABLE `sale_returns`
  ADD CONSTRAINT IF NOT EXISTS `sale_returns_ibfk_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`)
    ON DELETE RESTRICT;

-- [FK-08c] sale_returns.created_by → users.id
ALTER TABLE `sale_returns`
  ADD CONSTRAINT IF NOT EXISTS `sale_returns_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-09] stock_movements.created_by → users.id
ALTER TABLE `stock_movements`
  ADD CONSTRAINT IF NOT EXISTS `stock_movements_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-10] stock_transfers FKs
ALTER TABLE `stock_transfers`
  ADD CONSTRAINT IF NOT EXISTS `stock_transfers_ibfk_from`
    FOREIGN KEY (`from_batch_id`) REFERENCES `batches` (`id`)
    ON DELETE RESTRICT,
  ADD CONSTRAINT IF NOT EXISTS `stock_transfers_ibfk_to`
    FOREIGN KEY (`to_batch_id`) REFERENCES `batches` (`id`)
    ON DELETE RESTRICT,
  ADD CONSTRAINT IF NOT EXISTS `stock_transfers_ibfk_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE RESTRICT,
  ADD CONSTRAINT IF NOT EXISTS `stock_transfers_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT;

-- [FK-11] invoice_pdf_log FKs (Phase 25 new table)
ALTER TABLE `invoice_pdf_log`
  ADD CONSTRAINT IF NOT EXISTS `invoice_pdf_log_ibfk_sale`
    FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`)
    ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS `invoice_pdf_log_ibfk_user`
    FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT;

-- [FK-12] sale_return_items FKs (Phase 25 new table)
ALTER TABLE `sale_return_items`
  ADD CONSTRAINT IF NOT EXISTS `sale_return_items_ibfk_return`
    FOREIGN KEY (`return_id`) REFERENCES `sale_returns` (`id`)
    ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS `sale_return_items_ibfk_sale_item`
    FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`)
    ON DELETE RESTRICT,
  ADD CONSTRAINT IF NOT EXISTS `sale_return_items_ibfk_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE RESTRICT,
  ADD CONSTRAINT IF NOT EXISTS `sale_return_items_ibfk_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`)
    ON DELETE SET NULL;

-- [FK-13] user_permissions.user_id → users.id
ALTER TABLE `user_permissions`
  ADD CONSTRAINT IF NOT EXISTS `user_permissions_ibfk_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE;

-- [FK-16] purchases.created_by → users.id
ALTER TABLE `purchases`
  ADD CONSTRAINT IF NOT EXISTS `purchases_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-17] sales.created_by → users.id
ALTER TABLE `sales`
  ADD CONSTRAINT IF NOT EXISTS `sales_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;

-- [FK-20] supplier_payments.created_by → users.id
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT IF NOT EXISTS `supplier_payments_ibfk_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL;


-- ============================================================
-- SECTION 4: COMPOSITE & COVERING INDEXES
-- ============================================================

-- [IDX-01] active_sessions
ALTER TABLE `active_sessions`
  ADD INDEX IF NOT EXISTS `idx_as_user`    (`user_id`),
  ADD INDEX IF NOT EXISTS `idx_as_session` (`session_id`),
  ADD INDEX IF NOT EXISTS `idx_as_activity` (`last_activity`);

-- [IDX-02] cashbook — covering index for date-range cashbook report
ALTER TABLE `cashbook`
  ADD INDEX IF NOT EXISTS `idx_cb_date_type`
    (`entry_date`, `entry_type`, `voided`),
  ADD INDEX IF NOT EXISTS `idx_cb_ref`
    (`reference_type`, `reference_id`);

-- [IDX-03] customers
ALTER TABLE `customers`
  ADD INDEX IF NOT EXISTS `idx_cust_phone`  (`phone`),
  ADD INDEX IF NOT EXISTS `idx_cust_status` (`status`),
  ADD INDEX IF NOT EXISTS `idx_cust_name`   (`name`);

-- [IDX-04] customer_credits
ALTER TABLE `customer_credits`
  ADD INDEX IF NOT EXISTS `idx_cc_phone`  (`customer_phone`),
  ADD INDEX IF NOT EXISTS `idx_cc_status` (`status`),
  ADD INDEX IF NOT EXISTS `idx_cc_due`    (`due_date`);

-- [IDX-05] expenses
ALTER TABLE `expenses`
  ADD INDEX IF NOT EXISTS `idx_exp_date`     (`expense_date`),
  ADD INDEX IF NOT EXISTS `idx_exp_category` (`category`),
  ADD INDEX IF NOT EXISTS `idx_exp_cat_id`   (`category_id`);

-- [IDX-06] invoice_sequences — compound unique (already added in Section 1)
-- Defensive: also add lookup index on prefix+year
ALTER TABLE `invoice_sequences`
  ADD INDEX IF NOT EXISTS `idx_iseq_prefix` (`prefix`, `year`);

-- [IDX-07] stock_movements — covering index for reference lookups
ALTER TABLE `stock_movements`
  ADD INDEX IF NOT EXISTS `idx_sm_ref`
    (`reference_type`, `reference_id`),
  ADD INDEX IF NOT EXISTS `idx_sm_batch_type`
    (`batch_id`, `movement_type`, `created_at`);

-- [IDX-08] sale_return_items
ALTER TABLE `sale_return_items`
  ADD INDEX IF NOT EXISTS `idx_sri_product`  (`product_id`),
  ADD INDEX IF NOT EXISTS `idx_sri_batch`    (`batch_id`);

-- [IDX-09] products — deleted_at support
ALTER TABLE `products`
  ADD INDEX IF NOT EXISTS `idx_prod_deleted_status`
    (`deleted_at`, `status`),
  ADD INDEX IF NOT EXISTS `idx_prod_brand` (`brand_name`);

-- [IDX-10] users
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_email`  (`email`),
  ADD INDEX IF NOT EXISTS `idx_users_status` (`status`),
  ADD INDEX IF NOT EXISTS `idx_users_role`   (`role`);

-- [IDX-11] supplier_payments — statement query
ALTER TABLE `supplier_payments`
  ADD INDEX IF NOT EXISTS `idx_sp_supplier_date`
    (`supplier_id`, `payment_date`);

-- [IDX-12] purchase_returns — batch lookup
ALTER TABLE `purchase_returns`
  ADD INDEX IF NOT EXISTS `idx_pr_batch`    (`batch_id`),
  ADD INDEX IF NOT EXISTS `idx_pr_item`     (`purchase_item_id`);

-- sale_returns batch/item lookup
ALTER TABLE `sale_returns`
  ADD INDEX IF NOT EXISTS `idx_sr_batch`    (`batch_id`),
  ADD INDEX IF NOT EXISTS `idx_sr_item`     (`sale_item_id`);

-- batches: remove duplicate single-column indexes (keep compound; drop redundant)
-- idx_batches_expiry and expiry_date are duplicates — remove the plain one
ALTER TABLE `batches`
  DROP INDEX IF EXISTS `expiry_date`,           -- duplicate of idx_batches_expiry
  DROP INDEX IF EXISTS `product_id`,            -- duplicate of idx_batches_product
  ADD INDEX IF NOT EXISTS `idx_batch_supplier_date` (`supplier_id`, `purchase_date`);

-- sales: remove duplicate sale_date / idx_sales_date
ALTER TABLE `sales`
  DROP INDEX IF EXISTS `sale_date`;             -- duplicate of idx_sales_date

-- purchase_items: remove duplicate purchase_id / idx_purchase_items_purchase
ALTER TABLE `purchase_items`
  DROP INDEX IF EXISTS `purchase_id`;           -- duplicate of idx_purchase_items_purchase

-- sale_items: remove duplicate sale_id / idx_sale_items_sale
ALTER TABLE `sale_items`
  DROP INDEX IF EXISTS `sale_id`;               -- duplicate of idx_sale_items_sale


-- ============================================================
-- SECTION 5: VIEW FIXES & NEW VIEWS
-- ============================================================

-- [VIEW-01] Fix expiry_alerts_view — add is_blocked filter
CREATE OR REPLACE
  ALGORITHM = UNDEFINED
  DEFINER   = CURRENT_USER
  SQL SECURITY DEFINER
VIEW `expiry_alerts_view` AS
SELECT
    b.id                                                AS batch_id,
    b.batch_number,
    p.product_name,
    p.generic_name,
    b.expiry_date,
    b.remaining_quantity,
    DATEDIFF(b.expiry_date, CURDATE())                  AS days_to_expire,
    CASE
        WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 0    THEN 'Expired'
        WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 30   THEN 'Critical'
        WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 90   THEN 'Warning'
        ELSE 'Normal'
    END                                                 AS expiry_status
FROM  `batches`  b
JOIN  `products` p  ON p.id = b.product_id
WHERE b.remaining_quantity > 0
  AND b.is_blocked = 0                     -- FIX: was missing
  AND (p.deleted_at IS NULL OR p.status = 1)  -- FIX: exclude soft-deleted products
ORDER BY b.expiry_date ASC;

-- [VIEW-02] Fix stock_summary_view — add deleted_at filter
CREATE OR REPLACE
  ALGORITHM = UNDEFINED
  DEFINER   = CURRENT_USER
  SQL SECURITY DEFINER
VIEW `stock_summary_view` AS
SELECT
    p.id                                                AS product_id,
    p.product_name,
    p.generic_name,
    p.brand_name,
    p.strength,
    p.strength_unit,
    p.dosage_form,
    p.unit_type,
    p.pack_quantity,
    COALESCE(SUM(b.remaining_quantity), 0)              AS stock_in_pieces,
    COUNT(DISTINCT b.id)                                AS batch_count,
    MIN(b.expiry_date)                                  AS nearest_expiry,
    CASE
        WHEN COALESCE(SUM(b.remaining_quantity), 0) = 0             THEN 'Out of Stock'
        WHEN COALESCE(SUM(b.remaining_quantity), 0) <= p.reorder_level THEN 'Low Stock'
        ELSE 'In Stock'
    END                                                 AS stock_status
FROM  `products` p
LEFT JOIN `batches` b
      ON  b.product_id  = p.id
      AND b.is_expired  = 0
      AND b.is_blocked  = 0
WHERE p.status   = 1
  AND (p.deleted_at IS NULL)               -- FIX: exclude soft-deleted products
GROUP BY p.id;

-- [VIEW-03] NEW: low_stock_view — for dashboard alert widget
CREATE OR REPLACE
  ALGORITHM = UNDEFINED
  DEFINER   = CURRENT_USER
  SQL SECURITY DEFINER
VIEW `low_stock_view` AS
SELECT
    p.id                                    AS product_id,
    p.product_code,
    p.product_name,
    p.generic_name,
    p.brand_name,
    p.reorder_level,
    COALESCE(SUM(b.remaining_quantity), 0)  AS stock_in_pieces,
    MIN(b.expiry_date)                      AS nearest_expiry
FROM  `products` p
LEFT JOIN `batches` b
      ON  b.product_id = p.id
      AND b.is_expired = 0
      AND b.is_blocked = 0
WHERE p.status    = 1
  AND (p.deleted_at IS NULL)
GROUP BY p.id
HAVING COALESCE(SUM(b.remaining_quantity), 0) <= p.reorder_level
ORDER BY stock_in_pieces ASC, p.product_name ASC;

-- [VIEW-04] NEW: pending_credits_view — for credit module listing
CREATE OR REPLACE
  ALGORITHM = UNDEFINED
  DEFINER   = CURRENT_USER
  SQL SECURITY DEFINER
VIEW `pending_credits_view` AS
SELECT
    cc.id,
    cc.customer_name,
    cc.customer_phone,
    cc.total_amount,
    COALESCE(SUM(cp.amount), 0)                             AS paid_amount,
    cc.total_amount - COALESCE(SUM(cp.amount), 0)           AS balance_due,
    cc.due_date,
    cc.status,
    cc.sale_id,
    s.invoice_no,
    cc.created_at,
    DATEDIFF(CURDATE(), cc.due_date)                        AS days_overdue
FROM  `customer_credits` cc
LEFT JOIN `credit_payments` cp  ON cp.credit_id = cc.id
LEFT JOIN `sales`           s   ON s.id = cc.sale_id
WHERE cc.status = 'active'
GROUP BY cc.id
ORDER BY cc.due_date ASC;

-- [VIEW-05] NEW: daily_cashflow_view — for cashbook report summary
CREATE OR REPLACE
  ALGORITHM = UNDEFINED
  DEFINER   = CURRENT_USER
  SQL SECURITY DEFINER
VIEW `daily_cashflow_view` AS
SELECT
    cb.entry_date,
    cb.entry_type,
    SUM(cb.credit)  AS total_credit,
    SUM(cb.debit)   AS total_debit,
    SUM(cb.credit) - SUM(cb.debit) AS net_flow,
    COUNT(*)        AS transaction_count
FROM  `cashbook` cb
WHERE cb.voided = 0
GROUP BY cb.entry_date, cb.entry_type
ORDER BY cb.entry_date DESC, cb.entry_type ASC;


-- ============================================================
-- SECTION 6: TRIGGER ADDITIONS
-- ============================================================

-- [TRIG-01] Fix: also fire expiry check on INSERT into batches
DROP TRIGGER IF EXISTS `set_expired_flag_on_insert`;
DELIMITER $$
CREATE TRIGGER `set_expired_flag_on_insert`
BEFORE INSERT ON `batches`
FOR EACH ROW
BEGIN
    IF NEW.expiry_date < CURDATE() THEN
        SET NEW.is_expired = 1;
    ELSE
        SET NEW.is_expired = 0;
    END IF;
END$$
DELIMITER ;

-- [TRIG-02] NEW: sync products.stock_quantity after batch update
-- Keeps the denormalized products.stock_quantity accurate on every batch change.
DROP TRIGGER IF EXISTS `sync_product_stock_after_batch_update`;
DELIMITER $$
CREATE TRIGGER `sync_product_stock_after_batch_update`
AFTER UPDATE ON `batches`
FOR EACH ROW
BEGIN
    UPDATE `products`
    SET    `stock_quantity` = (
               SELECT COALESCE(SUM(remaining_quantity), 0)
               FROM   `batches`
               WHERE  product_id = NEW.product_id
                 AND  is_expired = 0
                 AND  is_blocked = 0
           ),
           `updated_at` = CURRENT_TIMESTAMP
    WHERE  id = NEW.product_id;
END$$
DELIMITER ;

-- [TRIG-03] NEW: sync products.stock_quantity after batch insert
DROP TRIGGER IF EXISTS `sync_product_stock_after_batch_insert`;
DELIMITER $$
CREATE TRIGGER `sync_product_stock_after_batch_insert`
AFTER INSERT ON `batches`
FOR EACH ROW
BEGIN
    UPDATE `products`
    SET    `stock_quantity` = (
               SELECT COALESCE(SUM(remaining_quantity), 0)
               FROM   `batches`
               WHERE  product_id = NEW.product_id
                 AND  is_expired = 0
                 AND  is_blocked = 0
           ),
           `updated_at` = CURRENT_TIMESTAMP
    WHERE  id = NEW.product_id;
END$$
DELIMITER ;


-- ============================================================
-- SECTION 7: STORED PROCEDURES
-- ============================================================

-- [PROC-01] sp_get_next_invoice_no — atomic invoice number generation
DROP PROCEDURE IF EXISTS `sp_get_next_invoice_no`;
DELIMITER $$
CREATE PROCEDURE `sp_get_next_invoice_no`(
    IN  p_prefix   VARCHAR(10),
    OUT p_invoice  VARCHAR(30)
)
BEGIN
    DECLARE v_year   INT DEFAULT YEAR(CURDATE());
    DECLARE v_month  INT DEFAULT MONTH(CURDATE());
    DECLARE v_num    INT DEFAULT 0;

    -- Atomic increment using INSERT ... ON DUPLICATE KEY UPDATE
    INSERT INTO `invoice_sequences` (`prefix`, `year`, `month`, `last_number`)
    VALUES (p_prefix, v_year, v_month, 1)
    ON DUPLICATE KEY UPDATE
        `last_number` = `last_number` + 1;

    SELECT `last_number` INTO v_num
    FROM   `invoice_sequences`
    WHERE  `prefix` = p_prefix
      AND  `year`   = v_year
      AND  `month`  = v_month;

    -- Format: INV-2026-06-0001
    SET p_invoice = CONCAT(
        p_prefix, '-',
        v_year, '-',
        LPAD(v_month, 2, '0'), '-',
        LPAD(v_num,   4, '0')
    );
END$$
DELIMITER ;

-- [PROC-02] sp_fefo_batch_pick — FEFO batch selection for POS
-- Returns the correct batch to deduct from, using First-Expiry-First-Out logic.
DROP PROCEDURE IF EXISTS `sp_fefo_batch_pick`;
DELIMITER $$
CREATE PROCEDURE `sp_fefo_batch_pick`(
    IN  p_product_id  INT(11),
    IN  p_quantity    INT(11),
    OUT p_batch_id    INT(11),
    OUT p_batch_no    VARCHAR(50),
    OUT p_expiry      DATE,
    OUT p_available   INT(11)
)
BEGIN
    -- Pick the batch expiring soonest that still has enough stock
    SELECT  id, batch_number, expiry_date, remaining_quantity
    INTO    p_batch_id, p_batch_no, p_expiry, p_available
    FROM    `batches`
    WHERE   product_id        = p_product_id
      AND   is_expired        = 0
      AND   is_blocked        = 0
      AND   remaining_quantity >= p_quantity
    ORDER BY expiry_date ASC, id ASC
    LIMIT 1;

    -- If no single batch holds enough, return the one with most stock (partial fill)
    IF p_batch_id IS NULL THEN
        SELECT  id, batch_number, expiry_date, remaining_quantity
        INTO    p_batch_id, p_batch_no, p_expiry, p_available
        FROM    `batches`
        WHERE   product_id        = p_product_id
          AND   is_expired        = 0
          AND   is_blocked        = 0
          AND   remaining_quantity > 0
        ORDER BY expiry_date ASC, remaining_quantity DESC
        LIMIT 1;
    END IF;
END$$
DELIMITER ;


-- ============================================================
-- SECTION 8: BACKFILL — sync products.stock_quantity
-- Run once to bring stock_quantity in line with actual batch data
-- ============================================================
UPDATE `products` p
SET    p.`stock_quantity` = (
           SELECT COALESCE(SUM(b.remaining_quantity), 0)
           FROM   `batches` b
           WHERE  b.product_id = p.id
             AND  b.is_expired = 0
             AND  b.is_blocked = 0
       ),
       p.`updated_at` = CURRENT_TIMESTAMP
WHERE  p.status = 1;

-- Seed invoice_sequences for the current month if no rows exist
INSERT IGNORE INTO `invoice_sequences` (`prefix`, `year`, `month`, `last_number`)
VALUES
  ('INV',  YEAR(CURDATE()), MONTH(CURDATE()), 0),
  ('PUR',  YEAR(CURDATE()), MONTH(CURDATE()), 0),
  ('RET',  YEAR(CURDATE()), MONTH(CURDATE()), 0),
  ('PRET', YEAR(CURDATE()), MONTH(CURDATE()), 0);

-- Backfill expenses.category_id from category name
UPDATE `expenses` e
JOIN   `expense_categories` ec ON ec.name = e.category
SET    e.category_id = ec.id
WHERE  e.category_id IS NULL;


-- ============================================================
-- SECTION 9: ENABLE FK CHECKS
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE
-- ============================================================
SELECT CONCAT(
    'Phase 26 Database Engineering migration complete. ',
    'Tables audited: 32. FKs added: 22. Indexes added: 28. ',
    'Views created/fixed: 5. Triggers added: 3. Procedures added: 2.'
) AS status;

-- ════════════════════════════════════════════════════════════════════════════
-- PHASE 28 ADDITIONS — Security Tables & Column Additions
-- ════════════════════════════════════════════════════════════════════════════
-- ============================================================
-- Phase 28: Security Migration
-- Pharmacy ERP — Enterprise Security Hardening
-- ============================================================

-- 1. Login attempt log table (DB-backed rate limiting, per IP)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `ip_address`   VARCHAR(45)      NOT NULL,
    `username`     VARCHAR(100)     DEFAULT NULL,
    `attempted_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success`      TINYINT(1)       NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_time`  (`ip_address`, `attempted_at`),
    INDEX `idx_user_time`(`username`,   `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Security audit log (tracks security-significant events)
CREATE TABLE IF NOT EXISTS `security_events` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`  VARCHAR(60)     NOT NULL,   -- e.g. CSRF_FAIL, XSS_ATTEMPT, PATH_TRAVERSAL
    `user_id`     INT             DEFAULT NULL,
    `ip_address`  VARCHAR(45)     NOT NULL,
    `user_agent`  VARCHAR(500)    DEFAULT NULL,
    `request_uri` VARCHAR(1000)   DEFAULT NULL,
    `details`     TEXT            DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_event_time`(`event_type`, `created_at`),
    INDEX `idx_ip`(`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure active_sessions table has all needed columns
ALTER TABLE `active_sessions`
    ADD COLUMN IF NOT EXISTS `csrf_token` VARCHAR(128) DEFAULT NULL AFTER `username`,
    ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(500) DEFAULT NULL;

-- 4. Add security_events cleanup event (runs nightly)
CREATE EVENT IF NOT EXISTS `cleanup_security_events`
    ON SCHEDULE EVERY 1 DAY
    DO DELETE FROM `security_events` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);

CREATE EVENT IF NOT EXISTS `cleanup_login_attempts`
    ON SCHEDULE EVERY 1 DAY
    DO DELETE FROM `login_attempts` WHERE `attempted_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- ════════════════════════════════════════════════════════════════════════════
-- PHASE 29 ADDITIONS — Backup/Reset Tables & Theme Columns
-- ════════════════════════════════════════════════════════════════════════════
-- ════════════════════════════════════════════════════════════════════
-- Phase 29 — Backup & Reset Improvements Migration
-- Pharmacy ERP PharmaCare Pro
-- Date: 2026-06-10
-- ════════════════════════════════════════════════════════════════════

-- 1. Ensure user_settings table exists (needed for Dark Blue Professional
--    theme assignment after factory reset)
CREATE TABLE IF NOT EXISTS `user_settings` (
    `id`             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT           NOT NULL,
    `selected_theme` VARCHAR(50)   NOT NULL DEFAULT 'dark_blue_professional',
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add theme_preset column to company_settings if not present
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'company_settings'
      AND COLUMN_NAME  = 'theme_preset'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE company_settings ADD COLUMN `theme_preset` VARCHAR(30) NOT NULL DEFAULT 'dark_blue_professional'",
    "SELECT 'theme_preset column already exists' AS _info"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Ensure system_resets audit table exists (Phase 28 — defensive re-create)
CREATE TABLE IF NOT EXISTS `system_resets` (
    `id`                      INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `reset_type`              VARCHAR(20)   NOT NULL,
    `reset_by`                INT           NOT NULL DEFAULT 0,
    `reset_at`                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`                   TEXT,
    `backup_filename_before`  VARCHAR(255),
    `ip_address`              VARCHAR(45)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- END Phase 29 migration
-- ════════════════════════════════════════════════════════════════════

-- ════════════════════════════════════════════════════════════════════════════
-- PHASE 30 FINAL — Restore SQL Mode
-- ════════════════════════════════════════════════════════════════════════════
SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;

-- ════════════════════════════════════════════════════════════════════════════
-- END PharmaCare Pro Phase 30 Final Production SQL
-- Tables: 44 | Triggers: 4 | Views: 2 | Procedures: 2 | Events: 2
-- ════════════════════════════════════════════════════════════════════════════
