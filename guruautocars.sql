-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 13, 2026 at 01:33 AM
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
-- Database: `guruautocars`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED DEFAULT NULL,
  `garage_id` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `role_key` varchar(50) DEFAULT NULL,
  `module_name` varchar(80) NOT NULL,
  `entity_name` varchar(80) DEFAULT NULL,
  `action_name` varchar(80) NOT NULL,
  `source_channel` varchar(40) DEFAULT NULL,
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `before_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_snapshot`)),
  `after_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_snapshot`)),
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `request_id` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `company_id`, `garage_id`, `user_id`, `role_key`, `module_name`, `entity_name`, `action_name`, `source_channel`, `reference_id`, `ip_address`, `details`, `before_snapshot`, `after_snapshot`, `metadata_json`, `request_id`, `created_at`) VALUES
(1, 1, NULL, 1, NULL, 'job_cards', NULL, 'status', NULL, 1, '::1', 'Status changed from COMPLETED to CLOSED', NULL, NULL, NULL, NULL, '2026-02-09 21:22:44'),
(2, 1, NULL, 1, NULL, 'services', NULL, 'create', NULL, 1, '::1', 'Created service ZSDF', NULL, NULL, NULL, NULL, '2026-02-09 21:26:32'),
(3, 1, NULL, 1, NULL, 'job_cards', NULL, 'create', NULL, 2, '::1', 'Created job card JOB-2602-1001', NULL, NULL, NULL, NULL, '2026-02-09 21:27:20'),
(4, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_labor', NULL, 2, '::1', 'Added labor line to job card', NULL, NULL, NULL, NULL, '2026-02-09 21:27:37'),
(5, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_part', NULL, 2, '::1', 'Added part #1 to job card', NULL, NULL, NULL, NULL, '2026-02-09 21:27:42'),
(6, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_part', NULL, 2, '::1', 'Added part #2 to job card', NULL, NULL, NULL, NULL, '2026-02-09 21:27:44'),
(7, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_labor', NULL, 2, '::1', 'Added labor line to job card', NULL, NULL, NULL, NULL, '2026-02-09 21:28:26'),
(8, 1, NULL, 1, NULL, 'job_cards', NULL, 'assign', NULL, 2, '::1', 'Updated job assignments', NULL, NULL, NULL, NULL, '2026-02-09 21:29:15'),
(9, 1, NULL, 1, NULL, 'job_cards', NULL, 'status', NULL, 2, '::1', 'Status changed from OPEN to IN_PROGRESS', NULL, NULL, NULL, NULL, '2026-02-09 21:29:26'),
(10, 1, NULL, 1, NULL, 'job_cards', NULL, 'status', NULL, 2, '::1', 'Status changed from IN_PROGRESS to WAITING_PARTS', NULL, NULL, NULL, NULL, '2026-02-09 21:29:48'),
(11, 1, NULL, 1, NULL, 'job_cards', NULL, 'status', NULL, 2, '::1', 'Status changed from WAITING_PARTS to COMPLETED', NULL, NULL, NULL, NULL, '2026-02-09 21:30:05'),
(12, 1, NULL, 1, NULL, 'parts_master', NULL, 'create', NULL, 4, '::1', 'Created part XGDB', NULL, NULL, NULL, NULL, '2026-02-09 21:31:40'),
(13, 1, NULL, 1, NULL, 'vis_catalog', NULL, 'create_brand', NULL, 1, '::1', 'Created brand Suzuki', NULL, NULL, NULL, NULL, '2026-02-09 21:40:28'),
(14, 1, NULL, 1, NULL, 'vis_catalog', NULL, 'create_model', NULL, 1, '::1', 'Created model Baleno', NULL, NULL, NULL, NULL, '2026-02-09 21:40:38'),
(15, 1, NULL, 1, NULL, 'vis_catalog', NULL, 'create_variant', NULL, 1, '::1', 'Created variant VXi', NULL, NULL, NULL, NULL, '2026-02-09 21:40:52'),
(16, 1, NULL, 1, NULL, 'vis_catalog', NULL, 'create_spec', NULL, 1, '::1', 'Created spec oil seal', NULL, NULL, NULL, NULL, '2026-02-09 21:41:10'),
(17, 1, NULL, 1, NULL, 'vis_mapping', NULL, 'create_part_compatibility', NULL, 1, '::1', 'Created VIS part compatibility mapping', NULL, NULL, NULL, NULL, '2026-02-09 21:41:27'),
(18, 1, NULL, 1, NULL, 'vis_mapping', NULL, 'create_service_part_map', NULL, 1, '::1', 'Created VIS service-to-part mapping', NULL, NULL, NULL, NULL, '2026-02-09 21:41:56'),
(19, 1, NULL, 1, NULL, 'vehicles', NULL, 'create', NULL, 3, '::1', 'Created vehicle ZSDVZDVVZSDV', NULL, NULL, NULL, NULL, '2026-02-09 21:43:48'),
(20, 1, NULL, 1, NULL, 'job_cards', NULL, 'create', NULL, 4, '::1', 'Created job card JOB-2602-1003', NULL, NULL, NULL, NULL, '2026-02-09 21:44:15'),
(21, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_part', NULL, 4, '::1', 'Added part #1 to job card', NULL, NULL, NULL, NULL, '2026-02-09 21:44:58'),
(22, 1, NULL, 1, NULL, 'vis_mapping', NULL, 'create_service_part_map', NULL, 2, '::1', 'Created VIS service-to-part mapping', NULL, NULL, NULL, NULL, '2026-02-09 21:53:15'),
(23, 1, NULL, 1, NULL, 'companies', NULL, 'update', NULL, 1, '::1', 'Updated company Guru Auto Cars', NULL, NULL, NULL, NULL, '2026-02-10 14:59:32'),
(24, 1, NULL, 1, NULL, 'system_settings', NULL, 'status', NULL, 8, '::1', 'Changed status to DELETED', NULL, NULL, NULL, NULL, '2026-02-10 15:00:33'),
(25, 1, NULL, 1, NULL, 'system_settings', NULL, 'update', NULL, 8, '::1', 'Updated key timezone', NULL, NULL, NULL, NULL, '2026-02-10 15:00:43'),
(26, 1, NULL, 1, NULL, 'customers', NULL, 'create', NULL, 5, '::1', 'Created customer nikhil n', NULL, NULL, NULL, NULL, '2026-02-10 15:02:12'),
(27, 1, NULL, 1, NULL, 'parts_master', NULL, 'create', NULL, 5, '::1', 'Created part ASD', NULL, NULL, NULL, NULL, '2026-02-10 15:11:32'),
(28, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_labor', NULL, 4, '::1', 'Added labor line to job card', NULL, NULL, NULL, NULL, '2026-02-10 15:23:56'),
(29, 1, NULL, 1, NULL, 'job_cards', NULL, 'update_labor', NULL, 4, '::1', 'Updated labor line #8', NULL, NULL, NULL, NULL, '2026-02-10 15:24:06'),
(30, 1, NULL, 1, NULL, 'job_cards', NULL, 'add_part', NULL, 4, '::1', 'Added part #1 to job card', NULL, NULL, NULL, NULL, '2026-02-10 15:26:18'),
(31, 1, NULL, 1, NULL, 'job_cards', NULL, 'status', NULL, 4, '::1', 'Status changed from OPEN to IN_PROGRESS', NULL, NULL, NULL, NULL, '2026-02-10 15:30:44'),
(32, 1, 1, 1, 'super_admin', 'billing', 'invoice', 'finalize', 'UI', 7, NULL, 'Finalized invoice INV-SMOKE-9001', '{\"invoice_status\":\"DRAFT\",\"payment_status\":\"UNPAID\",\"grand_total\":1180}', '{\"invoice_status\":\"FINALIZED\",\"payment_status\":\"UNPAID\",\"grand_total\":1180}', '{\"invoice_number\":\"INV-SMOKE-9001\",\"changes\":{\"invoice_status\":{\"from\":\"DRAFT\",\"to\":\"FINALIZED\"}}}', '8aed5d7364b3c431a61d1bdeb9c17d6a', '2026-02-10 16:52:22'),
(33, 1, 1, 1, 'super_admin', 'inventory', 'inventory_transfer', 'transfer', 'UI', 2, NULL, 'Transferred 1.00 of Engine Oil 5W30 (1L) (EO-5W30-1L) from garage 1 to garage 3', '{\"part_id\":1,\"from_garage_id\":1,\"to_garage_id\":3,\"from_stock_qty\":50,\"to_stock_qty\":0}', '{\"part_id\":1,\"from_garage_id\":1,\"to_garage_id\":3,\"from_stock_qty\":49,\"to_stock_qty\":1,\"transfer_ref\":\"TRF-260210222302-169\",\"quantity\":1,\"status_code\":\"POSTED\"}', '{\"allow_negative\":false,\"changes\":{\"from_stock_qty\":{\"from\":50,\"to\":49},\"to_stock_qty\":{\"from\":0,\"to\":1},\"transfer_ref\":{\"from\":null,\"to\":\"TRF-260210222302-169\"},\"quantity\":{\"from\":null,\"to\":1},\"status_code\":{\"from\":null,\"to\":\"POSTED\"}}}', 'fc10638baf28e4dd8cfdddc29394a4a6', '2026-02-10 16:53:02'),
(34, 1, 1, 1, 'super_admin', 'job_cards', 'job_card', 'status_change', 'UI', 4, NULL, 'Status changed from IN_PROGRESS to COMPLETED', '{\"status\":\"IN_PROGRESS\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"\",\"cancel_note\":\"\"}', '{\"status\":\"COMPLETED\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"2026-02-10 22:23:53\",\"cancel_note\":\"\"}', '{\"workflow_note\":\"Smoke complete\",\"inventory_warning_count\":0,\"changes\":{\"status\":{\"from\":\"IN_PROGRESS\",\"to\":\"COMPLETED\"},\"completed_at\":{\"from\":\"\",\"to\":\"2026-02-10 22:23:53\"}}}', '2cf95a2a8fb916105aa6cce561332671', '2026-02-10 16:53:53'),
(35, 1, 1, 1, 'super_admin', 'inventory', 'inventory_movement', 'stock_out', 'JOB-CLOSE', 1, NULL, 'Auto stock OUT posted from job close #4', '{\"part_id\":1,\"stock_qty\":49}', '{\"part_id\":1,\"stock_qty\":47,\"movement_type\":\"OUT\",\"movement_qty\":2}', '{\"job_card_id\":4,\"part_name\":\"Engine Oil 5W30 (1L)\",\"part_sku\":\"EO-5W30-1L\",\"changes\":{\"stock_qty\":{\"from\":49,\"to\":47},\"movement_type\":{\"from\":null,\"to\":\"OUT\"},\"movement_qty\":{\"from\":null,\"to\":2}}}', 'baa682157cc2c79c70a70394551fd21b', '2026-02-10 16:54:16'),
(36, 1, 1, 1, 'super_admin', 'job_cards', 'job_card', 'close', 'JOB-CLOSE', 4, NULL, 'Status changed from COMPLETED to CLOSED', '{\"status\":\"COMPLETED\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"2026-02-10 22:23:53\",\"cancel_note\":\"\"}', '{\"status\":\"CLOSED\",\"status_code\":\"ACTIVE\",\"closed_at\":\"2026-02-10 22:24:16\",\"completed_at\":\"2026-02-10 22:23:53\",\"cancel_note\":\"\"}', '{\"workflow_note\":\"Smoke close\",\"inventory_warning_count\":0,\"changes\":{\"status\":{\"from\":\"COMPLETED\",\"to\":\"CLOSED\"},\"closed_at\":{\"from\":\"\",\"to\":\"2026-02-10 22:24:16\"}}}', 'baa682157cc2c79c70a70394551fd21b', '2026-02-10 16:54:16'),
(37, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, NULL, 'Exported Inventory Movements data.', '{\"requested\":true}', '{\"module\":\"inventory\",\"format\":\"CSV\",\"row_count\":0}', '{\"garage_scope\":\"Guru Auto Cars - Pune East (PUNE-EAST)\",\"fy_label\":\"2026-27\",\"from\":\"2026-04-01\",\"to\":\"2026-04-01\",\"include_draft\":false,\"include_cancelled\":false,\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"inventory\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":0}}}', 'ecddc2eaeaa25de16f697f5963e0937d', '2026-02-10 16:55:09'),
(38, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, NULL, 'Exported Inventory Movements data.', '{\"requested\":true}', '{\"module\":\"inventory\",\"format\":\"CSV\",\"row_count\":1}', '{\"garage_scope\":\"Guru Auto Cars - Pune East (PUNE-EAST)\",\"fy_label\":\"2025-26\",\"from\":\"2026-02-10\",\"to\":\"2026-02-10\",\"include_draft\":false,\"include_cancelled\":false,\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"inventory\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":1}}}', '8f989b723d15ad1e9eed9c07e49ef9e1', '2026-02-10 16:56:38'),
(39, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, '::1', 'Exported Invoices data.', '{\"requested\":true}', '{\"module\":\"invoices\",\"format\":\"CSV\",\"row_count\":3}', '{\"garage_scope\":\"Guru Auto Cars - Pune Main (PUNE-MAIN)\",\"fy_label\":\"2025-26\",\"from\":\"2025-04-01\",\"to\":\"2026-02-11\",\"include_draft\":false,\"include_cancelled\":false,\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"invoices\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":3}}}', '2da99a45ef42eb31176be0903c09fec3', '2026-02-10 19:03:34'),
(40, 1, 1, 1, 'super_admin', 'job_cards', 'job_card', 'create', 'UI', 9, '::1', 'Created job card JOB-2602-1006', '{\"exists\":false}', '{\"id\":9,\"job_number\":\"JOB-2602-1006\",\"status\":\"OPEN\",\"status_code\":\"ACTIVE\",\"priority\":\"MEDIUM\",\"customer_id\":1,\"vehicle_id\":1}', '{\"assigned_count\":0,\"changes\":{\"exists\":{\"from\":false,\"to\":null},\"id\":{\"from\":null,\"to\":9},\"job_number\":{\"from\":null,\"to\":\"JOB-2602-1006\"},\"status\":{\"from\":null,\"to\":\"OPEN\"},\"status_code\":{\"from\":null,\"to\":\"ACTIVE\"},\"priority\":{\"from\":null,\"to\":\"MEDIUM\"},\"customer_id\":{\"from\":null,\"to\":1},\"vehicle_id\":{\"from\":null,\"to\":1}}}', 'e674ea984ee089816dabb8e6ad5a92e5', '2026-02-10 19:05:56'),
(41, 1, 1, 1, 'super_admin', 'customers', 'customer', 'create_inline', 'UI-AJAX', 6, '::1', 'Created customer nikhil nikji via inline modal', '{\"exists\":false}', '{\"id\":6,\"full_name\":\"nikhil nikji\",\"phone\":\"0\",\"status_code\":\"ACTIVE\"}', '{\"changes\":{\"exists\":{\"from\":false,\"to\":null},\"id\":{\"from\":null,\"to\":6},\"full_name\":{\"from\":null,\"to\":\"nikhil nikji\"},\"phone\":{\"from\":null,\"to\":\"0\"},\"status_code\":{\"from\":null,\"to\":\"ACTIVE\"}}}', '066973e53394c436253c2c9c07ec742b', '2026-02-10 20:12:08'),
(42, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_labor', 'UI', 9, '::1', 'Added labor line to job card', NULL, NULL, NULL, 'f3b437700d53a16f5e31370745d5f8eb', '2026-02-10 20:15:32'),
(43, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_part', 'UI', 9, '::1', 'Added part #3 to job card', NULL, NULL, NULL, 'e8a7a79d66033c9955a282588644bd1a', '2026-02-10 20:16:26'),
(44, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_labor', 'UI', 9, '::1', 'Added labor line to job card', NULL, NULL, NULL, 'b9428e95f8e64928f6d8376b0766acc6', '2026-02-10 20:16:33'),
(45, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'delete_part', 'UI', 9, '::1', 'Deleted part line #14', NULL, NULL, NULL, '9f2d9e226c0cfec11f1ce662b6629d0f', '2026-02-10 20:16:49'),
(46, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'delete_labor', 'UI', 9, '::1', 'Deleted labor line #10', NULL, NULL, NULL, '654fa711941908d510b8e80b5f41dbee', '2026-02-10 20:16:52'),
(47, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'delete_labor', 'UI', 9, '::1', 'Deleted labor line #9', NULL, NULL, NULL, 'c9699471e1ee48b8243613fb6dd22bc3', '2026-02-10 20:16:55'),
(48, 1, 1, 1, 'super_admin', 'services', 'service', 'update', 'UI', 1, '::1', 'Updated service', '{\"category_id\":0,\"service_name\":\"fszdf\",\"status_code\":\"ACTIVE\",\"default_rate\":0,\"gst_rate\":18}', '{\"category_id\":4,\"category_name\":\"AC\",\"service_name\":\"fszdf\",\"status_code\":\"ACTIVE\",\"default_rate\":0,\"gst_rate\":18}', '{\"changes\":{\"category_id\":{\"from\":0,\"to\":4},\"category_name\":{\"from\":null,\"to\":\"AC\"}}}', 'e09cc13fd5457c945b9f463f0417e327', '2026-02-10 20:24:18'),
(49, 1, 1, 1, 'super_admin', 'vendors', 'vendors', 'create', 'UI', 1, '::1', 'Created vendor ZSXDFV', NULL, NULL, NULL, 'cfcad676ccbdefebb94b5d076b39b900', '2026-02-10 20:50:40'),
(50, 1, 1, 1, 'super_admin', 'inventory', 'inventory_movement', 'stock_in', 'UI', 1, NULL, 'Stock IN posted for Engine Oil 5W30 (1L) (EO-5W30-1L), delta 1.25 at garage 1', '{\"garage_id\":1,\"part_id\":1,\"stock_qty\":47}', '{\"garage_id\":1,\"part_id\":1,\"stock_qty\":48.25,\"movement_type\":\"IN\",\"movement_qty\":1.25,\"reference_type\":\"PURCHASE\",\"reference_id\":1}', '{\"allow_negative\":false,\"delta\":1.25,\"changes\":{\"stock_qty\":{\"from\":47,\"to\":48.25},\"movement_type\":{\"from\":null,\"to\":\"IN\"},\"movement_qty\":{\"from\":null,\"to\":1.25},\"reference_type\":{\"from\":null,\"to\":\"PURCHASE\"},\"reference_id\":{\"from\":null,\"to\":1}}}', 'd077e069a82660f4f3b242642c1ade0f', '2026-02-10 20:59:18'),
(51, 1, 1, 1, 'super_admin', 'purchases', 'purchase', 'assign_finalize', 'UI', 1, NULL, 'Assigned and finalized unassigned purchase #1', NULL, '{\"purchase_id\":1,\"vendor_id\":1,\"invoice_number\":\"SMK-ASSIGN-20260211022940\",\"purchase_status\":\"FINALIZED\",\"assignment_status\":\"ASSIGNED\",\"payment_status\":\"UNPAID\"}', '{\"changes\":{\"purchase_id\":{\"from\":null,\"to\":1},\"vendor_id\":{\"from\":null,\"to\":1},\"invoice_number\":{\"from\":null,\"to\":\"SMK-ASSIGN-20260211022940\"},\"purchase_status\":{\"from\":null,\"to\":\"FINALIZED\"},\"assignment_status\":{\"from\":null,\"to\":\"ASSIGNED\"},\"payment_status\":{\"from\":null,\"to\":\"UNPAID\"}}}', '889d5220c6c875725ba546e7b14427b5', '2026-02-10 20:59:40'),
(52, 1, 1, 1, 'super_admin', 'purchases', 'purchase', 'create', 'UI', 2, NULL, 'Created purchase #2 (FINALIZED)', NULL, '{\"purchase_id\":2,\"vendor_id\":1,\"invoice_number\":\"SMK-VENDOR-20260211022959\",\"purchase_status\":\"FINALIZED\",\"payment_status\":\"UNPAID\",\"taxable_amount\":75,\"gst_amount\":13.5,\"grand_total\":88.5}', '{\"item_count\":1,\"garage_id\":1,\"changes\":{\"purchase_id\":{\"from\":null,\"to\":2},\"vendor_id\":{\"from\":null,\"to\":1},\"invoice_number\":{\"from\":null,\"to\":\"SMK-VENDOR-20260211022959\"},\"purchase_status\":{\"from\":null,\"to\":\"FINALIZED\"},\"payment_status\":{\"from\":null,\"to\":\"UNPAID\"},\"taxable_amount\":{\"from\":null,\"to\":75},\"gst_amount\":{\"from\":null,\"to\":13.5},\"grand_total\":{\"from\":null,\"to\":88.5}}}', '266e059db656ea6f4eb5fb2f7a99ec3f', '2026-02-10 20:59:59'),
(53, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_catalog', 'create_spec', 'UI', 2, '::1', 'Created spec oil seal', NULL, NULL, NULL, '1f110e182beb99bbb458ae2477a23f9f', '2026-02-10 21:19:05'),
(54, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_catalog', 'status_spec', 'UI', 1, '::1', 'Changed status to INACTIVE', NULL, NULL, NULL, 'aff5948ce6d6a75ec56aca9858a054b5', '2026-02-10 21:19:09'),
(55, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_catalog', 'status_spec', 'UI', 1, '::1', 'Changed status to ACTIVE', NULL, NULL, NULL, '28910fddb2e9ea85fb8fbc66bfedf3a7', '2026-02-10 21:19:13'),
(56, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_catalog', 'update_spec', 'UI', 1, '::1', 'Updated spec oil seal', NULL, NULL, NULL, 'c943ec970fe3c30c2805cf8453eb84de', '2026-02-10 21:19:18'),
(57, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 1, '::1', 'Temporary stock TMP-260211030035-341 created for Air Filter - Activa (AF-ACTIVA), qty 10.00', NULL, '{\"temp_ref\":\"TMP-260211030035-341\",\"garage_id\":1,\"part_id\":3,\"quantity\":10,\"status_code\":\"OPEN\",\"notes\":\"10\"}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030035-341\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":3},\"quantity\":{\"from\":null,\"to\":10},\"status_code\":{\"from\":null,\"to\":\"OPEN\"},\"notes\":{\"from\":null,\"to\":\"10\"}}}', 'd16fd7956b7a84080cbe860daa428127', '2026-02-10 21:30:35'),
(58, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_consumed', 'UI', 1, '::1', 'Temporary stock TMP-260211030035-341 resolved as CONSUMED', '{\"temp_ref\":\"TMP-260211030035-341\",\"status_code\":\"OPEN\"}', '{\"temp_ref\":\"TMP-260211030035-341\",\"status_code\":\"CONSUMED\",\"purchase_id\":null,\"resolution_notes\":null}', '{\"part_id\":3,\"part_name\":\"Air Filter - Activa\",\"part_sku\":\"AF-ACTIVA\",\"quantity\":10,\"stock_before_purchase\":null,\"stock_after_purchase\":null,\"changes\":{\"status_code\":{\"from\":\"OPEN\",\"to\":\"CONSUMED\"}}}', 'b463a736e3938c788111f6281879ca01', '2026-02-10 21:31:35'),
(59, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 2, '::1', 'Temporary stock TMP-260211030230-053 created for xbgxcvb (XGDB), qty 1000.00', NULL, '{\"temp_ref\":\"TMP-260211030230-053\",\"garage_id\":1,\"part_id\":4,\"quantity\":1000,\"status_code\":\"OPEN\",\"notes\":null}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030230-053\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":4},\"quantity\":{\"from\":null,\"to\":1000},\"status_code\":{\"from\":null,\"to\":\"OPEN\"}}}', '1e29292b88dfb71f0a66a4c84b472d32', '2026-02-10 21:32:30'),
(60, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 3, NULL, 'Temporary stock TMP-260211030249-493 created for Engine Oil 5W30 (1L) (EO-5W30-1L), qty 2.00', NULL, '{\"temp_ref\":\"TMP-260211030249-493\",\"garage_id\":1,\"part_id\":1,\"quantity\":2,\"status_code\":\"OPEN\",\"notes\":\"SMOKE_TSM_20260211030107_RET\"}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030249-493\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":1},\"quantity\":{\"from\":null,\"to\":2},\"status_code\":{\"from\":null,\"to\":\"OPEN\"},\"notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_RET\"}}}', '50247b0de505f5429297c1b847b27229', '2026-02-10 21:32:49'),
(61, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_returned', 'UI', 3, NULL, 'Temporary stock TMP-260211030249-493 resolved as RETURNED', '{\"temp_ref\":\"TMP-260211030249-493\",\"status_code\":\"OPEN\"}', '{\"temp_ref\":\"TMP-260211030249-493\",\"status_code\":\"RETURNED\",\"purchase_id\":null,\"resolution_notes\":\"SMOKE_TSM_20260211030107_RETURNED_OK\"}', '{\"part_id\":1,\"part_name\":\"Engine Oil 5W30 (1L)\",\"part_sku\":\"EO-5W30-1L\",\"quantity\":2,\"stock_before_purchase\":null,\"stock_after_purchase\":null,\"changes\":{\"status_code\":{\"from\":\"OPEN\",\"to\":\"RETURNED\"},\"resolution_notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_RETURNED_OK\"}}}', 'd45162e007da3a9c135afbf34452fa78', '2026-02-10 21:33:12'),
(62, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_purchased', 'UI', 2, '::1', 'Temporary stock TMP-260211030230-053 resolved as PURCHASED', '{\"temp_ref\":\"TMP-260211030230-053\",\"status_code\":\"OPEN\"}', '{\"temp_ref\":\"TMP-260211030230-053\",\"status_code\":\"PURCHASED\",\"purchase_id\":3,\"resolution_notes\":null}', '{\"part_id\":4,\"part_name\":\"xbgxcvb\",\"part_sku\":\"XGDB\",\"quantity\":1000,\"stock_before_purchase\":0,\"stock_after_purchase\":1000,\"changes\":{\"status_code\":{\"from\":\"OPEN\",\"to\":\"PURCHASED\"},\"purchase_id\":{\"from\":null,\"to\":3}}}', '5169099adf5fe4105420c4c5a8b02ce6', '2026-02-10 21:33:22'),
(63, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 4, NULL, 'Temporary stock TMP-260211030343-584 created for Engine Oil 5W30 (1L) (EO-5W30-1L), qty 3.00', NULL, '{\"temp_ref\":\"TMP-260211030343-584\",\"garage_id\":1,\"part_id\":1,\"quantity\":3,\"status_code\":\"OPEN\",\"notes\":\"SMOKE_TSM_20260211030107_PUR\"}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030343-584\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":1},\"quantity\":{\"from\":null,\"to\":3},\"status_code\":{\"from\":null,\"to\":\"OPEN\"},\"notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_PUR\"}}}', 'd7af98f994db54b95d356e2451a225f1', '2026-02-10 21:33:43'),
(64, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_purchased', 'UI', 4, NULL, 'Temporary stock TMP-260211030343-584 resolved as PURCHASED', '{\"temp_ref\":\"TMP-260211030343-584\",\"status_code\":\"OPEN\"}', '{\"temp_ref\":\"TMP-260211030343-584\",\"status_code\":\"PURCHASED\",\"purchase_id\":4,\"resolution_notes\":\"SMOKE_TSM_20260211030107_PURCHASED_OK\"}', '{\"part_id\":1,\"part_name\":\"Engine Oil 5W30 (1L)\",\"part_sku\":\"EO-5W30-1L\",\"quantity\":3,\"stock_before_purchase\":49,\"stock_after_purchase\":52,\"changes\":{\"status_code\":{\"from\":\"OPEN\",\"to\":\"PURCHASED\"},\"purchase_id\":{\"from\":null,\"to\":4},\"resolution_notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_PURCHASED_OK\"}}}', '0d63103ff38ca842a0d1e8f1a093699d', '2026-02-10 21:34:09'),
(65, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 5, NULL, 'Temporary stock TMP-260211030447-233 created for Engine Oil 5W30 (1L) (EO-5W30-1L), qty 1.00', NULL, '{\"temp_ref\":\"TMP-260211030447-233\",\"garage_id\":1,\"part_id\":1,\"quantity\":1,\"status_code\":\"OPEN\",\"notes\":\"SMOKE_TSM_20260211030107_CONS\"}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030447-233\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":1},\"quantity\":{\"from\":null,\"to\":1},\"status_code\":{\"from\":null,\"to\":\"OPEN\"},\"notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_CONS\"}}}', 'bdc3db770b1fa71cc01257ecb0277cfd', '2026-02-10 21:34:47'),
(66, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 6, '::1', 'Temporary stock TMP-260211030451-240 created for Oil Filter - Swift (OF-SWIFT), qty 1.00', NULL, '{\"temp_ref\":\"TMP-260211030451-240\",\"garage_id\":1,\"part_id\":2,\"quantity\":1,\"status_code\":\"OPEN\",\"notes\":null}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030451-240\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":2},\"quantity\":{\"from\":null,\"to\":1},\"status_code\":{\"from\":null,\"to\":\"OPEN\"}}}', 'c7f4d335066e7ad758dac92f026ddc9a', '2026-02-10 21:34:51'),
(67, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_consumed', 'UI', 5, NULL, 'Temporary stock TMP-260211030447-233 resolved as CONSUMED', '{\"temp_ref\":\"TMP-260211030447-233\",\"status_code\":\"OPEN\"}', '{\"temp_ref\":\"TMP-260211030447-233\",\"status_code\":\"CONSUMED\",\"purchase_id\":null,\"resolution_notes\":\"SMOKE_TSM_20260211030107_CONSUMED_OK\"}', '{\"part_id\":1,\"part_name\":\"Engine Oil 5W30 (1L)\",\"part_sku\":\"EO-5W30-1L\",\"quantity\":1,\"stock_before_purchase\":null,\"stock_after_purchase\":null,\"changes\":{\"status_code\":{\"from\":\"OPEN\",\"to\":\"CONSUMED\"},\"resolution_notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_CONSUMED_OK\"}}}', '47cd7d1491e975169ff3c14ea0be9229', '2026-02-10 21:35:10'),
(68, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_consumed', 'UI', 6, '::1', 'Temporary stock TMP-260211030451-240 resolved as CONSUMED', '{\"temp_ref\":\"TMP-260211030451-240\",\"status_code\":\"OPEN\"}', '{\"temp_ref\":\"TMP-260211030451-240\",\"status_code\":\"CONSUMED\",\"purchase_id\":null,\"resolution_notes\":null}', '{\"part_id\":2,\"part_name\":\"Oil Filter - Swift\",\"part_sku\":\"OF-SWIFT\",\"quantity\":1,\"stock_before_purchase\":null,\"stock_after_purchase\":null,\"changes\":{\"status_code\":{\"from\":\"OPEN\",\"to\":\"CONSUMED\"}}}', '567ca8982c1c78444de611dd839afcad', '2026-02-10 21:35:12'),
(69, 1, 1, 1, 'super_admin', 'inventory', 'temp_stock_entry', 'temp_in', 'UI', 7, NULL, 'Temporary stock TMP-260211030538-343 created for Engine Oil 5W30 (1L) (EO-5W30-1L), qty 4.00', NULL, '{\"temp_ref\":\"TMP-260211030538-343\",\"garage_id\":1,\"part_id\":1,\"quantity\":4,\"status_code\":\"OPEN\",\"notes\":\"SMOKE_TSM_20260211030107_OPEN_ONLY\"}', '{\"changes\":{\"temp_ref\":{\"from\":null,\"to\":\"TMP-260211030538-343\"},\"garage_id\":{\"from\":null,\"to\":1},\"part_id\":{\"from\":null,\"to\":1},\"quantity\":{\"from\":null,\"to\":4},\"status_code\":{\"from\":null,\"to\":\"OPEN\"},\"notes\":{\"from\":null,\"to\":\"SMOKE_TSM_20260211030107_OPEN_ONLY\"}}}', 'c8b6a2e39dd13a11e865b168ef9cc308', '2026-02-10 21:35:38'),
(70, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_labor', 'UI', 9, '::1', 'Added labor line to job card', NULL, NULL, NULL, '12f2419035987a75c3aaccf662b962ac', '2026-02-10 21:57:53'),
(71, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_labor', 'UI', 9, '::1', 'Added labor line to job card', NULL, NULL, NULL, '0de5b15ace7979c8f0941a81f4b807ef', '2026-02-10 21:58:10'),
(72, 1, 1, 1, 'super_admin', 'estimates', 'estimate', 'create', 'UI', 1, '::1', 'Created estimate EST-2602-1001', '{\"exists\":false}', '{\"estimate_number\":\"EST-2602-1001\",\"estimate_status\":\"DRAFT\",\"customer_id\":1,\"vehicle_id\":1}', '{\"changes\":{\"exists\":{\"from\":false,\"to\":null},\"estimate_number\":{\"from\":null,\"to\":\"EST-2602-1001\"},\"estimate_status\":{\"from\":null,\"to\":\"DRAFT\"},\"customer_id\":{\"from\":null,\"to\":1},\"vehicle_id\":{\"from\":null,\"to\":1}}}', 'e6a1e36d3fe9a126dd0089632eb82027', '2026-02-10 22:24:59'),
(73, 1, 1, 1, 'super_admin', 'estimates', 'estimate', 'create', 'UI', 2, '::1', 'Created estimate EST-2602-1002', '{\"exists\":false}', '{\"estimate_number\":\"EST-2602-1002\",\"estimate_status\":\"DRAFT\",\"customer_id\":2,\"vehicle_id\":2}', '{\"changes\":{\"exists\":{\"from\":false,\"to\":null},\"estimate_number\":{\"from\":null,\"to\":\"EST-2602-1002\"},\"estimate_status\":{\"from\":null,\"to\":\"DRAFT\"},\"customer_id\":{\"from\":null,\"to\":2},\"vehicle_id\":{\"from\":null,\"to\":2}}}', 'a26a1dd11985554605c5ebc2c3c49bf6', '2026-02-10 22:26:28'),
(74, 1, 1, 1, 'super_admin', 'estimates', 'estimates', 'add_service', 'UI', 2, '::1', 'Added service line to estimate', NULL, NULL, NULL, '60bb475d5ccff2111500f79ccbe10717', '2026-02-10 22:26:49'),
(75, 1, 1, 1, 'super_admin', 'estimates', 'estimates', 'update_service', 'UI', 2, '::1', 'Updated service line #1', NULL, NULL, NULL, 'fd096a77ce85e6f69d9eb29f771825f5', '2026-02-10 22:27:10'),
(76, 1, 1, 1, 'super_admin', 'estimates', 'estimates', 'add_part', 'UI', 2, '::1', 'Added part line to estimate', NULL, NULL, NULL, '8a428b6550b8433ddcc6f1715d078f33', '2026-02-10 22:27:22'),
(77, 1, 1, 1, 'super_admin', 'estimates', 'estimates', 'update', 'UI', 2, '::1', 'Updated estimate details', NULL, NULL, NULL, '96ef3837b01bfbf531afac15230cf4c0', '2026-02-10 22:27:28'),
(78, 1, 1, 1, 'super_admin', 'estimates', 'estimates', 'status_change', 'UI', 2, '::1', 'Changed estimate status from DRAFT to APPROVED', NULL, NULL, NULL, '3a6682b557a5d1b453abc6b477e92230', '2026-02-10 22:27:58'),
(79, 1, 1, 1, 'super_admin', 'estimates', 'estimate', 'convert', 'UI', 2, '::1', 'Converted estimate EST-2602-1002 to job card JOB-2602-1007', '{\"estimate_status\":\"APPROVED\",\"converted_job_card_id\":0}', '{\"estimate_status\":\"CONVERTED\",\"converted_job_card_id\":10}', '{\"job_number\":\"JOB-2602-1007\",\"service_line_count\":1,\"part_line_count\":1,\"changes\":{\"estimate_status\":{\"from\":\"APPROVED\",\"to\":\"CONVERTED\"},\"converted_job_card_id\":{\"from\":0,\"to\":10}}}', '1bb94ff7c154e43d6fdd82aab5d56919', '2026-02-10 22:28:08'),
(80, 1, 1, 1, 'super_admin', 'auth', 'user_session', 'login', 'UI', 1, '::1', 'User login successful.', '{\"authenticated\":false}', '{\"authenticated\":true}', '{\"changes\":{\"authenticated\":{\"from\":false,\"to\":true}}}', '3925352e84d0e39d72dd587db173ea7e', '2026-02-11 17:54:21'),
(81, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, '::1', 'Exported report CSV: billing_gst_summary_20260211_235406.csv', '{\"requested\":true}', '{\"module\":\"reports_billing\",\"format\":\"CSV\",\"row_count\":1}', '{\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"reports_billing\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":1}}}', '642062d1c531028ab981714ee40ffa8d', '2026-02-11 18:24:06'),
(82, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_labor', 'UI', 10, '::1', 'Added labor line to job card', NULL, NULL, NULL, 'b6bfc9c17b158cb34d8638083fea4638', '2026-02-11 18:35:58'),
(83, 1, 1, 1, 'super_admin', 'job_cards', 'job_card', 'status_change', 'UI', 10, '::1', 'Status changed from OPEN to IN_PROGRESS', '{\"status\":\"OPEN\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"\",\"cancel_note\":\"\"}', '{\"status\":\"IN_PROGRESS\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"\",\"cancel_note\":\"\"}', '{\"workflow_note\":null,\"inventory_warning_count\":0,\"changes\":{\"status\":{\"from\":\"OPEN\",\"to\":\"IN_PROGRESS\"}}}', 'd21e3aba7bfa728b66daffdae009b2ab', '2026-02-11 18:36:17'),
(84, 1, 1, 1, 'super_admin', 'job_cards', 'job_card', 'status_change', 'UI', 10, '::1', 'Status changed from IN_PROGRESS to COMPLETED', '{\"status\":\"IN_PROGRESS\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"\",\"cancel_note\":\"\"}', '{\"status\":\"COMPLETED\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"2026-02-12 00:06:34\",\"cancel_note\":\"\"}', '{\"workflow_note\":null,\"inventory_warning_count\":0,\"changes\":{\"status\":{\"from\":\"IN_PROGRESS\",\"to\":\"COMPLETED\"},\"completed_at\":{\"from\":\"\",\"to\":\"2026-02-12 00:06:34\"}}}', 'd2fcb71e2d702237ed1cd91e08f2deaa', '2026-02-11 18:36:34'),
(85, 1, 1, 1, 'super_admin', 'inventory', 'inventory_movement', 'stock_out', 'JOB-CLOSE', 5, '::1', 'Auto stock OUT posted from job close #10', '{\"part_id\":5,\"stock_qty\":0}', '{\"part_id\":5,\"stock_qty\":-1,\"movement_type\":\"OUT\",\"movement_qty\":1}', '{\"job_card_id\":10,\"part_name\":\"asd\",\"part_sku\":\"ASD\",\"changes\":{\"stock_qty\":{\"from\":0,\"to\":-1},\"movement_type\":{\"from\":null,\"to\":\"OUT\"},\"movement_qty\":{\"from\":null,\"to\":1}}}', 'a6d34bccc0c41fab5d69bed967630d18', '2026-02-11 18:37:29'),
(86, 1, 1, 1, 'super_admin', 'job_cards', 'job_card', 'close', 'JOB-CLOSE', 10, '::1', 'Status changed from COMPLETED to CLOSED', '{\"status\":\"COMPLETED\",\"status_code\":\"ACTIVE\",\"closed_at\":\"\",\"completed_at\":\"2026-02-12 00:06:34\",\"cancel_note\":\"\"}', '{\"status\":\"CLOSED\",\"status_code\":\"ACTIVE\",\"closed_at\":\"2026-02-12 00:07:29\",\"completed_at\":\"2026-02-12 00:06:34\",\"cancel_note\":\"\"}', '{\"workflow_note\":null,\"inventory_warning_count\":1,\"changes\":{\"status\":{\"from\":\"COMPLETED\",\"to\":\"CLOSED\"},\"closed_at\":{\"from\":\"\",\"to\":\"2026-02-12 00:07:29\"}}}', 'a6d34bccc0c41fab5d69bed967630d18', '2026-02-11 18:37:29'),
(87, 1, 1, 1, 'super_admin', 'billing', 'invoice', 'create', 'UI', 8, '::1', 'Created draft invoice INV/2025-26/05003 from Job JOB-2602-1007', '{\"exists\":false}', '{\"invoice_number\":\"INV\\/2025-26\\/05003\",\"invoice_status\":\"DRAFT\",\"payment_status\":\"UNPAID\",\"grand_total\":1770,\"taxable_amount\":1500,\"total_tax_amount\":270,\"job_card_id\":10}', '{\"financial_year_label\":\"2025-26\",\"line_count\":3,\"changes\":{\"exists\":{\"from\":false,\"to\":null},\"invoice_number\":{\"from\":null,\"to\":\"INV\\/2025-26\\/05003\"},\"invoice_status\":{\"from\":null,\"to\":\"DRAFT\"},\"payment_status\":{\"from\":null,\"to\":\"UNPAID\"},\"grand_total\":{\"from\":null,\"to\":1770},\"taxable_amount\":{\"from\":null,\"to\":1500},\"total_tax_amount\":{\"from\":null,\"to\":270},\"job_card_id\":{\"from\":null,\"to\":10}}}', 'ebf1914cc16202d1c6b740e7aaedb6b0', '2026-02-11 18:37:50'),
(88, 1, 1, 1, 'super_admin', 'billing', 'invoice', 'finalize', 'UI', 8, '::1', 'Finalized invoice INV/2025-26/05003', '{\"invoice_status\":\"DRAFT\",\"payment_status\":\"UNPAID\",\"grand_total\":1770}', '{\"invoice_status\":\"FINALIZED\",\"payment_status\":\"UNPAID\",\"grand_total\":1770}', '{\"invoice_number\":\"INV\\/2025-26\\/05003\",\"changes\":{\"invoice_status\":{\"from\":\"DRAFT\",\"to\":\"FINALIZED\"}}}', '3edff8b4d0f474b4f9f2d4106aeedfc6', '2026-02-11 18:38:32'),
(89, 1, 1, 1, 'super_admin', 'auth', 'user_session', 'login', 'UI', 1, '::1', 'User login successful.', '{\"authenticated\":false}', '{\"authenticated\":true}', '{\"changes\":{\"authenticated\":{\"from\":false,\"to\":true}}}', 'a61de1777815ab67a6503b53a151f487', '2026-02-12 16:26:45'),
(90, 1, 1, 1, 'super_admin', 'outsourced_works', 'outsourced_works', 'payment_add', 'UI', 2, '::1', 'Recorded outsourced payment entry #1', NULL, NULL, NULL, 'ecf7409ab21de8b9c2c0bac8a18ccc24', '2026-02-12 16:29:02'),
(91, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, '::1', 'Exported report CSV: gst_sales_report_20260212_221315.csv', '{\"requested\":true}', '{\"module\":\"reports_gst\",\"format\":\"CSV\",\"row_count\":4}', '{\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"reports_gst\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":4}}}', '3ce41c85d88fd48b42172a5df2837dba', '2026-02-12 16:43:15'),
(92, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, '::1', 'Exported report CSV: gst_purchase_report_20260212_221316.csv', '{\"requested\":true}', '{\"module\":\"reports_gst\",\"format\":\"CSV\",\"row_count\":2}', '{\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"reports_gst\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":2}}}', '2d6e631db10ce52c69c9dc58b5d5ccf6', '2026-02-12 16:43:16'),
(93, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'delete_labor', 'UI', 9, '::1', 'Deleted labor line #12', NULL, NULL, NULL, 'fb4659a9c1ca44fd95b0e5d564ba52af', '2026-02-12 16:48:36'),
(94, 1, 1, 1, 'super_admin', 'purchases', 'purchase_payment', 'payment_add', 'UI', 2, '::1', 'Recorded purchase payment #1', NULL, '{\"purchase_id\":2,\"payment_id\":1,\"amount\":8.5,\"payment_mode\":\"CASH\"}', '{\"changes\":{\"purchase_id\":{\"from\":null,\"to\":2},\"payment_id\":{\"from\":null,\"to\":1},\"amount\":{\"from\":null,\"to\":8.5},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"}}}', 'f9c3aefaff07cc0fe4a414caa0aa534c', '2026-02-12 16:51:34'),
(95, 1, 1, 1, 'super_admin', 'purchases', 'purchase_payment', 'payment_add', 'UI', 2, '::1', 'Recorded purchase payment #2', NULL, '{\"purchase_id\":2,\"payment_id\":2,\"amount\":10,\"payment_mode\":\"CASH\"}', '{\"changes\":{\"purchase_id\":{\"from\":null,\"to\":2},\"payment_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":10},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"}}}', 'cfa1e1c22df4e27b79b4b7cdff6c0fe5', '2026-02-12 16:53:24'),
(96, 1, 1, 1, 'super_admin', 'purchases', 'purchase_payment', 'payment_reverse', 'UI', 2, '::1', 'Reversed purchase payment #1', NULL, '{\"purchase_id\":2,\"payment_id\":1,\"reversal_id\":3,\"reversal_amount\":-8.5}', '{\"changes\":{\"purchase_id\":{\"from\":null,\"to\":2},\"payment_id\":{\"from\":null,\"to\":1},\"reversal_id\":{\"from\":null,\"to\":3},\"reversal_amount\":{\"from\":null,\"to\":-8.5}}}', '61266acf2ca78e8a2ee74731e19cdebb', '2026-02-12 16:54:39'),
(97, 1, 1, 1, 'super_admin', 'purchases', 'purchase', 'create', 'UI', 5, '::1', 'Created purchase #5 (FINALIZED)', NULL, '{\"purchase_id\":5,\"vendor_id\":1,\"invoice_number\":\"11\",\"purchase_status\":\"FINALIZED\",\"payment_status\":\"UNPAID\",\"taxable_amount\":9000,\"gst_amount\":1620,\"grand_total\":10620}', '{\"item_count\":1,\"garage_id\":1,\"changes\":{\"purchase_id\":{\"from\":null,\"to\":5},\"vendor_id\":{\"from\":null,\"to\":1},\"invoice_number\":{\"from\":null,\"to\":\"11\"},\"purchase_status\":{\"from\":null,\"to\":\"FINALIZED\"},\"payment_status\":{\"from\":null,\"to\":\"UNPAID\"},\"taxable_amount\":{\"from\":null,\"to\":9000},\"gst_amount\":{\"from\":null,\"to\":1620},\"grand_total\":{\"from\":null,\"to\":10620}}}', '1d349eb85acf00cbdadb920d843afe60', '2026-02-12 16:55:59'),
(98, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, '::1', 'Exported Invoices data.', '{\"requested\":true}', '{\"module\":\"invoices\",\"format\":\"CSV\",\"row_count\":4}', '{\"garage_scope\":\"Guru Auto Cars - Pune Main (PUNE-MAIN)\",\"fy_label\":\"2025-26\",\"from\":\"2025-04-01\",\"to\":\"2026-02-12\",\"include_draft\":false,\"include_cancelled\":false,\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"invoices\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":4}}}', 'b6862f3fb7534943bcb9d349119922e9', '2026-02-12 17:07:43'),
(99, 1, 1, 1, 'super_admin', 'exports', 'data_export', 'download', 'UI', NULL, '::1', 'Exported Jobs data.', '{\"requested\":true}', '{\"module\":\"jobs\",\"format\":\"CSV\",\"row_count\":5}', '{\"garage_scope\":\"Guru Auto Cars - Pune Main (PUNE-MAIN)\",\"fy_label\":\"2025-26\",\"from\":\"2025-04-01\",\"to\":\"2026-02-12\",\"include_draft\":false,\"include_cancelled\":false,\"changes\":{\"requested\":{\"from\":true,\"to\":null},\"module\":{\"from\":null,\"to\":\"jobs\"},\"format\":{\"from\":null,\"to\":\"CSV\"},\"row_count\":{\"from\":null,\"to\":5}}}', 'ce59051984ca4f15775015f6390a0d15', '2026-02-12 17:07:45'),
(100, 1, 1, 1, 'super_admin', 'backup', 'backup_integrity', 'integrity_check', 'SYSTEM', NULL, '::1', 'Post-restore integrity check executed. Result: FAIL', '{\"integrity_state\":\"UNKNOWN\"}', '{\"integrity_state\":\"FAIL\",\"issues_count\":1}', '{\"issues\":[{\"code\":\"INVOICE_COUNTER_BEHIND\",\"severity\":\"FAIL\",\"count\":1}],\"changes\":{\"integrity_state\":{\"from\":\"UNKNOWN\",\"to\":\"FAIL\"},\"issues_count\":{\"from\":null,\"to\":1}}}', '80cbf5820013eda17fe4ebd1f663bd29', '2026-02-12 17:08:34'),
(101, 1, 1, 1, 'super_admin', 'customers', 'customers', 'status', 'UI', 5, '::1', 'Changed customer status to DELETED', NULL, NULL, NULL, 'f90bffeaa74411a850b7688f6155506f', '2026-02-12 17:09:56'),
(102, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 1, '::1', 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":15000,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":15000},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '159915a197a96ff141b3bd1f307852a8', '2026-02-12 17:32:14'),
(103, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 2, '::1', 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"General Expense\",\"amount\":10000,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"General Expense\"},\"amount\":{\"from\":null,\"to\":10000},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', 'ead870c0788be149f8501b128f50aca0', '2026-02-12 17:47:07'),
(104, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'reverse', 'MANUAL', 2, '::1', 'Expense reversed', NULL, '{\"reversal_id\":3}', '{\"changes\":{\"reversal_id\":{\"from\":null,\"to\":3}}}', 'c9ec7b508f2276d4b641afe577ac55f0', '2026-02-12 17:48:09'),
(105, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 4, '::1', 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":10000,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":10000},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '9289d17d31a026a7d86239a0f71d8d48', '2026-02-12 17:55:18'),
(106, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 5, '::1', 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '3fdd096ae3a04947c683fb77ae9249f7', '2026-02-12 17:55:53'),
(107, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'reverse', 'MANUAL', 5, '::1', 'Expense reversed', NULL, '{\"reversal_id\":6}', '{\"changes\":{\"reversal_id\":{\"from\":null,\"to\":6}}}', '55f1701c5e09c591af091cd18bc5461f', '2026-02-12 17:56:25'),
(108, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'reverse', 'MANUAL', 1, '::1', 'Expense reversed', NULL, '{\"reversal_id\":7}', '{\"changes\":{\"reversal_id\":{\"from\":null,\"to\":7}}}', '96e55e809c851265f76b64ff62e9b3b1', '2026-02-12 17:56:36'),
(109, 1, 1, 1, 'super_admin', 'auth', 'user_session', 'login', 'UI', 1, '::1', 'User login successful.', '{\"authenticated\":false}', '{\"authenticated\":true}', '{\"changes\":{\"authenticated\":{\"from\":false,\"to\":true}}}', '59a934e8b45b18a0bcd67d0381401cb2', '2026-02-12 20:33:41'),
(110, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'delete_labor', 'UI', 9, '::1', 'Deleted labor line #11', NULL, NULL, NULL, '6aa5a5ea00c3f5c810aeda12050dc3e0', '2026-02-12 21:07:33'),
(111, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'add_labor', 'UI', 9, '::1', 'Added labor line to job card', NULL, NULL, NULL, 'c797cd3cd3631f4422364a10da602040', '2026-02-12 21:07:48'),
(112, 1, 1, 1, 'super_admin', 'payroll', 'payroll_advance', 'advance_create', 'SYSTEM', 3, NULL, 'Recorded staff advance', NULL, '{\"user_id\":1,\"advance_date\":\"2026-02-13\",\"amount\":250}', '{\"changes\":{\"user_id\":{\"from\":null,\"to\":1},\"advance_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"amount\":{\"from\":null,\"to\":250}}}', 'f71acdcd50d032ccf6b84bbcb5472251', '2026-02-12 21:08:10'),
(113, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_sheet', 'salary_sheet_generate', 'SYSTEM', 2, NULL, 'Generated / synced salary sheet', NULL, '{\"salary_month\":\"2026-02\",\"auto_locked\":0,\"staff_count\":1}', '{\"changes\":{\"salary_month\":{\"from\":null,\"to\":\"2026-02\"},\"auto_locked\":{\"from\":null,\"to\":0},\"staff_count\":{\"from\":null,\"to\":1}}}', '688d92a881911b72cb2e9807af01b84e', '2026-02-12 21:08:10'),
(114, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":4,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":4},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '7d305d9b6043c57018186f152aa6440d', '2026-02-12 21:08:10'),
(115, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 8, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '7d305d9b6043c57018186f152aa6440d', '2026-02-12 21:08:10'),
(116, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":5,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":5},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', 'a6da074c034dd2cd8a5e25f45652283b', '2026-02-12 21:08:10'),
(117, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 9, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', 'a6da074c034dd2cd8a5e25f45652283b', '2026-02-12 21:08:10'),
(118, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 10, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":180,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":180},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '80bd252ad5ed0c333992ef7e5b1902ae', '2026-02-12 21:08:10'),
(119, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_create', 'SYSTEM', 10, NULL, 'Recorded manual expense', NULL, '{\"category_id\":2,\"amount\":180,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"category_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":180},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"},\"expense_date\":{\"from\":null,\"to\":\"2026-02-13\"}}}', '80bd252ad5ed0c333992ef7e5b1902ae', '2026-02-12 21:08:10'),
(120, 1, 1, 1, 'super_admin', 'job_cards', 'job_cards', 'soft_delete', 'UI', 9, '::1', 'Soft deleted job card', NULL, NULL, NULL, '468b9a30e6b4ed87268d5f29c8335661', '2026-02-12 21:08:22'),
(121, 1, 1, 1, 'super_admin', 'payroll', 'payroll_advance', 'advance_create', 'SYSTEM', 4, NULL, 'Recorded staff advance', NULL, '{\"user_id\":1,\"advance_date\":\"2026-02-13\",\"amount\":250}', '{\"changes\":{\"user_id\":{\"from\":null,\"to\":1},\"advance_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"amount\":{\"from\":null,\"to\":250}}}', 'c897788384ee86c7bf069c6d766ac1a9', '2026-02-12 21:09:09'),
(122, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_sheet', 'salary_sheet_generate', 'SYSTEM', 2, NULL, 'Generated / synced salary sheet', NULL, '{\"salary_month\":\"2026-02\",\"auto_locked\":0,\"staff_count\":1}', '{\"changes\":{\"salary_month\":{\"from\":null,\"to\":\"2026-02\"},\"auto_locked\":{\"from\":null,\"to\":0},\"staff_count\":{\"from\":null,\"to\":1}}}', '96f4aa375281931e077c041d9c23c93e', '2026-02-12 21:09:09'),
(123, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":6,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":6},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '4e3ba3065e03eeec454dd98f3c12140b', '2026-02-12 21:09:09'),
(124, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 11, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '4e3ba3065e03eeec454dd98f3c12140b', '2026-02-12 21:09:09'),
(125, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":7,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":7},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '4ef5bc2ce4eca9716f56488ad0b82cf3', '2026-02-12 21:09:10'),
(126, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 12, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '4ef5bc2ce4eca9716f56488ad0b82cf3', '2026-02-12 21:09:10');
INSERT INTO `audit_logs` (`id`, `company_id`, `garage_id`, `user_id`, `role_key`, `module_name`, `entity_name`, `action_name`, `source_channel`, `reference_id`, `ip_address`, `details`, `before_snapshot`, `after_snapshot`, `metadata_json`, `request_id`, `created_at`) VALUES
(127, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 13, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":180,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":180},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '303bb29c2c8c86625c83e2265c52f5f3', '2026-02-12 21:09:10'),
(128, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_create', 'SYSTEM', 13, NULL, 'Recorded manual expense', NULL, '{\"category_id\":2,\"amount\":180,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"category_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":180},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"},\"expense_date\":{\"from\":null,\"to\":\"2026-02-13\"}}}', '303bb29c2c8c86625c83e2265c52f5f3', '2026-02-12 21:09:10'),
(129, 1, 1, 1, 'super_admin', 'payroll', 'payroll_advance', 'advance_create', 'SYSTEM', 5, NULL, 'Recorded staff advance', NULL, '{\"user_id\":1,\"advance_date\":\"2026-02-13\",\"amount\":250}', '{\"changes\":{\"user_id\":{\"from\":null,\"to\":1},\"advance_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"amount\":{\"from\":null,\"to\":250}}}', '9465d583df58d19ed4a67c8ae4e05b30', '2026-02-12 21:11:28'),
(130, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_sheet', 'salary_sheet_generate', 'SYSTEM', 2, NULL, 'Generated / synced salary sheet', NULL, '{\"salary_month\":\"2026-02\",\"auto_locked\":0,\"staff_count\":1}', '{\"changes\":{\"salary_month\":{\"from\":null,\"to\":\"2026-02\"},\"auto_locked\":{\"from\":null,\"to\":0},\"staff_count\":{\"from\":null,\"to\":1}}}', '923a43b4cd519f62ebcdb5fb7de8ec2a', '2026-02-12 21:11:28'),
(131, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":8,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":8},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '384c217fc10465859c65dd66b8f18b81', '2026-02-12 21:11:28'),
(132, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 14, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '384c217fc10465859c65dd66b8f18b81', '2026-02-12 21:11:28'),
(133, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":9,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":9},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '51319220349bf440d281a85daddc78ce', '2026-02-12 21:11:29'),
(134, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 15, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '51319220349bf440d281a85daddc78ce', '2026-02-12 21:11:29'),
(135, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 16, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":180,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":180},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '72a0cbe93e4355030d57e718b9a1c5e2', '2026-02-12 21:11:29'),
(136, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_create', 'SYSTEM', 16, NULL, 'Recorded manual expense', NULL, '{\"category_id\":2,\"amount\":180,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"category_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":180},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"},\"expense_date\":{\"from\":null,\"to\":\"2026-02-13\"}}}', '72a0cbe93e4355030d57e718b9a1c5e2', '2026-02-12 21:11:29'),
(137, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":10,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":10},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '59fb50f7754e106f1e5e86e5cc6ee797', '2026-02-12 21:13:21'),
(138, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 17, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '59fb50f7754e106f1e5e86e5cc6ee797', '2026-02-12 21:13:21'),
(139, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 18, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":180,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":180},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', 'bf7ad7cccc982e9c611847c2ad0af791', '2026-02-12 21:13:22'),
(140, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_create', 'SYSTEM', 18, NULL, 'Recorded manual expense', NULL, '{\"category_id\":2,\"amount\":180,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"category_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":180},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"},\"expense_date\":{\"from\":null,\"to\":\"2026-02-13\"}}}', 'bf7ad7cccc982e9c611847c2ad0af791', '2026-02-12 21:13:22'),
(141, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 1, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":11,\"sheet_id\":2,\"amount\":500,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":11},\"sheet_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":500},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', 'fc4819b4b0c4beed85cfc376b8dd7f4f', '2026-02-12 21:48:48'),
(142, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 19, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":500,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":500},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', 'fc4819b4b0c4beed85cfc376b8dd7f4f', '2026-02-12 21:48:48'),
(143, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 20, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":180,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":180},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '7de779fbf528c186772343b4f51a5489', '2026-02-12 21:49:26'),
(144, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_create', 'SYSTEM', 20, NULL, 'Recorded manual expense', NULL, '{\"category_id\":2,\"amount\":180,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"category_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":180},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"},\"expense_date\":{\"from\":null,\"to\":\"2026-02-13\"}}}', '7de779fbf528c186772343b4f51a5489', '2026-02-12 21:49:26'),
(145, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_reverse', 'SYSTEM', 1, NULL, 'Reversed salary payment entry', NULL, '{\"payment_id\":11,\"reversal_id\":12,\"reversal_amount\":-500,\"sheet_id\":2,\"sheet_settled_after_reversal\":1}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":11},\"reversal_id\":{\"from\":null,\"to\":12},\"reversal_amount\":{\"from\":null,\"to\":-500},\"sheet_id\":{\"from\":null,\"to\":2},\"sheet_settled_after_reversal\":{\"from\":null,\"to\":1}}}', '6ff923c25ba3147d417d75745861eb7c', '2026-02-12 21:51:57'),
(146, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'reversal', 'PAYROLL_PAYMENT_REV', 21, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":-500,\"entry_type\":\"REVERSAL\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":-500},\"entry_type\":{\"from\":null,\"to\":\"REVERSAL\"}}}', '6ff923c25ba3147d417d75745861eb7c', '2026-02-12 21:51:57'),
(147, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_add', 'SYSTEM', 2, NULL, 'Added salary payment entry', NULL, '{\"payment_id\":13,\"sheet_id\":3,\"amount\":400,\"payment_date\":\"2026-02-13\",\"payment_mode\":\"BANK_TRANSFER\",\"sheet_auto_locked\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":13},\"sheet_id\":{\"from\":null,\"to\":3},\"amount\":{\"from\":null,\"to\":400},\"payment_date\":{\"from\":null,\"to\":\"2026-02-13\"},\"payment_mode\":{\"from\":null,\"to\":\"BANK_TRANSFER\"},\"sheet_auto_locked\":{\"from\":null,\"to\":0}}}', '94a1b322402b301424741912808620f0', '2026-02-12 21:52:41'),
(148, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'PAYROLL_PAYMENT', 22, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":400,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":400},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '94a1b322402b301424741912808620f0', '2026-02-12 21:52:41'),
(149, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_payment', 'salary_payment_reverse', 'SYSTEM', 2, NULL, 'Reversed salary payment entry', NULL, '{\"payment_id\":13,\"reversal_id\":14,\"reversal_amount\":-400,\"sheet_id\":3,\"sheet_settled_after_reversal\":0}', '{\"changes\":{\"payment_id\":{\"from\":null,\"to\":13},\"reversal_id\":{\"from\":null,\"to\":14},\"reversal_amount\":{\"from\":null,\"to\":-400},\"sheet_id\":{\"from\":null,\"to\":3},\"sheet_settled_after_reversal\":{\"from\":null,\"to\":0}}}', '8f2b2f110d1e3f93175f298ac7ddbef9', '2026-02-12 21:52:54'),
(150, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'reversal', 'PAYROLL_PAYMENT_REV', 23, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":-400,\"entry_type\":\"REVERSAL\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":-400},\"entry_type\":{\"from\":null,\"to\":\"REVERSAL\"}}}', '8f2b2f110d1e3f93175f298ac7ddbef9', '2026-02-12 21:52:54'),
(151, 1, 1, 1, 'super_admin', 'payroll', 'payroll_salary_item', 'salary_entry_reverse', 'SYSTEM', 2, NULL, 'Reversed salary entry row', '{\"gross_amount\":1200,\"net_payable\":1200,\"paid_amount\":0}', '{\"gross_amount\":0,\"net_payable\":0,\"paid_amount\":0,\"reason\":\"Smoke flow reverse salary entry\",\"sheet_settled_after_reversal\":1}', '{\"changes\":{\"gross_amount\":{\"from\":1200,\"to\":0},\"net_payable\":{\"from\":1200,\"to\":0},\"reason\":{\"from\":null,\"to\":\"Smoke flow reverse salary entry\"},\"sheet_settled_after_reversal\":{\"from\":null,\"to\":1}}}', '655049fbda6cfdb73c8485035e9624b9', '2026-02-12 21:53:07'),
(152, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense', 'MANUAL_EXPENSE', 24, NULL, 'Expense entry created', NULL, '{\"company_id\":1,\"garage_id\":1,\"category_name\":\"Salary & Wages\",\"amount\":210,\"entry_type\":\"EXPENSE\"}', '{\"changes\":{\"company_id\":{\"from\":null,\"to\":1},\"garage_id\":{\"from\":null,\"to\":1},\"category_name\":{\"from\":null,\"to\":\"Salary & Wages\"},\"amount\":{\"from\":null,\"to\":210},\"entry_type\":{\"from\":null,\"to\":\"EXPENSE\"}}}', '5bf5ff1d33d76c6faae2e781985cc060', '2026-02-12 21:53:19'),
(153, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_create', 'SYSTEM', 24, NULL, 'Recorded manual expense', NULL, '{\"category_id\":2,\"amount\":210,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"category_id\":{\"from\":null,\"to\":2},\"amount\":{\"from\":null,\"to\":210},\"payment_mode\":{\"from\":null,\"to\":\"CASH\"},\"expense_date\":{\"from\":null,\"to\":\"2026-02-13\"}}}', '5bf5ff1d33d76c6faae2e781985cc060', '2026-02-12 21:53:19'),
(154, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'expense_update', 'SYSTEM', 24, NULL, 'Updated expense', '{\"category_id\":2,\"amount\":210,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"category_id\":2,\"amount\":225,\"payment_mode\":\"CASH\",\"expense_date\":\"2026-02-13\"}', '{\"changes\":{\"amount\":{\"from\":210,\"to\":225}}}', '68a2d38f877be09e07bab5719c73df58', '2026-02-12 21:53:33'),
(155, 1, 1, 1, 'super_admin', 'expenses', 'expense', 'reverse', 'MANUAL', 24, NULL, 'Expense reversed', NULL, '{\"reversal_id\":25}', '{\"changes\":{\"reversal_id\":{\"from\":null,\"to\":25}}}', '59289b555ee5864a59444894dd4d03d1', '2026-02-12 21:53:46'),
(156, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_spec', 'status_spec', 'UI', 1, '::1', 'Changed status to ACTIVE', NULL, NULL, '{\"requested_status\":\"ACTIVE\",\"applied_status\":\"ACTIVE\",\"dependency_part_links\":0,\"dependency_job_links\":0}', '7815c299690845d9a27bdaa58a5393db', '2026-02-12 23:20:49'),
(157, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_spec', 'status_spec', 'UI', 1, '::1', 'Changed status to INACTIVE', NULL, NULL, '{\"requested_status\":\"INACTIVE\",\"applied_status\":\"INACTIVE\",\"dependency_part_links\":0,\"dependency_job_links\":0}', 'f89783a89e341e8593ec230ad275bed1', '2026-02-12 23:20:51'),
(158, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_spec', 'status_spec', 'UI', 1, '::1', 'Changed status to ACTIVE', NULL, NULL, '{\"requested_status\":\"ACTIVE\",\"applied_status\":\"ACTIVE\",\"dependency_part_links\":0,\"dependency_job_links\":0}', '2a6bc2e36051458612afd3a218509206', '2026-02-12 23:20:52'),
(159, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_spec', 'status_spec', 'UI', 2, '::1', 'Changed status to INACTIVE', NULL, NULL, '{\"requested_status\":\"INACTIVE\",\"applied_status\":\"INACTIVE\",\"dependency_part_links\":0,\"dependency_job_links\":0}', '3acc56c0e8a5782fadcd4f57a5a65040', '2026-02-12 23:20:54'),
(160, 1, 1, 1, 'super_admin', 'vis_catalog', 'vis_spec', 'status_spec', 'UI', 2, '::1', 'Changed status to ACTIVE', NULL, NULL, '{\"requested_status\":\"ACTIVE\",\"applied_status\":\"ACTIVE\",\"dependency_part_links\":0,\"dependency_job_links\":0}', 'bcd02a665ff99012db773704a0e256a4', '2026-02-12 23:20:56'),
(161, 1, 1, 1, 'super_admin', 'billing', 'invoice_payment', 'payment_reverse', 'UI', 2, '::1', 'Reversed payment #2 for invoice INV-2602-05002', '{\"payment_id\":2,\"invoice_id\":2,\"invoice_status\":\"FINALIZED\",\"payment_status\":\"PARTIAL\",\"amount\":10}', '{\"reversal_id\":11,\"payment_status\":\"UNPAID\",\"payment_mode\":null,\"net_paid\":0,\"reason\":\"j\"}', '{\"invoice_number\":\"INV-2602-05002\",\"changes\":{\"payment_id\":{\"from\":2,\"to\":null},\"invoice_id\":{\"from\":2,\"to\":null},\"invoice_status\":{\"from\":\"FINALIZED\",\"to\":null},\"payment_status\":{\"from\":\"PARTIAL\",\"to\":\"UNPAID\"},\"amount\":{\"from\":10,\"to\":null},\"reversal_id\":{\"from\":null,\"to\":11},\"net_paid\":{\"from\":null,\"to\":0},\"reason\":{\"from\":null,\"to\":\"j\"}}}', '909d98781658e631bcc9db8aa10fa468', '2026-02-13 00:00:22'),
(162, 1, 1, 1, 'super_admin', 'billing', 'invoice_payment', 'payment_reverse', 'UI', 1, '::1', 'Reversed payment #1 for invoice INV-2602-05001', '{\"payment_id\":1,\"invoice_id\":1,\"invoice_status\":\"FINALIZED\",\"payment_status\":\"PAID\",\"amount\":566}', '{\"reversal_id\":12,\"payment_status\":\"UNPAID\",\"payment_mode\":null,\"net_paid\":0,\"reason\":\"iub\"}', '{\"invoice_number\":\"INV-2602-05001\",\"changes\":{\"payment_id\":{\"from\":1,\"to\":null},\"invoice_id\":{\"from\":1,\"to\":null},\"invoice_status\":{\"from\":\"FINALIZED\",\"to\":null},\"payment_status\":{\"from\":\"PAID\",\"to\":\"UNPAID\"},\"amount\":{\"from\":566,\"to\":null},\"reversal_id\":{\"from\":null,\"to\":12},\"net_paid\":{\"from\":null,\"to\":0},\"reason\":{\"from\":null,\"to\":\"iub\"}}}', '05101f773ffbb7fcac0f9f7d7ce72d44', '2026-02-13 00:03:21'),
(163, 1, 1, 1, 'super_admin', 'customers', 'customer', 'create_inline', 'UI-AJAX', 7, '::1', 'Created customer jagdamna via inline modal', '{\"exists\":false}', '{\"id\":7,\"full_name\":\"jagdamna\",\"phone\":\"adf\",\"status_code\":\"ACTIVE\"}', '{\"changes\":{\"exists\":{\"from\":false,\"to\":null},\"id\":{\"from\":null,\"to\":7},\"full_name\":{\"from\":null,\"to\":\"jagdamna\"},\"phone\":{\"from\":null,\"to\":\"adf\"},\"status_code\":{\"from\":null,\"to\":\"ACTIVE\"}}}', '4de5f75820424aaa2a81a87233b1ea08', '2026-02-13 00:12:49'),
(164, 1, 1, 1, 'super_admin', 'auth', 'user_session', 'login', 'UI', 1, '::1', 'User login successful.', '{\"authenticated\":false}', '{\"authenticated\":true}', '{\"changes\":{\"authenticated\":{\"from\":false,\"to\":true}}}', 'cd7686a748cfa111fa2ab821e9049ddc', '2026-02-13 00:29:54'),
(165, 1, 1, 1, 'super_admin', 'auth', 'user_session', 'logout', 'UI', 1, '::1', 'User logout.', '{\"authenticated\":true,\"user_name\":\"System Admin\"}', '{\"authenticated\":false}', '{\"changes\":{\"authenticated\":{\"from\":true,\"to\":false},\"user_name\":{\"from\":\"System Admin\",\"to\":null}}}', 'eac61e994ec3fea0cb2f65b325891719', '2026-02-13 00:32:50');

--
-- Triggers `audit_logs`
--
DELIMITER $$
CREATE TRIGGER `trg_audit_logs_no_delete` BEFORE DELETE ON `audit_logs` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs rows are immutable and cannot be deleted';
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_audit_logs_no_update` BEFORE UPDATE ON `audit_logs` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs rows are immutable and cannot be updated';
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `backup_integrity_checks`
--

CREATE TABLE `backup_integrity_checks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `result_code` enum('PASS','WARN','FAIL') NOT NULL DEFAULT 'PASS',
  `issues_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary_json`)),
  `checked_by` int(10) UNSIGNED DEFAULT NULL,
  `checked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `backup_integrity_checks`
--

INSERT INTO `backup_integrity_checks` (`id`, `company_id`, `result_code`, `issues_count`, `summary_json`, `checked_by`, `checked_at`) VALUES
(1, 1, 'PASS', 0, '{\"sequence\":\"ok\",\"fy_scope\":\"ok\"}', 1, '2026-02-10 17:27:46'),
(2, 1, 'FAIL', 1, '{\"checked_at\":\"2026-02-12T22:38:34+05:30\",\"company_id\":1,\"critical_issues\":1,\"warning_issues\":0,\"issues\":[{\"code\":\"INVOICE_COUNTER_BEHIND\",\"severity\":\"FAIL\",\"count\":1}]}', 1, '2026-02-12 17:08:34');

-- --------------------------------------------------------

--
-- Table structure for table `backup_runs`
--

CREATE TABLE `backup_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `backup_type` enum('MANUAL','SCHEDULED','RESTORE_POINT') NOT NULL DEFAULT 'MANUAL',
  `backup_label` varchar(140) NOT NULL,
  `dump_file_name` varchar(255) NOT NULL,
  `file_size_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` varchar(128) DEFAULT NULL,
  `dump_started_at` datetime DEFAULT NULL,
  `dump_completed_at` datetime DEFAULT NULL,
  `status_code` enum('SUCCESS','FAILED') NOT NULL DEFAULT 'SUCCESS',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `backup_runs`
--

INSERT INTO `backup_runs` (`id`, `company_id`, `backup_type`, `backup_label`, `dump_file_name`, `file_size_bytes`, `checksum_sha256`, `dump_started_at`, `dump_completed_at`, `status_code`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 'MANUAL', 'Smoke backup metadata', 'smoke_dump.sql', 2048, '04d588cb41b8022d1233924f0fe8280d9e2bb92cdc81658eb102ef9c42fc9452', '2026-02-10 22:57:46', '2026-02-10 22:57:46', 'SUCCESS', 'compliance smoke', 1, '2026-02-10 17:27:46');

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `legal_name` varchar(160) DEFAULT NULL,
  `gstin` varchar(15) DEFAULT NULL,
  `pan` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `address_line1` varchar(200) DEFAULT NULL,
  `address_line2` varchar(200) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `legal_name`, `gstin`, `pan`, `phone`, `email`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `status`, `created_at`, `updated_at`, `status_code`, `deleted_at`) VALUES
(1, 'Guru Auto Cars', 'Guru Auto Cars Private Limited', '27ABCDE1234F1Z5', 'ABCDE1234F', '+91-9876543210', 'info@guruautocars.in', 'Near Main Road, Sector 12', NULL, 'Pune', 'Maharashtra', '411001', 'active', '2026-02-08 22:01:27', '2026-02-08 22:10:33', 'ACTIVE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `alt_phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `gstin` varchar(15) DEFAULT NULL,
  `address_line1` varchar(200) DEFAULT NULL,
  `address_line2` varchar(200) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `company_id`, `created_by`, `full_name`, `phone`, `alt_phone`, `email`, `gstin`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `notes`, `is_active`, `created_at`, `updated_at`, `status_code`, `deleted_at`) VALUES
(1, 1, 1, 'Ravi Sharma', '+91-9988776655', NULL, 'ravi.sharma@example.com', NULL, NULL, NULL, 'Pune', 'Maharashtra', NULL, NULL, 1, '2026-02-08 22:01:27', '2026-02-08 22:01:27', 'ACTIVE', NULL),
(2, 1, 1, 'Neha Verma', '+91-8899776655', NULL, 'neha.verma@example.com', NULL, NULL, NULL, 'Pune', 'Maharashtra', NULL, NULL, 1, '2026-02-08 22:01:27', '2026-02-08 22:01:27', 'ACTIVE', NULL),
(5, 1, 1, 'nikhil n', '9595959595', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-02-10 15:02:12', '2026-02-12 17:09:56', 'DELETED', '2026-02-12 22:39:56'),
(6, 1, 1, 'nikhil nikji', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-02-10 20:12:08', '2026-02-10 20:12:08', 'ACTIVE', NULL),
(7, 1, 1, 'jagdamna', 'adf', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-02-13 00:12:49', '2026-02-13 00:12:49', 'ACTIVE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customer_history`
--

CREATE TABLE `customer_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(40) NOT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_history`
--

INSERT INTO `customer_history` (`id`, `customer_id`, `action_type`, `action_note`, `snapshot_json`, `created_by`, `created_at`) VALUES
(1, 5, 'CREATE', 'Customer created', '{\"full_name\":\"nikhil n\",\"phone\":\"9595959595\",\"status_code\":\"ACTIVE\"}', 1, '2026-02-10 15:02:12'),
(2, 6, 'CREATE', 'Customer created via inline modal', '{\"full_name\":\"nikhil nikji\",\"phone\":\"0\",\"status_code\":\"ACTIVE\"}', 1, '2026-02-10 20:12:08'),
(3, 5, 'STATUS', 'Status changed to DELETED', '{\"status_code\":\"DELETED\"}', 1, '2026-02-12 17:09:56'),
(4, 7, 'CREATE', 'Customer created via inline modal', '{\"full_name\":\"jagdamna\",\"phone\":\"adf\",\"status_code\":\"ACTIVE\"}', 1, '2026-02-13 00:12:49');

-- --------------------------------------------------------

--
-- Table structure for table `data_export_logs`
--

CREATE TABLE `data_export_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED DEFAULT NULL,
  `module_key` varchar(60) NOT NULL,
  `format_key` varchar(20) NOT NULL DEFAULT 'CSV',
  `row_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `include_draft` tinyint(1) NOT NULL DEFAULT 0,
  `include_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `filter_summary` varchar(255) DEFAULT NULL,
  `scope_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scope_json`)),
  `requested_by` int(10) UNSIGNED DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `data_export_logs`
--

INSERT INTO `data_export_logs` (`id`, `company_id`, `garage_id`, `module_key`, `format_key`, `row_count`, `include_draft`, `include_cancelled`, `filter_summary`, `scope_json`, `requested_by`, `requested_at`) VALUES
(1, 1, 3, 'inventory', 'CSV', 0, 0, 0, 'FY 2026-27, 2026-04-01 to 2026-04-01', '{\"garage_scope\":\"Guru Auto Cars - Pune East (PUNE-EAST)\",\"fy_label\":\"2026-27\",\"from\":\"2026-04-01\",\"to\":\"2026-04-01\"}', 1, '2026-02-10 16:55:09'),
(2, 1, 3, 'inventory', 'CSV', 1, 0, 0, 'FY 2025-26, 2026-02-10 to 2026-02-10', '{\"garage_scope\":\"Guru Auto Cars - Pune East (PUNE-EAST)\",\"fy_label\":\"2025-26\",\"from\":\"2026-02-10\",\"to\":\"2026-02-10\"}', 1, '2026-02-10 16:56:38'),
(3, 1, 1, 'invoices', 'CSV', 3, 0, 0, 'FY 2025-26, 2025-04-01 to 2026-02-11', '{\"garage_scope\":\"Guru Auto Cars - Pune Main (PUNE-MAIN)\",\"fy_label\":\"2025-26\",\"from\":\"2025-04-01\",\"to\":\"2026-02-11\"}', 1, '2026-02-10 19:03:34'),
(4, 1, 1, 'reports_billing', 'CSV', 1, 0, 0, 'Report export: billing_gst_summary_20260211_235406.csv', '{\"filename\":\"billing_gst_summary_20260211_235406.csv\"}', 1, '2026-02-11 18:24:06'),
(5, 1, 1, 'reports_gst', 'CSV', 4, 0, 0, 'Report export: gst_sales_report_20260212_221315.csv', '{\"filename\":\"gst_sales_report_20260212_221315.csv\"}', 1, '2026-02-12 16:43:15'),
(6, 1, 1, 'reports_gst', 'CSV', 2, 0, 0, 'Report export: gst_purchase_report_20260212_221316.csv', '{\"filename\":\"gst_purchase_report_20260212_221316.csv\"}', 1, '2026-02-12 16:43:16'),
(7, 1, 1, 'invoices', 'CSV', 4, 0, 0, 'FY 2025-26, 2025-04-01 to 2026-02-12', '{\"garage_scope\":\"Guru Auto Cars - Pune Main (PUNE-MAIN)\",\"fy_label\":\"2025-26\",\"from\":\"2025-04-01\",\"to\":\"2026-02-12\"}', 1, '2026-02-12 17:07:43'),
(8, 1, 1, 'jobs', 'CSV', 5, 0, 0, 'FY 2025-26, 2025-04-01 to 2026-02-12', '{\"garage_scope\":\"Guru Auto Cars - Pune Main (PUNE-MAIN)\",\"fy_label\":\"2025-26\",\"from\":\"2025-04-01\",\"to\":\"2026-02-12\"}', 1, '2026-02-12 17:07:45');

-- --------------------------------------------------------

--
-- Table structure for table `estimates`
--

CREATE TABLE `estimates` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `estimate_number` varchar(40) NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `complaint` text NOT NULL,
  `notes` text DEFAULT NULL,
  `estimate_status` enum('DRAFT','APPROVED','REJECTED','CONVERTED') NOT NULL DEFAULT 'DRAFT',
  `estimate_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `valid_until` date DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `converted_job_card_id` int(10) UNSIGNED DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estimates`
--

INSERT INTO `estimates` (`id`, `company_id`, `garage_id`, `estimate_number`, `customer_id`, `vehicle_id`, `complaint`, `notes`, `estimate_status`, `estimate_total`, `valid_until`, `approved_at`, `rejected_at`, `reject_reason`, `converted_at`, `converted_job_card_id`, `status_code`, `deleted_at`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'EST-2602-1001', 1, 1, 'dfgbxdfgb', NULL, 'DRAFT', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, 'ACTIVE', NULL, 1, 1, '2026-02-10 22:24:59', '2026-02-10 22:24:59'),
(2, 1, 1, 'EST-2602-1002', 2, 2, 'vv', NULL, 'CONVERTED', 1500.00, NULL, '2026-02-11 03:57:58', NULL, NULL, '2026-02-11 03:58:08', 10, 'ACTIVE', NULL, 1, 1, '2026-02-10 22:26:28', '2026-02-10 22:28:08');

-- --------------------------------------------------------

--
-- Table structure for table `estimate_counters`
--

CREATE TABLE `estimate_counters` (
  `garage_id` int(10) UNSIGNED NOT NULL,
  `prefix` varchar(20) NOT NULL DEFAULT 'EST',
  `current_number` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estimate_counters`
--

INSERT INTO `estimate_counters` (`garage_id`, `prefix`, `current_number`, `updated_at`) VALUES
(1, 'EST', 1002, '2026-02-10 22:26:28');

-- --------------------------------------------------------

--
-- Table structure for table `estimate_history`
--

CREATE TABLE `estimate_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `estimate_id` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(60) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) DEFAULT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estimate_history`
--

INSERT INTO `estimate_history` (`id`, `estimate_id`, `action_type`, `from_status`, `to_status`, `action_note`, `payload_json`, `created_by`, `created_at`) VALUES
(1, 1, 'CREATE', NULL, 'DRAFT', 'Estimate created', '{\"estimate_number\":\"EST-2602-1001\"}', 1, '2026-02-10 22:24:59'),
(2, 2, 'CREATE', NULL, 'DRAFT', 'Estimate created', '{\"estimate_number\":\"EST-2602-1002\"}', 1, '2026-02-10 22:26:28'),
(3, 2, 'SERVICE_ADD', NULL, NULL, 'Service line added', NULL, 1, '2026-02-10 22:26:49'),
(4, 2, 'SERVICE_EDIT', NULL, NULL, 'Service line updated', NULL, 1, '2026-02-10 22:27:10'),
(5, 2, 'PART_ADD', NULL, NULL, 'Part line added', NULL, 1, '2026-02-10 22:27:22'),
(6, 2, 'UPDATE_META', 'DRAFT', 'DRAFT', 'Estimate details updated', NULL, 1, '2026-02-10 22:27:28'),
(7, 2, 'STATUS_CHANGE', 'DRAFT', 'APPROVED', NULL, NULL, 1, '2026-02-10 22:27:58'),
(8, 2, 'CONVERT', 'APPROVED', 'CONVERTED', 'Converted to job card JOB-2602-1007', '{\"job_id\":10,\"job_number\":\"JOB-2602-1007\"}', 1, '2026-02-10 22:28:08');

-- --------------------------------------------------------

--
-- Table structure for table `estimate_parts`
--

CREATE TABLE `estimate_parts` (
  `id` int(10) UNSIGNED NOT NULL,
  `estimate_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estimate_parts`
--

INSERT INTO `estimate_parts` (`id`, `estimate_id`, `part_id`, `quantity`, `unit_price`, `gst_rate`, `total_amount`, `created_at`, `updated_at`) VALUES
(1, 2, 5, 1.00, 500.00, 18.00, 500.00, '2026-02-10 22:27:22', '2026-02-10 22:27:22');

-- --------------------------------------------------------

--
-- Table structure for table `estimate_services`
--

CREATE TABLE `estimate_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `estimate_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(10) UNSIGNED DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estimate_services`
--

INSERT INTO `estimate_services` (`id`, `estimate_id`, `service_id`, `description`, `quantity`, `unit_price`, `gst_rate`, `total_amount`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 'fszdf', 1.00, 1000.00, 18.00, 1000.00, '2026-02-10 22:26:49', '2026-02-10 22:27:10');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `expense_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_to` varchar(120) DEFAULT NULL,
  `payment_mode` enum('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT','VOID') NOT NULL DEFAULT 'CASH',
  `notes` varchar(255) DEFAULT NULL,
  `source_type` varchar(40) DEFAULT NULL,
  `source_id` bigint(20) UNSIGNED DEFAULT NULL,
  `entry_type` enum('EXPENSE','REVERSAL','DELETED') NOT NULL DEFAULT 'EXPENSE',
  `reversed_expense_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `company_id`, `garage_id`, `category_id`, `expense_date`, `amount`, `paid_to`, `payment_mode`, `notes`, `source_type`, `source_id`, `entry_type`, `reversed_expense_id`, `created_by`, `updated_by`, `created_at`) VALUES
(1, 1, 1, 2, '2026-02-12', 15000.00, 'System Admin', 'CASH', NULL, 'PAYROLL_PAYMENT', 1, 'EXPENSE', NULL, 1, NULL, '2026-02-12 17:32:14'),
(2, 1, 1, 8, '2026-02-12', 10000.00, NULL, 'CASH', NULL, 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 17:47:07'),
(3, 1, 1, 8, '2026-02-12', -10000.00, NULL, 'ADJUSTMENT', 'Reversed via expense screen', 'MANUAL_EXPENSE_REV', 2, 'REVERSAL', 2, 1, NULL, '2026-02-12 17:48:09'),
(4, 1, 1, 2, '2026-02-12', 10000.00, 'System Admin', 'CASH', NULL, 'PAYROLL_PAYMENT', 2, 'EXPENSE', NULL, 1, NULL, '2026-02-12 17:55:18'),
(5, 1, 1, 2, '2026-02-12', 500.00, 'System Admin', 'CASH', NULL, 'PAYROLL_PAYMENT', 3, 'EXPENSE', NULL, 1, NULL, '2026-02-12 17:55:53'),
(6, 1, 1, 2, '2026-02-12', -500.00, 'System Admin', 'ADJUSTMENT', 'Reversed via expense screen', 'MANUAL_EXPENSE_REV', 5, 'REVERSAL', 5, 1, NULL, '2026-02-12 17:56:25'),
(7, 1, 1, 2, '2026-02-12', -15000.00, 'System Admin', 'ADJUSTMENT', 'Reversed via expense screen', 'MANUAL_EXPENSE_REV', 1, 'REVERSAL', 1, 1, NULL, '2026-02-12 17:56:36'),
(8, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke salary payment', 'PAYROLL_PAYMENT', 4, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:08:10'),
(9, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke EMI deduction payment', 'PAYROLL_PAYMENT', 5, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:08:10'),
(10, 1, 1, 2, '2026-02-13', 180.00, 'Smoke Vendor', 'CASH', 'Smoke expense entry', 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:08:10'),
(11, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke salary payment', 'PAYROLL_PAYMENT', 6, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:09:09'),
(12, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke EMI deduction payment', 'PAYROLL_PAYMENT', 7, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:09:10'),
(13, 1, 1, 2, '2026-02-13', 180.00, 'Smoke Vendor', 'CASH', 'Smoke expense entry', 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:09:10'),
(14, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke salary payment', 'PAYROLL_PAYMENT', 8, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:11:28'),
(15, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke EMI deduction payment', 'PAYROLL_PAYMENT', 9, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:11:29'),
(16, 1, 1, 2, '2026-02-13', 180.00, 'Smoke Vendor', 'CASH', 'Smoke expense entry', 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:11:29'),
(17, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke salary payment', 'PAYROLL_PAYMENT', 10, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:13:21'),
(18, 1, 1, 2, '2026-02-13', 180.00, 'Smoke Vendor', 'CASH', 'Smoke expense entry', 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:13:22'),
(19, 1, 1, 2, '2026-02-13', 500.00, 'System Admin', 'BANK_TRANSFER', 'Smoke salary payment', 'PAYROLL_PAYMENT', 11, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:48:48'),
(20, 1, 1, 2, '2026-02-13', 180.00, 'Smoke Vendor', 'CASH', 'Smoke expense entry', 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:49:26'),
(21, 1, 1, 2, '2026-02-13', -500.00, 'System Admin', 'ADJUSTMENT', 'Smoke reverse salary payment', 'PAYROLL_PAYMENT_REV', 12, 'REVERSAL', NULL, 1, NULL, '2026-02-12 21:51:57'),
(22, 1, 1, 2, '2026-02-13', 400.00, 'System Admin', 'BANK_TRANSFER', 'Smoke flow payment', 'PAYROLL_PAYMENT', 13, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:52:41'),
(23, 1, 1, 2, '2026-02-13', -400.00, 'System Admin', 'ADJUSTMENT', 'Smoke flow reverse payment', 'PAYROLL_PAYMENT_REV', 14, 'REVERSAL', NULL, 1, NULL, '2026-02-12 21:52:54'),
(24, 1, 1, 2, '2026-02-13', 225.00, 'Smoke Flow Vendor', 'CASH', 'Smoke flow expense edited', 'MANUAL_EXPENSE', NULL, 'EXPENSE', NULL, 1, NULL, '2026-02-12 21:53:19'),
(25, 1, 1, 2, '2026-02-13', -225.00, 'Smoke Flow Vendor', 'ADJUSTMENT', 'Smoke flow reverse expense', 'MANUAL_EXPENSE_REV', 24, 'REVERSAL', 24, 1, NULL, '2026-02-12 21:53:46');

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `category_name` varchar(120) NOT NULL,
  `status_code` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `company_id`, `garage_id`, `category_name`, `status_code`, `created_by`, `updated_by`, `created_at`) VALUES
(2, 1, 1, 'Salary & Wages', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(3, 1, 3, 'Salary & Wages', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(4, 1, 1, 'Outsourced Works', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(5, 1, 3, 'Outsourced Works', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(6, 1, 1, 'Purchases', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(7, 1, 3, 'Purchases', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(8, 1, 1, 'General Expense', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34'),
(9, 1, 3, 'General Expense', 'ACTIVE', NULL, NULL, '2026-02-12 17:23:34');

-- --------------------------------------------------------

--
-- Table structure for table `financial_years`
--

CREATE TABLE `financial_years` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `fy_label` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `financial_years`
--

INSERT INTO `financial_years` (`id`, `company_id`, `fy_label`, `start_date`, `end_date`, `is_default`, `status_code`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, '2026-27', '2026-04-01', '2027-03-31', 1, 'ACTIVE', 1, '2026-02-09 19:58:20', '2026-02-09 19:58:20', NULL),
(2, 1, '2025-26', '2025-04-01', '2026-03-31', 0, 'ACTIVE', 1, '2026-02-10 16:56:01', '2026-02-10 16:56:01', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `garages`
--

CREATE TABLE `garages` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(140) NOT NULL,
  `code` varchar(30) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `gstin` varchar(15) DEFAULT NULL,
  `address_line1` varchar(200) DEFAULT NULL,
  `address_line2` varchar(200) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garages`
--

INSERT INTO `garages` (`id`, `company_id`, `name`, `code`, `phone`, `email`, `gstin`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `status`, `created_at`, `updated_at`, `status_code`, `deleted_at`) VALUES
(1, 1, 'Guru Auto Cars - Pune Main', 'PUNE-MAIN', '+91-9876543210', 'pune@guruautocars.in', '27ABCDE1234F1Z5', 'Near Main Road, Sector 12', NULL, 'Pune', 'Maharashtra', '411001', 'active', '2026-02-08 22:01:27', '2026-02-08 22:01:27', 'ACTIVE', NULL),
(3, 1, 'Guru Auto Cars - Pune East', 'PUNE-EAST', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-02-10 16:49:32', '2026-02-10 16:49:32', 'ACTIVE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `garage_inventory`
--

CREATE TABLE `garage_inventory` (
  `garage_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_inventory`
--

INSERT INTO `garage_inventory` (`garage_id`, `part_id`, `quantity`, `updated_at`) VALUES
(1, 1, 52.00, '2026-02-10 21:34:09'),
(1, 2, 23.00, '2026-02-09 21:22:44'),
(1, 3, 616.00, '2026-02-12 16:55:59'),
(1, 4, 1000.00, '2026-02-10 21:33:22'),
(1, 5, -1.00, '2026-02-11 18:37:29'),
(3, 1, 1.00, '2026-02-10 16:53:02');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `movement_type` enum('IN','OUT','ADJUST') NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `reference_type` enum('PURCHASE','JOB_CARD','ADJUSTMENT','OPENING','TRANSFER') NOT NULL DEFAULT 'ADJUSTMENT',
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `movement_uid` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_movements`
--

INSERT INTO `inventory_movements` (`id`, `company_id`, `garage_id`, `part_id`, `movement_type`, `quantity`, `reference_type`, `reference_id`, `movement_uid`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 1, 2, 'OUT', 1.00, 'JOB_CARD', 1, 'legacy-1', 'Issued for Job Card #1', 1, '2026-02-08 22:13:00'),
(2, 1, 1, 3, 'OUT', 2.00, 'JOB_CARD', 1, 'legacy-2', 'Issued for Job Card #1', 1, '2026-02-09 20:56:41'),
(3, 1, 1, 2, 'OUT', 1.00, 'JOB_CARD', 1, 'legacy-3', 'Auto posted on CLOSE for Job Card #1', 1, '2026-02-09 21:22:44'),
(4, 1, 1, 3, 'OUT', 2.00, 'JOB_CARD', 1, 'legacy-4', 'Auto posted on CLOSE for Job Card #1', 1, '2026-02-09 21:22:44'),
(7, 1, 1, 3, 'IN', 500.00, 'PURCHASE', NULL, 'legacy-7', NULL, 1, '2026-02-09 21:51:20'),
(13, 1, 1, 1, 'OUT', 1.00, 'TRANSFER', 2, 'transfer-2-out', 'Smoke transfer', 1, '2026-02-10 16:53:02'),
(14, 1, 3, 1, 'IN', 1.00, 'TRANSFER', 2, 'transfer-2-in', 'Smoke transfer', 1, '2026-02-10 16:53:02'),
(15, 1, 1, 1, 'OUT', 2.00, 'JOB_CARD', 4, 'jobclose-4-1', 'Auto posted on CLOSE for Job Card #4', 1, '2026-02-10 16:54:16'),
(16, 1, 1, 1, 'IN', 1.25, 'PURCHASE', 1, 'adj-ee3258d3fa47741bd947d4041d26a100bf1533743147ed7480dd42bf772a', 'SMOKE-MANUAL-IN-20260211022918', 1, '2026-02-10 20:59:18'),
(17, 1, 1, 1, 'IN', 0.75, 'PURCHASE', 2, 'pur-9a0c57d9db3b6646d9e68cf3c98a3d87f1daa5a2c9fb5f51104331ce47b9', 'SMOKE-VENDOR-CREATE-20260211022959', 1, '2026-02-10 20:59:59'),
(18, 1, 1, 4, 'IN', 1000.00, 'PURCHASE', 3, 'tmppur-224f474553baa8d9ed2b490ec21b27d29b1dc37e69d52c6a01d8c43b', 'Converted from temporary stock TMP-260211030230-053', 1, '2026-02-10 21:33:22'),
(19, 1, 1, 1, 'IN', 3.00, 'PURCHASE', 4, 'tmppur-a732bb4c9fae7618e3f5cae8104c8a8027735f6aedbece009ff055cc', 'Converted from temporary stock TMP-260211030343-584 | SMOKE_TSM_20260211030107_PURCHASED_OK', 1, '2026-02-10 21:34:09'),
(20, 1, 1, 5, 'OUT', 1.00, 'JOB_CARD', 10, 'jobclose-10-5', 'Auto posted on CLOSE for Job Card #10', 1, '2026-02-11 18:37:29'),
(21, 1, 1, 3, 'IN', 100.00, 'PURCHASE', 5, 'pur-8bd9375e70d11adde1f4467e020a3afd55f4a92b3c7bc737460c3db44400', 'Purchase #5', 1, '2026-02-12 16:55:59');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transfers`
--

CREATE TABLE `inventory_transfers` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `from_garage_id` int(10) UNSIGNED NOT NULL,
  `to_garage_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `transfer_ref` varchar(40) NOT NULL,
  `request_uid` varchar(64) DEFAULT NULL,
  `status_code` enum('POSTED','CANCELLED') NOT NULL DEFAULT 'POSTED',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_transfers`
--

INSERT INTO `inventory_transfers` (`id`, `company_id`, `from_garage_id`, `to_garage_id`, `part_id`, `quantity`, `transfer_ref`, `request_uid`, `status_code`, `notes`, `created_by`, `created_at`) VALUES
(2, 1, 1, 3, 1, 1.00, 'TRF-260210222302-169', 'af0191cbab7588708228fdacc1cb69db5caf126b582cce558e5b515c5b6e8ba3', 'POSTED', 'Smoke transfer', 1, '2026-02-10 16:53:02');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(40) NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `invoice_status` enum('DRAFT','FINALIZED','CANCELLED') NOT NULL DEFAULT 'FINALIZED',
  `subtotal_service` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal_parts` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_regime` enum('INTRASTATE','INTERSTATE') NOT NULL DEFAULT 'INTRASTATE',
  `cgst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sgst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `igst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `cgst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sgst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `igst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `service_tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `parts_tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `round_off` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('UNPAID','PARTIAL','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
  `payment_mode` enum('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `financial_year_id` int(10) UNSIGNED DEFAULT NULL,
  `financial_year_label` varchar(20) DEFAULT NULL,
  `sequence_number` int(10) UNSIGNED DEFAULT NULL,
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finalized_at` datetime DEFAULT NULL,
  `finalized_by` int(10) UNSIGNED DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) UNSIGNED DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `company_id`, `garage_id`, `invoice_number`, `job_card_id`, `customer_id`, `vehicle_id`, `invoice_date`, `due_date`, `invoice_status`, `subtotal_service`, `subtotal_parts`, `taxable_amount`, `tax_regime`, `cgst_rate`, `sgst_rate`, `igst_rate`, `cgst_amount`, `sgst_amount`, `igst_amount`, `service_tax_amount`, `parts_tax_amount`, `total_tax_amount`, `gross_total`, `round_off`, `grand_total`, `payment_status`, `payment_mode`, `notes`, `created_by`, `financial_year_id`, `financial_year_label`, `sequence_number`, `snapshot_json`, `created_at`, `finalized_at`, `finalized_by`, `cancelled_at`, `cancelled_by`, `cancel_reason`) VALUES
(1, 1, 1, 'INV-2602-05001', 1, 1, 1, '2026-02-10', NULL, 'FINALIZED', 0.00, 480.00, 480.00, 'INTRASTATE', 0.00, 0.00, 18.00, 43.20, 43.20, 0.00, 0.00, 86.40, 86.40, 566.40, -0.40, 566.00, 'UNPAID', NULL, NULL, 1, NULL, '2025-26', 5001, NULL, '2026-02-09 20:57:42', '2026-02-10 02:27:42', 1, NULL, NULL, NULL),
(2, 1, 1, 'INV-2602-05002', 2, 1, 1, '2026-02-10', NULL, 'FINALIZED', 1000.00, 700.00, 1700.00, 'INTRASTATE', 9.00, 9.00, 0.00, 153.00, 153.00, 0.00, 180.00, 126.00, 306.00, 2006.00, 0.00, 2006.00, 'UNPAID', NULL, NULL, 1, NULL, '2025-26', 5002, NULL, '2026-02-09 21:30:21', '2026-02-10 03:00:21', 1, NULL, NULL, NULL),
(7, 1, 1, 'INV-SMOKE-9001', 4, 2, 3, '2026-02-10', '2026-02-10', 'FINALIZED', 1000.00, 0.00, 1000.00, 'INTRASTATE', 9.00, 9.00, 0.00, 90.00, 90.00, 0.00, 180.00, 0.00, 180.00, 1180.00, 0.00, 1180.00, 'UNPAID', NULL, 'Smoke draft finalize', 1, NULL, '2025-26', 9001, '{\"job\": {\"id\": 4}}', '2026-02-10 16:51:50', '2026-02-10 22:22:21', 1, NULL, NULL, NULL),
(8, 1, 1, 'INV/2025-26/05003', 10, 2, 2, '2026-02-12', NULL, 'FINALIZED', 1000.00, 500.00, 1500.00, 'INTRASTATE', 9.00, 9.00, 0.00, 135.00, 135.00, 0.00, 180.00, 90.00, 270.00, 1770.00, 0.00, 1770.00, 'UNPAID', NULL, NULL, 1, 2, '2025-26', 5003, '{\"company\":{\"name\":\"Guru Auto Cars\",\"legal_name\":\"Guru Auto Cars Private Limited\",\"gstin\":\"27ABCDE1234F1Z5\",\"address_line1\":\"Near Main Road, Sector 12\",\"address_line2\":\"\",\"city\":\"Pune\",\"state\":\"Maharashtra\",\"pincode\":\"411001\"},\"garage\":{\"name\":\"Guru Auto Cars - Pune Main\",\"code\":\"PUNE-MAIN\",\"gstin\":\"27ABCDE1234F1Z5\",\"address_line1\":\"Near Main Road, Sector 12\",\"address_line2\":\"\",\"city\":\"Pune\",\"state\":\"Maharashtra\",\"pincode\":\"411001\"},\"customer\":{\"full_name\":\"Neha Verma\",\"phone\":\"+91-8899776655\",\"gstin\":\"\",\"address_line1\":\"\",\"address_line2\":\"\",\"city\":\"Pune\",\"state\":\"Maharashtra\",\"pincode\":\"\"},\"vehicle\":{\"registration_no\":\"MH14CD5678\",\"brand\":\"Honda\",\"model\":\"Activa\",\"variant\":\"\",\"fuel_type\":\"PETROL\",\"model_year\":2020},\"job\":{\"id\":10,\"job_number\":\"JOB-2602-1007\",\"closed_at\":\"2026-02-12 00:07:29\"},\"tax_regime\":\"INTRASTATE\",\"generated_at\":\"2026-02-12T00:07:50+05:30\"}', '2026-02-11 18:37:50', '2026-02-12 00:08:32', 1, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_counters`
--

CREATE TABLE `invoice_counters` (
  `garage_id` int(10) UNSIGNED NOT NULL,
  `prefix` varchar(20) NOT NULL DEFAULT 'INV',
  `current_number` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_counters`
--

INSERT INTO `invoice_counters` (`garage_id`, `prefix`, `current_number`, `updated_at`) VALUES
(1, 'INV', 5004, '2026-02-09 21:48:47'),
(3, 'INV', 5000, '2026-02-10 16:49:32');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `item_type` enum('LABOR','PART') NOT NULL,
  `description` varchar(255) NOT NULL,
  `part_id` int(10) UNSIGNED DEFAULT NULL,
  `service_id` int(10) UNSIGNED DEFAULT NULL,
  `hsn_sac_code` varchar(20) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `gst_rate` decimal(5,2) NOT NULL,
  `cgst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sgst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `igst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `taxable_value` decimal(12,2) NOT NULL,
  `cgst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sgst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `igst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL,
  `total_value` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`id`, `invoice_id`, `item_type`, `description`, `part_id`, `service_id`, `hsn_sac_code`, `quantity`, `unit_price`, `gst_rate`, `cgst_rate`, `sgst_rate`, `igst_rate`, `taxable_value`, `cgst_amount`, `sgst_amount`, `igst_amount`, `tax_amount`, `total_value`) VALUES
(1, 1, 'PART', 'Oil Filter - Swift', 2, NULL, NULL, 1.00, 180.00, 18.00, 9.00, 9.00, 0.00, 180.00, 16.20, 16.20, 0.00, 32.40, 212.40),
(2, 1, 'PART', 'Air Filter - Activa', 3, NULL, NULL, 2.00, 150.00, 18.00, 9.00, 9.00, 0.00, 300.00, 27.00, 27.00, 0.00, 54.00, 354.00),
(3, 2, 'LABOR', 'fszdf', NULL, NULL, NULL, 1.00, 1000.00, 18.00, 9.00, 9.00, 0.00, 1000.00, 90.00, 90.00, 0.00, 180.00, 1180.00),
(4, 2, 'LABOR', 'test paint job', NULL, NULL, NULL, 1.00, 0.00, 18.00, 9.00, 9.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00),
(5, 2, 'PART', 'Engine Oil 5W30 (1L)', 1, NULL, NULL, 1.00, 520.00, 18.00, 9.00, 9.00, 0.00, 520.00, 46.80, 46.80, 0.00, 93.60, 613.60),
(6, 2, 'PART', 'Oil Filter - Swift', 2, NULL, NULL, 1.00, 180.00, 18.00, 9.00, 9.00, 0.00, 180.00, 16.20, 16.20, 0.00, 32.40, 212.40),
(15, 7, 'LABOR', 'Smoke Labor', NULL, NULL, NULL, 1.00, 1000.00, 18.00, 9.00, 9.00, 0.00, 1000.00, 90.00, 90.00, 0.00, 180.00, 1180.00),
(16, 8, 'LABOR', 'fszdf', NULL, 1, 'ZSDF', 1.00, 1000.00, 18.00, 9.00, 9.00, 0.00, 1000.00, 90.00, 90.00, 0.00, 180.00, 1180.00),
(17, 8, 'LABOR', 'fszdf', NULL, 1, 'ZSDF', 1.00, 0.00, 18.00, 9.00, 9.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00),
(18, 8, 'PART', 'asd', 5, NULL, NULL, 1.00, 500.00, 18.00, 9.00, 9.00, 0.00, 500.00, 45.00, 45.00, 0.00, 90.00, 590.00);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_number_sequences`
--

CREATE TABLE `invoice_number_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `financial_year_id` int(10) UNSIGNED DEFAULT NULL,
  `financial_year_label` varchar(20) NOT NULL,
  `prefix` varchar(20) NOT NULL DEFAULT 'INV',
  `current_number` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_number_sequences`
--

INSERT INTO `invoice_number_sequences` (`id`, `company_id`, `garage_id`, `financial_year_id`, `financial_year_label`, `prefix`, `current_number`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 2, '2025-26', 'INV', 5003, '2026-02-10 08:37:45', '2026-02-11 18:37:50');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_payment_history`
--

CREATE TABLE `invoice_payment_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `payment_id` int(10) UNSIGNED DEFAULT NULL,
  `action_type` varchar(40) NOT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_payment_history`
--

INSERT INTO `invoice_payment_history` (`id`, `invoice_id`, `payment_id`, `action_type`, `action_note`, `payload_json`, `created_by`, `created_at`) VALUES
(1, 1, 1, 'LEGACY_PAYMENT_SYNC', 'Legacy payment synchronized.', NULL, 1, '2026-02-09 20:57:57'),
(2, 2, 2, 'LEGACY_PAYMENT_SYNC', 'Legacy payment synchronized.', NULL, 1, '2026-02-09 21:30:33'),
(6, 2, 11, 'PAYMENT_REVERSED', 'j', '{\"reversed_payment_id\":2,\"reversal_amount\":-10,\"reversal_date\":\"2026-02-13\",\"net_paid_amount\":0}', 1, '2026-02-13 00:00:22'),
(7, 1, 12, 'PAYMENT_REVERSED', 'iub', '{\"reversed_payment_id\":1,\"reversal_amount\":-566,\"reversal_date\":\"2026-02-13\",\"net_paid_amount\":0}', 1, '2026-02-13 00:03:21');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_status_history`
--

CREATE TABLE `invoice_status_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) NOT NULL,
  `action_type` varchar(40) NOT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_status_history`
--

INSERT INTO `invoice_status_history` (`id`, `invoice_id`, `from_status`, `to_status`, `action_type`, `action_note`, `payload_json`, `created_by`, `created_at`) VALUES
(1, 1, NULL, 'FINALIZED', 'LEGACY_SYNC', 'Legacy invoice state synchronized.', NULL, 1, '2026-02-09 20:57:42'),
(2, 2, NULL, 'FINALIZED', 'LEGACY_SYNC', 'Legacy invoice state synchronized.', NULL, 1, '2026-02-09 21:30:21'),
(9, 7, 'DRAFT', 'FINALIZED', 'FINALIZE', 'Invoice finalized after GST integrity validation.', NULL, 1, '2026-02-10 16:52:21'),
(10, 8, NULL, 'DRAFT', 'CREATE_DRAFT', 'Draft invoice created from closed job card.', '{\"job_card_id\":10,\"invoice_number\":\"INV\\/2025-26\\/05003\",\"tax_regime\":\"INTRASTATE\"}', 1, '2026-02-11 18:37:50'),
(11, 8, 'DRAFT', 'FINALIZED', 'FINALIZE', 'Invoice finalized after GST integrity validation.', NULL, 1, '2026-02-11 18:38:32');

-- --------------------------------------------------------

--
-- Table structure for table `job_assignments`
--

CREATE TABLE `job_assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `assignment_role` enum('MECHANIC','SUPPORT','INSPECTOR') NOT NULL DEFAULT 'MECHANIC',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_assignments`
--

INSERT INTO `job_assignments` (`id`, `job_card_id`, `user_id`, `assignment_role`, `is_primary`, `status_code`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'MECHANIC', 1, 'ACTIVE', 1, '2026-02-09 20:56:53', '2026-02-09 20:56:53');

-- --------------------------------------------------------

--
-- Table structure for table `job_cards`
--

CREATE TABLE `job_cards` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `job_number` varchar(30) NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `odometer_km` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `service_advisor_id` int(10) UNSIGNED DEFAULT NULL,
  `complaint` text NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `status` enum('OPEN','IN_PROGRESS','WAITING_PARTS','READY_FOR_DELIVERY','COMPLETED','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `priority` enum('LOW','MEDIUM','HIGH','URGENT') NOT NULL DEFAULT 'MEDIUM',
  `estimated_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `promised_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `cancel_note` varchar(255) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `stock_posted_at` datetime DEFAULT NULL,
  `estimate_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_cards`
--

INSERT INTO `job_cards` (`id`, `company_id`, `garage_id`, `job_number`, `customer_id`, `vehicle_id`, `odometer_km`, `assigned_to`, `service_advisor_id`, `complaint`, `diagnosis`, `status`, `priority`, `estimated_cost`, `opened_at`, `promised_at`, `completed_at`, `created_by`, `updated_by`, `created_at`, `updated_at`, `status_code`, `deleted_at`, `cancel_note`, `closed_at`, `stock_posted_at`, `estimate_id`) VALUES
(1, 1, 1, 'JOB-TEST-0001', 1, 1, 25500, 1, 1, 'Engine noise and oil leakage', 'sadfvdzfvsw fa awefg sdfg', 'CLOSED', 'MEDIUM', 0.00, '2026-02-09 03:33:27', NULL, '2026-02-10 02:36:29', 1, 1, '2026-02-08 22:03:27', '2026-02-10 23:29:20', 'ACTIVE', NULL, NULL, '2026-02-10 02:52:44', '2026-02-10 02:52:44', NULL),
(2, 1, 1, 'JOB-2602-1001', 1, 1, 25500, NULL, 1, 'dfgsd', 'fgsdfgsd', 'CLOSED', 'MEDIUM', 1700.00, '2026-02-10 02:57:20', NULL, '2026-02-10 03:00:05', 1, 1, '2026-02-09 21:27:20', '2026-02-10 23:29:20', 'ACTIVE', NULL, NULL, '2026-02-10 03:00:05', NULL, NULL),
(4, 1, 1, 'JOB-2602-1003', 2, 3, 0, NULL, 1, 'zsdfvzd', NULL, 'CLOSED', 'MEDIUM', 1140.00, '2026-02-10 03:14:15', NULL, '2026-02-10 22:23:53', 1, 1, '2026-02-09 21:44:15', '2026-02-10 16:54:16', 'ACTIVE', NULL, NULL, '2026-02-10 22:24:16', '2026-02-10 22:24:16', NULL),
(9, 1, 1, 'JOB-2602-1006', 1, 1, 25500, NULL, 1, 'cyjxf', NULL, 'OPEN', 'MEDIUM', 0.00, '2026-02-11 00:35:56', NULL, NULL, 1, 1, '2026-02-10 19:05:56', '2026-02-12 21:08:22', 'DELETED', '2026-02-13 02:38:22', 'cfnhc', NULL, NULL, NULL),
(10, 1, 1, 'JOB-2602-1007', 2, 2, 18200, NULL, 1, 'vv', NULL, 'CLOSED', 'MEDIUM', 1500.00, '2026-02-11 03:58:08', NULL, '2026-02-12 00:06:34', 1, 1, '2026-02-10 22:28:08', '2026-02-11 18:37:29', 'ACTIVE', NULL, NULL, '2026-02-12 00:07:29', '2026-02-12 00:07:29', 2);

-- --------------------------------------------------------

--
-- Table structure for table `job_counters`
--

CREATE TABLE `job_counters` (
  `garage_id` int(10) UNSIGNED NOT NULL,
  `prefix` varchar(20) NOT NULL DEFAULT 'JOB',
  `current_number` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_counters`
--

INSERT INTO `job_counters` (`garage_id`, `prefix`, `current_number`, `updated_at`) VALUES
(1, 'JOB', 1007, '2026-02-10 22:28:08'),
(3, 'JOB', 1000, '2026-02-10 16:49:32');

-- --------------------------------------------------------

--
-- Table structure for table `job_history`
--

CREATE TABLE `job_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(60) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) DEFAULT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_history`
--

INSERT INTO `job_history` (`id`, `job_card_id`, `action_type`, `from_status`, `to_status`, `action_note`, `payload_json`, `created_by`, `created_at`) VALUES
(1, 1, 'STATUS_CHANGE', 'COMPLETED', 'CLOSED', 'df', '{\"inventory_warnings\":0}', 1, '2026-02-09 21:22:44'),
(2, 2, 'CREATE', NULL, 'OPEN', 'Job created', '{\"job_number\":\"JOB-2602-1001\"}', 1, '2026-02-09 21:27:20'),
(3, 2, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":1,\"service_name\":\"fszdf\",\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":1000}', 1, '2026-02-09 21:27:37'),
(4, 2, 'PART_ADD', NULL, NULL, 'Part line added', '{\"part_id\":1,\"part_name\":\"Engine Oil 5W30 (1L)\",\"quantity\":1,\"unit_price\":520,\"available_qty\":50}', 1, '2026-02-09 21:27:42'),
(5, 2, 'PART_ADD', NULL, NULL, 'Part line added', '{\"part_id\":2,\"part_name\":\"Oil Filter - Swift\",\"quantity\":1,\"unit_price\":180,\"available_qty\":23}', 1, '2026-02-09 21:27:44'),
(6, 2, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":null,\"service_name\":null,\"description\":\"test paint job\",\"quantity\":1,\"unit_price\":0}', 1, '2026-02-09 21:28:26'),
(7, 2, 'ASSIGN_UPDATE', NULL, NULL, 'Assignments updated', '{\"user_ids\":[]}', 1, '2026-02-09 21:29:15'),
(8, 2, 'STATUS_CHANGE', 'OPEN', 'IN_PROGRESS', NULL, '{\"inventory_warnings\":0}', 1, '2026-02-09 21:29:26'),
(9, 2, 'STATUS_CHANGE', 'IN_PROGRESS', 'WAITING_PARTS', NULL, '{\"inventory_warnings\":0}', 1, '2026-02-09 21:29:48'),
(10, 2, 'STATUS_CHANGE', 'WAITING_PARTS', 'COMPLETED', NULL, '{\"inventory_warnings\":0}', 1, '2026-02-09 21:30:05'),
(22, 4, 'ASSIGN_CREATE', NULL, NULL, 'Assigned users', '{\"user_ids\":[2]}', 1, '2026-02-09 21:44:15'),
(23, 4, 'CREATE', NULL, 'OPEN', 'Job created', '{\"job_number\":\"JOB-2602-1003\"}', 1, '2026-02-09 21:44:15'),
(24, 4, 'PART_ADD', NULL, NULL, 'Part line added', '{\"part_id\":1,\"part_name\":\"Engine Oil 5W30 (1L)\",\"quantity\":1,\"unit_price\":520,\"available_qty\":48}', 1, '2026-02-09 21:44:58'),
(27, 4, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":1,\"service_name\":\"fszdf\",\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":0}', 1, '2026-02-10 15:23:56'),
(28, 4, 'LABOR_EDIT', NULL, NULL, 'Labor line updated', '{\"labor_id\":8,\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":100}', 1, '2026-02-10 15:24:06'),
(29, 4, 'PART_ADD', NULL, NULL, 'Part line added', '{\"part_id\":1,\"part_name\":\"Engine Oil 5W30 (1L)\",\"quantity\":1,\"unit_price\":520,\"available_qty\":50}', 1, '2026-02-10 15:26:18'),
(30, 4, 'STATUS_CHANGE', 'OPEN', 'IN_PROGRESS', NULL, '{\"inventory_warnings\":0}', 1, '2026-02-10 15:30:44'),
(31, 4, 'STATUS_CHANGE', 'IN_PROGRESS', 'COMPLETED', 'Smoke complete', '{\"inventory_warnings\":0}', 1, '2026-02-10 16:53:53'),
(32, 4, 'STATUS_CHANGE', 'COMPLETED', 'CLOSED', 'Smoke close', '{\"inventory_warnings\":0}', 1, '2026-02-10 16:54:16'),
(33, 9, 'CREATE', NULL, 'OPEN', 'Job created', '{\"job_number\":\"JOB-2602-1006\"}', 1, '2026-02-10 19:05:56'),
(34, 9, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":null,\"service_name\":null,\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":0}', 1, '2026-02-10 20:15:32'),
(35, 9, 'PART_ADD', NULL, NULL, 'Part line added', '{\"part_id\":3,\"part_name\":\"Air Filter - Activa\",\"quantity\":1,\"unit_price\":150,\"available_qty\":516}', 1, '2026-02-10 20:16:26'),
(36, 9, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":1,\"service_name\":\"fszdf\",\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":0}', 1, '2026-02-10 20:16:32'),
(37, 9, 'PART_REMOVE', NULL, NULL, 'Part line removed', '{\"job_part_id\":14}', 1, '2026-02-10 20:16:49'),
(38, 9, 'LABOR_REMOVE', NULL, NULL, 'Labor line removed', '{\"labor_id\":10}', 1, '2026-02-10 20:16:52'),
(39, 9, 'LABOR_REMOVE', NULL, NULL, 'Labor line removed', '{\"labor_id\":9}', 1, '2026-02-10 20:16:54'),
(40, 9, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":null,\"service_category_key\":null,\"service_category_name\":null,\"service_name\":null,\"description\":\"test\",\"quantity\":1,\"unit_price\":0,\"execution_type\":\"IN_HOUSE\",\"outsource_vendor_id\":null,\"outsource_vendor_name\":null,\"outsource_partner_name\":null,\"outsource_cost\":0,\"outsource_payable_status\":\"PAID\"}', 1, '2026-02-10 21:57:53'),
(41, 9, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":null,\"service_category_key\":null,\"service_category_name\":null,\"service_name\":null,\"description\":\"test paint job\",\"quantity\":1,\"unit_price\":0,\"execution_type\":\"OUTSOURCED\",\"outsource_vendor_id\":1,\"outsource_vendor_name\":\"zdcvzxvc\",\"outsource_partner_name\":\"zdcvzxvc\",\"outsource_cost\":500,\"outsource_payable_status\":\"UNPAID\"}', 1, '2026-02-10 21:58:10'),
(42, 10, 'CREATE', NULL, 'OPEN', 'Job created from estimate EST-2602-1002', '{\"estimate_id\":2,\"estimate_number\":\"EST-2602-1002\"}', 1, '2026-02-10 22:28:08'),
(43, 10, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":1,\"service_category_key\":\"4\",\"service_category_name\":\"AC\",\"service_name\":\"fszdf\",\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":0,\"execution_type\":\"OUTSOURCED\",\"outsource_vendor_id\":1,\"outsource_vendor_name\":\"zdcvzxvc\",\"outsource_partner_name\":\"zdcvzxvc\",\"outsource_cost\":1000,\"outsource_payable_status\":\"UNPAID\"}', 1, '2026-02-11 18:35:58'),
(44, 10, 'STATUS_CHANGE', 'OPEN', 'IN_PROGRESS', NULL, '{\"inventory_warnings\":0}', 1, '2026-02-11 18:36:17'),
(45, 10, 'STATUS_CHANGE', 'IN_PROGRESS', 'COMPLETED', NULL, '{\"inventory_warnings\":0}', 1, '2026-02-11 18:36:34'),
(46, 10, 'STATUS_CHANGE', 'COMPLETED', 'CLOSED', NULL, '{\"inventory_warnings\":1}', 1, '2026-02-11 18:37:29'),
(47, 9, 'LABOR_REMOVE', NULL, NULL, 'Labor line removed', '{\"labor_id\":12}', 1, '2026-02-12 16:48:36'),
(48, 9, 'LABOR_REMOVE', NULL, NULL, 'Labor line removed', '{\"labor_id\":11}', 1, '2026-02-12 21:07:33'),
(49, 9, 'LABOR_ADD', NULL, NULL, 'Labor line added', '{\"service_id\":1,\"service_category_key\":\"4\",\"service_category_name\":\"AC\",\"service_name\":\"fszdf\",\"description\":\"fszdf\",\"quantity\":1,\"unit_price\":0,\"execution_type\":\"OUTSOURCED\",\"outsource_vendor_id\":1,\"outsource_vendor_name\":\"zdcvzxvc\",\"outsource_partner_name\":\"zdcvzxvc\",\"outsource_cost\":5001,\"outsource_expected_return_date\":null,\"outsource_payable_status\":\"UNPAID\",\"outsourced_work_id\":3}', 1, '2026-02-12 21:07:48'),
(50, 9, 'SOFT_DELETE', 'OPEN', 'OPEN', 'cfnhc', NULL, 1, '2026-02-12 21:08:22');

-- --------------------------------------------------------

--
-- Table structure for table `job_issues`
--

CREATE TABLE `job_issues` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `issue_title` varchar(150) NOT NULL,
  `issue_notes` text DEFAULT NULL,
  `resolved_flag` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_issues`
--

INSERT INTO `job_issues` (`id`, `job_card_id`, `issue_title`, `issue_notes`, `resolved_flag`, `created_at`) VALUES
(1, 1, 'tesd', 'sadf', 1, '2026-02-09 20:59:17');

-- --------------------------------------------------------

--
-- Table structure for table `job_labor`
--

CREATE TABLE `job_labor` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `service_id` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `execution_type` enum('IN_HOUSE','OUTSOURCED') NOT NULL DEFAULT 'IN_HOUSE',
  `outsource_vendor_id` int(10) UNSIGNED DEFAULT NULL,
  `outsource_partner_name` varchar(150) DEFAULT NULL,
  `outsource_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `outsource_expected_return_date` date DEFAULT NULL,
  `outsource_payable_status` enum('UNPAID','PAID') NOT NULL DEFAULT 'PAID',
  `outsource_paid_at` datetime DEFAULT NULL,
  `outsource_paid_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_labor`
--

INSERT INTO `job_labor` (`id`, `job_card_id`, `description`, `quantity`, `unit_price`, `gst_rate`, `total_amount`, `created_at`, `service_id`, `updated_at`, `execution_type`, `outsource_vendor_id`, `outsource_partner_name`, `outsource_cost`, `outsource_expected_return_date`, `outsource_payable_status`, `outsource_paid_at`, `outsource_paid_by`) VALUES
(1, 1, 'test paint job', 1.00, 1000.00, 18.00, 1000.00, '2026-02-09 20:59:12', NULL, '2026-02-09 20:59:12', 'IN_HOUSE', NULL, NULL, 0.00, NULL, 'PAID', NULL, NULL),
(2, 2, 'fszdf', 1.00, 1000.00, 18.00, 1000.00, '2026-02-09 21:27:37', 1, '2026-02-09 21:27:37', 'IN_HOUSE', NULL, NULL, 0.00, NULL, 'PAID', NULL, NULL),
(3, 2, 'test paint job', 1.00, 0.00, 18.00, 0.00, '2026-02-09 21:28:26', NULL, '2026-02-09 21:28:26', 'IN_HOUSE', NULL, NULL, 0.00, NULL, 'PAID', NULL, NULL),
(8, 4, 'fszdf', 1.00, 100.00, 18.00, 100.00, '2026-02-10 15:23:56', 1, '2026-02-10 15:24:06', 'IN_HOUSE', NULL, NULL, 0.00, NULL, 'PAID', NULL, NULL),
(13, 10, 'fszdf', 1.00, 1000.00, 18.00, 1000.00, '2026-02-10 22:28:08', 1, '2026-02-10 22:28:08', 'IN_HOUSE', NULL, NULL, 0.00, NULL, 'PAID', NULL, NULL),
(14, 10, 'fszdf', 1.00, 0.00, 18.00, 0.00, '2026-02-11 18:35:58', 1, '2026-02-11 18:35:58', 'OUTSOURCED', 1, 'zdcvzxvc', 1000.00, NULL, 'UNPAID', NULL, NULL),
(15, 9, 'fszdf', 1.00, 0.00, 18.00, 0.00, '2026-02-12 21:07:48', 1, '2026-02-12 21:07:48', 'OUTSOURCED', 1, 'zdcvzxvc', 5001.00, NULL, 'UNPAID', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `job_parts`
--

CREATE TABLE `job_parts` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `total_amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_parts`
--

INSERT INTO `job_parts` (`id`, `job_card_id`, `part_id`, `quantity`, `unit_price`, `gst_rate`, `total_amount`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 1.00, 180.00, 18.00, 180.00, '2026-02-08 22:13:00', '2026-02-09 20:56:53'),
(2, 1, 3, 2.00, 150.00, 18.00, 300.00, '2026-02-09 20:56:41', '2026-02-09 20:56:53'),
(3, 2, 1, 1.00, 520.00, 18.00, 520.00, '2026-02-09 21:27:42', '2026-02-09 21:27:42'),
(4, 2, 2, 1.00, 180.00, 18.00, 180.00, '2026-02-09 21:27:44', '2026-02-09 21:27:44'),
(7, 4, 1, 1.00, 520.00, 18.00, 520.00, '2026-02-09 21:44:58', '2026-02-09 21:44:58'),
(13, 4, 1, 1.00, 520.00, 18.00, 520.00, '2026-02-10 15:26:18', '2026-02-10 15:26:18'),
(15, 10, 5, 1.00, 500.00, 18.00, 500.00, '2026-02-10 22:28:08', '2026-02-10 22:28:08');

-- --------------------------------------------------------

--
-- Table structure for table `outsourced_works`
--

CREATE TABLE `outsourced_works` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `job_card_id` int(10) UNSIGNED NOT NULL,
  `job_labor_id` int(10) UNSIGNED DEFAULT NULL,
  `vendor_id` int(10) UNSIGNED DEFAULT NULL,
  `partner_name` varchar(150) NOT NULL,
  `service_description` varchar(255) NOT NULL,
  `agreed_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expected_return_date` date DEFAULT NULL,
  `current_status` enum('SENT','RECEIVED','VERIFIED','PAYABLE','PAID') NOT NULL DEFAULT 'SENT',
  `sent_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `payable_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `outsourced_works`
--

INSERT INTO `outsourced_works` (`id`, `company_id`, `garage_id`, `job_card_id`, `job_labor_id`, `vendor_id`, `partner_name`, `service_description`, `agreed_cost`, `expected_return_date`, `current_status`, `sent_at`, `received_at`, `verified_at`, `payable_at`, `paid_at`, `notes`, `status_code`, `deleted_at`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 9, NULL, 1, 'zdcvzxvc', 'test paint job', 500.00, NULL, 'PAYABLE', '2026-02-11 03:28:10', NULL, NULL, '2026-02-11 03:28:10', NULL, NULL, 'DELETED', '2026-02-12 22:18:35', 1, 1, '2026-02-10 21:58:10', '2026-02-12 16:48:35'),
(2, 1, 1, 10, 14, 1, 'zdcvzxvc', 'fszdf', 1000.00, NULL, 'PAYABLE', '2026-02-12 00:05:58', NULL, NULL, '2026-02-12 00:05:58', NULL, NULL, 'ACTIVE', NULL, 1, 1, '2026-02-11 18:35:58', '2026-02-11 18:35:58'),
(3, 1, 1, 9, 15, 1, 'zdcvzxvc', 'fszdf', 5001.00, NULL, 'SENT', '2026-02-13 02:37:48', NULL, NULL, NULL, NULL, 'Auto-disabled by reversal hardening: orphaned/deleted job linkage', 'INACTIVE', '2026-02-13 05:06:14', 1, 1, '2026-02-12 21:07:48', '2026-02-12 23:36:14');

-- --------------------------------------------------------

--
-- Table structure for table `outsourced_work_history`
--

CREATE TABLE `outsourced_work_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `outsourced_work_id` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(40) NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) DEFAULT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `outsourced_work_history`
--

INSERT INTO `outsourced_work_history` (`id`, `outsourced_work_id`, `action_type`, `from_status`, `to_status`, `action_note`, `payload_json`, `created_by`, `created_at`) VALUES
(1, 1, 'LEGACY_SYNC', NULL, 'PAYABLE', 'Legacy outsourced work synchronized.', '{\"agreed_cost\": 500.00, \"job_card_id\": 9, \"job_labor_id\": 12}', 1, '2026-02-10 21:58:10'),
(2, 2, 'LEGACY_SYNC', NULL, 'PAYABLE', 'Legacy outsourced work synchronized.', '{\"agreed_cost\": 1000.00, \"job_card_id\": 10, \"job_labor_id\": 14}', 1, '2026-02-11 18:35:58'),
(4, 2, 'PAYMENT_ADD', 'PAYABLE', 'PAYABLE', 'Outsourced payment recorded', '{\"payment_id\":1,\"payment_date\":\"2026-02-12\",\"amount\":100,\"payment_mode\":\"CASH\",\"reference_no\":\"\",\"old_paid_amount\":0,\"new_paid_amount\":100}', 1, '2026-02-12 16:29:02');

-- --------------------------------------------------------

--
-- Table structure for table `outsourced_work_payments`
--

CREATE TABLE `outsourced_work_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `outsourced_work_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `entry_type` enum('PAYMENT','REVERSAL') NOT NULL DEFAULT 'PAYMENT',
  `amount` decimal(12,2) NOT NULL,
  `payment_mode` enum('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT') NOT NULL DEFAULT 'BANK_TRANSFER',
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `reversed_payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `outsourced_work_payments`
--

INSERT INTO `outsourced_work_payments` (`id`, `outsourced_work_id`, `company_id`, `garage_id`, `payment_date`, `entry_type`, `amount`, `payment_mode`, `reference_no`, `notes`, `reversed_payment_id`, `created_by`, `created_at`) VALUES
(1, 2, 1, 1, '2026-02-12', 'PAYMENT', 100.00, 'CASH', NULL, NULL, NULL, 1, '2026-02-12 16:29:02');

-- --------------------------------------------------------

--
-- Table structure for table `parts`
--

CREATE TABLE `parts` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `part_name` varchar(150) NOT NULL,
  `part_sku` varchar(80) NOT NULL,
  `hsn_code` varchar(20) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'PCS',
  `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `min_stock` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `vendor_id` int(10) UNSIGNED DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parts`
--

INSERT INTO `parts` (`id`, `company_id`, `part_name`, `part_sku`, `hsn_code`, `unit`, `purchase_price`, `selling_price`, `gst_rate`, `min_stock`, `is_active`, `created_at`, `updated_at`, `category_id`, `vendor_id`, `status_code`, `deleted_at`) VALUES
(1, 1, 'Engine Oil 5W30 (1L)', 'EO-5W30-1L', '27101980', 'LITRE', 380.00, 520.00, 18.00, 10.00, 1, '2026-02-08 22:01:27', '2026-02-08 22:01:27', NULL, NULL, 'ACTIVE', NULL),
(2, 1, 'Oil Filter - Swift', 'OF-SWIFT', '84212300', 'PCS', 120.00, 180.00, 18.00, 8.00, 1, '2026-02-08 22:01:27', '2026-02-08 22:01:27', NULL, NULL, 'ACTIVE', NULL),
(3, 1, 'Air Filter - Activa', 'AF-ACTIVA', '84213100', 'PCS', 90.00, 150.00, 18.00, 8.00, 1, '2026-02-08 22:01:27', '2026-02-08 22:01:27', NULL, NULL, 'ACTIVE', NULL),
(4, 1, 'xbgxcvb', 'XGDB', NULL, 'PCS', 0.00, 0.00, 18.00, 0.00, 1, '2026-02-09 21:31:40', '2026-02-09 21:31:40', NULL, NULL, 'ACTIVE', NULL),
(5, 1, 'asd', 'ASD', NULL, 'PCS', 0.00, 0.00, 18.00, 0.00, 1, '2026-02-10 15:11:32', '2026-02-10 15:11:32', NULL, NULL, 'ACTIVE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `part_categories`
--

CREATE TABLE `part_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `category_code` varchar(40) NOT NULL,
  `category_name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `entry_type` enum('PAYMENT','REVERSAL') NOT NULL DEFAULT 'PAYMENT',
  `amount` decimal(12,2) NOT NULL,
  `paid_on` date NOT NULL,
  `payment_mode` enum('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED') NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `reversed_payment_id` int(10) UNSIGNED DEFAULT NULL,
  `is_reversed` tinyint(1) NOT NULL DEFAULT 0,
  `reversed_at` datetime DEFAULT NULL,
  `reversed_by` int(10) UNSIGNED DEFAULT NULL,
  `reverse_reason` varchar(255) DEFAULT NULL,
  `received_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `invoice_id`, `entry_type`, `amount`, `paid_on`, `payment_mode`, `reference_no`, `notes`, `reversed_payment_id`, `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason`, `received_by`, `created_at`) VALUES
(1, 1, 'PAYMENT', 566.00, '2026-02-10', 'CASH', NULL, NULL, NULL, 1, '2026-02-13 05:33:21', 1, 'iub', 1, '2026-02-09 20:57:57'),
(2, 2, 'PAYMENT', 10.00, '2026-02-10', 'CASH', NULL, NULL, NULL, 1, '2026-02-13 05:30:22', 1, 'j', 1, '2026-02-09 21:30:33'),
(11, 2, 'REVERSAL', -10.00, '2026-02-13', 'CASH', 'REV-2', 'j', 2, 0, NULL, NULL, 'j', 1, '2026-02-13 00:00:22'),
(12, 1, 'REVERSAL', -566.00, '2026-02-13', 'CASH', 'REV-1', 'iub', 1, 0, NULL, NULL, 'iub', 1, '2026-02-13 00:03:21');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_advances`
--

CREATE TABLE `payroll_advances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `advance_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `applied_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('OPEN','CLOSED','DELETED') NOT NULL DEFAULT 'OPEN',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_advances`
--

INSERT INTO `payroll_advances` (`id`, `user_id`, `company_id`, `garage_id`, `advance_date`, `amount`, `applied_amount`, `status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2026-02-12', 500.00, 400.00, 'CLOSED', NULL, 1, NULL, '2026-02-12 17:51:52', '2026-02-12 17:55:18'),
(2, 1, 1, 1, '2026-02-12', 10000.00, 0.00, 'OPEN', NULL, 1, NULL, '2026-02-12 17:55:37', '2026-02-12 17:55:37'),
(3, 1, 1, 1, '2026-02-13', 250.00, 0.00, 'OPEN', 'Smoke advance', 1, NULL, '2026-02-12 21:08:10', '2026-02-12 21:08:10'),
(4, 1, 1, 1, '2026-02-13', 250.00, 0.00, 'OPEN', 'Smoke advance', 1, NULL, '2026-02-12 21:09:09', '2026-02-12 21:09:09'),
(5, 1, 1, 1, '2026-02-13', 250.00, 0.00, 'OPEN', 'Smoke advance', 1, NULL, '2026-02-12 21:11:28', '2026-02-12 21:11:28');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_loans`
--

CREATE TABLE `payroll_loans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `loan_date` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `emi_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('ACTIVE','PAID','CLOSED','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_loans`
--

INSERT INTO `payroll_loans` (`id`, `user_id`, `company_id`, `garage_id`, `loan_date`, `total_amount`, `emi_amount`, `paid_amount`, `status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2026-02-12', 600.00, 100.00, 500.00, 'PAID', NULL, 1, NULL, '2026-02-12 17:52:05', '2026-02-12 21:11:29'),
(2, 1, 1, 1, '2026-02-13', 2000.00, 300.00, 600.00, 'ACTIVE', 'Smoke EMI loan', 1, NULL, '2026-02-12 21:08:10', '2026-02-12 21:11:29'),
(3, 1, 1, 1, '2026-02-13', 2000.00, 300.00, 0.00, 'ACTIVE', 'Smoke EMI loan', 1, NULL, '2026-02-12 21:09:09', '2026-02-12 21:09:09'),
(4, 1, 1, 1, '2026-02-13', 2000.00, 300.00, 0.00, 'ACTIVE', 'Smoke EMI loan', 1, NULL, '2026-02-12 21:11:28', '2026-02-12 21:11:28');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_loan_payments`
--

CREATE TABLE `payroll_loan_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `salary_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payment_date` date NOT NULL,
  `entry_type` enum('EMI','MANUAL','REVERSAL') NOT NULL DEFAULT 'EMI',
  `amount` decimal(12,2) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `reversed_payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_loan_payments`
--

INSERT INTO `payroll_loan_payments` (`id`, `loan_id`, `company_id`, `garage_id`, `salary_item_id`, `payment_date`, `entry_type`, `amount`, `reference_no`, `notes`, `reversed_payment_id`, `created_by`, `created_at`) VALUES
(1, 1, 1, 1, NULL, '2026-02-12', 'MANUAL', 100.00, 'MANUAL-1', NULL, NULL, 1, '2026-02-12 17:52:26'),
(2, 1, 1, 1, 1, '2026-02-12', 'EMI', 100.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 17:55:18'),
(3, 1, 1, 1, 1, '2026-02-13', 'EMI', 100.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 21:08:10'),
(4, 2, 1, 1, 1, '2026-02-13', 'EMI', 200.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 21:08:10'),
(5, 1, 1, 1, 1, '2026-02-13', 'EMI', 100.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 21:09:09'),
(6, 2, 1, 1, 1, '2026-02-13', 'EMI', 200.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 21:09:09'),
(7, 1, 1, 1, 1, '2026-02-13', 'EMI', 100.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 21:11:29'),
(8, 2, 1, 1, 1, '2026-02-13', 'EMI', 200.00, 'EMI-1', 'Auto EMI from salary sheet', NULL, 1, '2026-02-12 21:11:29');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_salary_items`
--

CREATE TABLE `payroll_salary_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sheet_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `salary_type` enum('MONTHLY','PER_DAY','PER_JOB') NOT NULL DEFAULT 'MONTHLY',
  `base_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_base` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `overtime_rate` decimal(12,2) DEFAULT NULL,
  `overtime_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `advance_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `manual_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_payable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions_applied` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('PENDING','PARTIAL','PAID','LOCKED') NOT NULL DEFAULT 'PENDING',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_salary_items`
--

INSERT INTO `payroll_salary_items` (`id`, `sheet_id`, `user_id`, `salary_type`, `base_amount`, `commission_base`, `commission_rate`, `commission_amount`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `advance_deduction`, `loan_deduction`, `manual_deduction`, `gross_amount`, `net_payable`, `paid_amount`, `deductions_applied`, `status`, `notes`, `created_at`) VALUES
(1, 2, 1, 'MONTHLY', 14000.00, 14000.00, 0.000, 0.00, 0.00, NULL, 0.00, 0.00, 0.00, 0.00, 14000.00, 14000.00, 29000.00, 0, 'PAID', NULL, '2026-02-12 17:31:36'),
(2, 3, 1, 'MONTHLY', 0.00, 0.00, 0.000, 0.00, 0.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 'PAID', 'Reversed salary entry: Smoke flow reverse salary entry', '2026-02-12 21:52:41');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_salary_payments`
--

CREATE TABLE `payroll_salary_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sheet_id` bigint(20) UNSIGNED NOT NULL,
  `salary_item_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `entry_type` enum('PAYMENT','REVERSAL') NOT NULL DEFAULT 'PAYMENT',
  `amount` decimal(12,2) NOT NULL,
  `payment_mode` enum('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT') NOT NULL DEFAULT 'BANK_TRANSFER',
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `reversed_payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_salary_payments`
--

INSERT INTO `payroll_salary_payments` (`id`, `sheet_id`, `salary_item_id`, `user_id`, `company_id`, `garage_id`, `payment_date`, `entry_type`, `amount`, `payment_mode`, `reference_no`, `notes`, `reversed_payment_id`, `created_by`, `created_at`) VALUES
(1, 2, 1, 1, 1, 1, '2026-02-12', 'PAYMENT', 15000.00, 'CASH', 'PAY-1', NULL, NULL, 1, '2026-02-12 17:32:14'),
(2, 2, 1, 1, 1, 1, '2026-02-12', 'PAYMENT', 10000.00, 'CASH', 'PAY-1', NULL, NULL, 1, '2026-02-12 17:55:18'),
(3, 2, 1, 1, 1, 1, '2026-02-12', 'PAYMENT', 500.00, 'CASH', 'PAY-1', NULL, NULL, 1, '2026-02-12 17:55:53'),
(4, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke salary payment', NULL, 1, '2026-02-12 21:08:10'),
(5, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke EMI deduction payment', NULL, 1, '2026-02-12 21:08:10'),
(6, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke salary payment', NULL, 1, '2026-02-12 21:09:09'),
(7, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke EMI deduction payment', NULL, 1, '2026-02-12 21:09:09'),
(8, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke salary payment', NULL, 1, '2026-02-12 21:11:28'),
(9, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke EMI deduction payment', NULL, 1, '2026-02-12 21:11:29'),
(10, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke salary payment', NULL, 1, '2026-02-12 21:13:21'),
(11, 2, 1, 1, 1, 1, '2026-02-13', 'PAYMENT', 500.00, 'BANK_TRANSFER', 'PAY-1', 'Smoke salary payment', NULL, 1, '2026-02-12 21:48:48'),
(12, 2, 1, 1, 1, 1, '2026-02-13', 'REVERSAL', -500.00, 'ADJUSTMENT', 'REV-11', 'Smoke reverse salary payment', 11, 1, '2026-02-12 21:51:57'),
(13, 3, 2, 1, 1, 1, '2026-02-13', 'PAYMENT', 400.00, 'BANK_TRANSFER', 'PAY-2', 'Smoke flow payment', NULL, 1, '2026-02-12 21:52:41'),
(14, 3, 2, 1, 1, 1, '2026-02-13', 'REVERSAL', -400.00, 'ADJUSTMENT', 'REV-13', 'Smoke flow reverse payment', 13, 1, '2026-02-12 21:52:54');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_salary_sheets`
--

CREATE TABLE `payroll_salary_sheets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `salary_month` char(7) NOT NULL,
  `status` enum('OPEN','LOCKED') NOT NULL DEFAULT 'OPEN',
  `total_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_payable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_salary_sheets`
--

INSERT INTO `payroll_salary_sheets` (`id`, `company_id`, `garage_id`, `salary_month`, `status`, `total_gross`, `total_deductions`, `total_payable`, `total_paid`, `locked_at`, `locked_by`, `created_by`, `created_at`) VALUES
(2, 1, 1, '2026-02', 'LOCKED', 14000.00, 0.00, 14000.00, 29000.00, '2026-02-13 03:21:57', 1, 1, '2026-02-12 17:31:36'),
(3, 1, 1, '2026-03', 'LOCKED', 0.00, 0.00, 0.00, 0.00, '2026-02-13 03:23:07', 1, 1, '2026-02-12 21:52:41');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_salary_structures`
--

CREATE TABLE `payroll_salary_structures` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `salary_type` enum('MONTHLY','PER_DAY','PER_JOB') NOT NULL DEFAULT 'MONTHLY',
  `base_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `overtime_rate` decimal(12,2) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_salary_structures`
--

INSERT INTO `payroll_salary_structures` (`id`, `user_id`, `company_id`, `garage_id`, `salary_type`, `base_amount`, `commission_rate`, `overtime_rate`, `status_code`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'MONTHLY', 1200.00, 0.000, NULL, 'ACTIVE', 1, 1, '2026-02-12 17:31:30', '2026-02-12 21:52:41');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `perm_key` varchar(80) NOT NULL,
  `perm_name` varchar(120) NOT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `perm_key`, `perm_name`, `status_code`) VALUES
(1, 'dashboard.view', 'View dashboard', 'ACTIVE'),
(2, 'company.manage', 'Manage companies', 'ACTIVE'),
(3, 'garage.manage', 'Manage garages', 'ACTIVE'),
(4, 'staff.manage', 'Manage staff and assignments', 'ACTIVE'),
(5, 'customer.view', 'View customers', 'ACTIVE'),
(6, 'customer.manage', 'Manage customers', 'ACTIVE'),
(7, 'vehicle.view', 'View vehicles', 'ACTIVE'),
(8, 'vehicle.manage', 'Manage vehicles', 'ACTIVE'),
(9, 'job.view', 'View job cards', 'ACTIVE'),
(10, 'job.manage', 'Create and manage job cards', 'ACTIVE'),
(11, 'job.assign', 'Assign mechanics to jobs', 'ACTIVE'),
(12, 'job.update', 'Update job progress', 'ACTIVE'),
(13, 'inventory.view', 'View inventory', 'ACTIVE'),
(14, 'inventory.manage', 'Manage inventory', 'ACTIVE'),
(15, 'invoice.view', 'View invoices', 'ACTIVE'),
(16, 'invoice.manage', 'Create and manage invoices', 'ACTIVE'),
(17, 'invoice.pay', 'Record invoice payments', 'ACTIVE'),
(18, 'report.view', 'View reports and analytics', 'ACTIVE'),
(19, 'financial_year.view', 'View financial years', 'ACTIVE'),
(20, 'financial_year.manage', 'Manage financial years', 'ACTIVE'),
(21, 'settings.view', 'View system settings', 'ACTIVE'),
(22, 'settings.manage', 'Manage system settings', 'ACTIVE'),
(23, 'role.view', 'View role master', 'ACTIVE'),
(24, 'role.manage', 'Manage role master', 'ACTIVE'),
(25, 'permission.view', 'View permissions', 'ACTIVE'),
(26, 'permission.manage', 'Manage permissions', 'ACTIVE'),
(27, 'staff.view', 'View staff', 'ACTIVE'),
(28, 'service.view', 'View service master', 'ACTIVE'),
(29, 'service.manage', 'Manage service master', 'ACTIVE'),
(30, 'part_category.view', 'View part categories', 'ACTIVE'),
(31, 'part_category.manage', 'Manage part categories', 'ACTIVE'),
(32, 'part_master.view', 'View parts/items master', 'ACTIVE'),
(33, 'part_master.manage', 'Manage parts/items master', 'ACTIVE'),
(34, 'vendor.view', 'View vendor master', 'ACTIVE'),
(35, 'vendor.manage', 'Manage vendor master', 'ACTIVE'),
(36, 'vis.view', 'View VIS masters', 'ACTIVE'),
(37, 'vis.manage', 'Manage VIS masters', 'ACTIVE'),
(59, 'job.create', 'Create job cards', 'ACTIVE'),
(60, 'job.edit', 'Edit job cards', 'ACTIVE'),
(61, 'job.close', 'Close job cards', 'ACTIVE'),
(62, 'inventory.adjust', 'Adjust inventory stock movements', 'ACTIVE'),
(63, 'inventory.transfer', 'Transfer stock between garages', 'ACTIVE'),
(64, 'inventory.negative', 'Allow negative inventory adjustments', 'ACTIVE'),
(65, 'billing.view', 'View billing and invoices', 'ACTIVE'),
(66, 'billing.create', 'Create invoice drafts from closed jobs', 'ACTIVE'),
(67, 'billing.finalize', 'Finalize invoices and lock GST', 'ACTIVE'),
(68, 'billing.cancel', 'Cancel invoices with audit reason', 'ACTIVE'),
(69, 'reports.view', 'View trusted operational reports and dashboard intelligence', 'ACTIVE'),
(70, 'reports.financial', 'View trusted financial and GST analytics', 'ACTIVE'),
(71, 'audit.view', 'View immutable audit logs', 'ACTIVE'),
(72, 'export.data', 'Export scoped operational and financial data', 'ACTIVE'),
(73, 'backup.manage', 'Manage backup metadata and recovery checks', 'ACTIVE'),
(74, 'purchase.view', 'View purchase module', 'ACTIVE'),
(75, 'purchase.manage', 'Create and update purchases', 'ACTIVE'),
(76, 'purchase.finalize', 'Finalize purchases', 'ACTIVE'),
(77, 'estimate.view', 'View estimates', 'ACTIVE'),
(78, 'estimate.create', 'Create estimates', 'ACTIVE'),
(79, 'estimate.edit', 'Edit estimates', 'ACTIVE'),
(80, 'estimate.approve', 'Approve estimates', 'ACTIVE'),
(81, 'estimate.reject', 'Reject estimates', 'ACTIVE'),
(82, 'estimate.convert', 'Convert approved estimates to job cards', 'ACTIVE'),
(83, 'estimate.print', 'Print estimates', 'ACTIVE'),
(84, 'estimate.manage', 'Manage estimate workflow end-to-end', 'ACTIVE'),
(85, 'outsourced.view', 'View outsourced works and vendor payables', 'ACTIVE'),
(86, 'outsourced.manage', 'Manage outsourced lifecycle and job linkage', 'ACTIVE'),
(87, 'outsourced.pay', 'Record outsourced vendor payments and reversals', 'ACTIVE'),
(88, 'vendor.payments', 'View vendor payable summaries and aging', 'ACTIVE'),
(89, 'purchase.payments', 'Record purchase payments and reversals', 'ACTIVE'),
(90, 'gst.reports', 'Access GST compliance reports', 'ACTIVE'),
(91, 'financial.reports', 'Access financial compliance reports', 'ACTIVE'),
(92, 'payroll.manage', 'Manage payroll, advances, and salary payouts', 'ACTIVE'),
(93, 'payroll.view', 'View payroll and earnings', 'ACTIVE'),
(94, 'expense.manage', 'Manage expenses and categories', 'ACTIVE'),
(95, 'expense.view', 'View expenses and reports', 'ACTIVE'),
(96, 'purchase.delete', 'Soft delete purchases with reversal checks', 'ACTIVE');

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `vendor_id` int(10) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(80) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `purchase_source` enum('VENDOR_ENTRY','MANUAL_ADJUSTMENT','TEMP_CONVERSION') NOT NULL DEFAULT 'VENDOR_ENTRY',
  `assignment_status` enum('ASSIGNED','UNASSIGNED') NOT NULL DEFAULT 'ASSIGNED',
  `purchase_status` enum('DRAFT','FINALIZED') NOT NULL DEFAULT 'DRAFT',
  `payment_status` enum('UNPAID','PARTIAL','PAID') NOT NULL DEFAULT 'UNPAID',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(10) UNSIGNED DEFAULT NULL,
  `delete_reason` varchar(255) DEFAULT NULL,
  `taxable_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `finalized_by` int(10) UNSIGNED DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`id`, `company_id`, `garage_id`, `vendor_id`, `invoice_number`, `purchase_date`, `purchase_source`, `assignment_status`, `purchase_status`, `payment_status`, `status_code`, `deleted_at`, `deleted_by`, `delete_reason`, `taxable_amount`, `gst_amount`, `grand_total`, `notes`, `created_by`, `finalized_by`, `finalized_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'SMK-ASSIGN-20260211022940', '2026-02-11', 'MANUAL_ADJUSTMENT', 'ASSIGNED', 'FINALIZED', 'UNPAID', 'ACTIVE', NULL, NULL, NULL, 475.00, 85.50, 560.50, 'SMOKE-ASSIGN-FINALIZE', 1, 1, '2026-02-11 02:29:40', '2026-02-10 20:59:18', '2026-02-10 20:59:40'),
(2, 1, 1, 1, 'SMK-VENDOR-20260211022959', '2026-02-11', 'VENDOR_ENTRY', 'ASSIGNED', 'FINALIZED', 'PARTIAL', 'ACTIVE', NULL, NULL, NULL, 75.00, 13.50, 88.50, 'SMOKE-VENDOR-CREATE-20260211022959', 1, 1, '2026-02-11 02:29:59', '2026-02-10 20:59:59', '2026-02-12 16:51:34'),
(3, 1, 1, NULL, NULL, '2026-02-11', 'TEMP_CONVERSION', 'UNASSIGNED', 'DRAFT', 'UNPAID', 'ACTIVE', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'Converted from temporary stock TMP-260211030230-053', 1, NULL, NULL, '2026-02-10 21:33:22', '2026-02-10 21:33:22'),
(4, 1, 1, NULL, NULL, '2026-02-11', 'TEMP_CONVERSION', 'UNASSIGNED', 'DRAFT', 'UNPAID', 'ACTIVE', NULL, NULL, NULL, 1140.00, 205.20, 1345.20, 'Converted from temporary stock TMP-260211030343-584 | SMOKE_TSM_20260211030107_PURCHASED_OK', 1, NULL, NULL, '2026-02-10 21:34:09', '2026-02-10 21:34:09'),
(5, 1, 1, 1, '11', '2026-02-12', 'VENDOR_ENTRY', 'ASSIGNED', 'FINALIZED', 'UNPAID', 'ACTIVE', NULL, NULL, NULL, 9000.00, 1620.00, 10620.00, NULL, 1, 1, '2026-02-12 22:25:59', '2026-02-12 16:55:59', '2026-02-12 16:55:59');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `purchase_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_items`
--

INSERT INTO `purchase_items` (`id`, `purchase_id`, `part_id`, `quantity`, `unit_cost`, `gst_rate`, `taxable_amount`, `gst_amount`, `total_amount`, `created_at`) VALUES
(1, 1, 1, 1.25, 380.00, 18.00, 475.00, 85.50, 560.50, '2026-02-10 20:59:18'),
(2, 2, 1, 0.75, 100.00, 18.00, 75.00, 13.50, 88.50, '2026-02-10 20:59:59'),
(3, 3, 4, 1000.00, 0.00, 18.00, 0.00, 0.00, 0.00, '2026-02-10 21:33:22'),
(4, 4, 1, 3.00, 380.00, 18.00, 1140.00, 205.20, 1345.20, '2026-02-10 21:34:09'),
(5, 5, 3, 100.00, 90.00, 18.00, 9000.00, 1620.00, 10620.00, '2026-02-12 16:55:59');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments`
--

CREATE TABLE `purchase_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `entry_type` enum('PAYMENT','REVERSAL') NOT NULL DEFAULT 'PAYMENT',
  `amount` decimal(12,2) NOT NULL,
  `payment_mode` enum('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT') NOT NULL DEFAULT 'BANK_TRANSFER',
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `reversed_payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_payments`
--

INSERT INTO `purchase_payments` (`id`, `purchase_id`, `company_id`, `garage_id`, `payment_date`, `entry_type`, `amount`, `payment_mode`, `reference_no`, `notes`, `reversed_payment_id`, `created_by`, `created_at`) VALUES
(1, 2, 1, 1, '2026-02-12', 'PAYMENT', 8.50, 'CASH', NULL, NULL, NULL, 1, '2026-02-12 16:51:34'),
(2, 2, 1, 1, '2026-02-12', 'PAYMENT', 10.00, 'CASH', NULL, NULL, NULL, 1, '2026-02-12 16:53:24'),
(3, 2, 1, 1, '2026-02-12', 'REVERSAL', -8.50, 'ADJUSTMENT', 'REV-1', '1', 1, 1, '2026-02-12 16:54:39');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `role_key` varchar(50) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_key`, `role_name`, `description`, `is_system`, `created_at`, `status_code`) VALUES
(1, 'super_admin', 'Super Admin', 'Global administrator with full control', 1, '2026-02-08 22:01:27', 'ACTIVE'),
(2, 'garage_owner', 'Garage Owner', 'Owner-level control for company operations', 1, '2026-02-08 22:01:27', 'ACTIVE'),
(3, 'manager', 'Manager', 'Branch manager for day-to-day operations', 1, '2026-02-08 22:01:27', 'ACTIVE'),
(4, 'mechanic', 'Mechanic', 'Technician handling assigned jobs', 1, '2026-02-08 22:01:27', 'ACTIVE'),
(5, 'accountant', 'Accountant / Billing Staff', 'Billing, payment and financial reporting', 1, '2026-02-08 22:01:27', 'ACTIVE');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(10) UNSIGNED NOT NULL,
  `permission_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15),
(1, 16),
(1, 17),
(1, 18),
(1, 19),
(1, 20),
(1, 21),
(1, 22),
(1, 23),
(1, 24),
(1, 25),
(1, 26),
(1, 27),
(1, 28),
(1, 29),
(1, 30),
(1, 31),
(1, 32),
(1, 33),
(1, 34),
(1, 35),
(1, 36),
(1, 37),
(1, 59),
(1, 60),
(1, 61),
(1, 62),
(1, 63),
(1, 64),
(1, 65),
(1, 66),
(1, 67),
(1, 68),
(1, 69),
(1, 70),
(1, 71),
(1, 72),
(1, 73),
(1, 74),
(1, 75),
(1, 76),
(1, 77),
(1, 78),
(1, 79),
(1, 80),
(1, 81),
(1, 82),
(1, 83),
(1, 84),
(1, 85),
(1, 86),
(1, 87),
(1, 88),
(1, 89),
(1, 90),
(1, 91),
(1, 92),
(1, 93),
(1, 94),
(1, 95),
(1, 96),
(2, 1),
(2, 2),
(2, 3),
(2, 4),
(2, 5),
(2, 6),
(2, 7),
(2, 8),
(2, 9),
(2, 10),
(2, 11),
(2, 12),
(2, 13),
(2, 14),
(2, 15),
(2, 16),
(2, 17),
(2, 18),
(2, 19),
(2, 20),
(2, 21),
(2, 22),
(2, 23),
(2, 25),
(2, 27),
(2, 28),
(2, 29),
(2, 30),
(2, 31),
(2, 32),
(2, 33),
(2, 34),
(2, 35),
(2, 36),
(2, 37),
(2, 59),
(2, 60),
(2, 61),
(2, 62),
(2, 63),
(2, 64),
(2, 65),
(2, 66),
(2, 67),
(2, 68),
(2, 69),
(2, 70),
(2, 71),
(2, 72),
(2, 73),
(2, 74),
(2, 75),
(2, 76),
(2, 77),
(2, 78),
(2, 79),
(2, 80),
(2, 81),
(2, 82),
(2, 83),
(2, 85),
(2, 86),
(2, 87),
(2, 88),
(2, 89),
(2, 90),
(2, 91),
(2, 92),
(2, 93),
(2, 94),
(2, 95),
(2, 96),
(3, 1),
(3, 4),
(3, 5),
(3, 6),
(3, 7),
(3, 8),
(3, 9),
(3, 10),
(3, 11),
(3, 12),
(3, 13),
(3, 14),
(3, 15),
(3, 16),
(3, 18),
(3, 19),
(3, 21),
(3, 23),
(3, 25),
(3, 27),
(3, 28),
(3, 29),
(3, 30),
(3, 31),
(3, 32),
(3, 33),
(3, 34),
(3, 35),
(3, 36),
(3, 59),
(3, 60),
(3, 61),
(3, 62),
(3, 63),
(3, 65),
(3, 66),
(3, 67),
(3, 68),
(3, 69),
(3, 74),
(3, 75),
(3, 76),
(3, 77),
(3, 78),
(3, 79),
(3, 80),
(3, 81),
(3, 82),
(3, 83),
(3, 85),
(3, 86),
(3, 87),
(3, 88),
(3, 89),
(3, 90),
(3, 91),
(3, 92),
(3, 93),
(3, 94),
(3, 95),
(3, 96),
(4, 1),
(4, 9),
(4, 12),
(4, 60),
(5, 1),
(5, 5),
(5, 7),
(5, 9),
(5, 15),
(5, 16),
(5, 17),
(5, 18),
(5, 19),
(5, 21),
(5, 28),
(5, 32),
(5, 34),
(5, 36),
(5, 65),
(5, 66),
(5, 67),
(5, 68),
(5, 69),
(5, 70),
(5, 71),
(5, 72),
(5, 74),
(5, 75),
(5, 76),
(5, 77),
(5, 83),
(5, 85),
(5, 87),
(5, 88),
(5, 89),
(5, 90),
(5, 91),
(5, 92),
(5, 93),
(5, 94),
(5, 95),
(5, 96);

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `service_code` varchar(40) NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `default_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `default_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `company_id`, `category_id`, `service_code`, `service_name`, `description`, `default_hours`, `default_rate`, `gst_rate`, `status_code`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 4, 'ZSDF', 'fszdf', NULL, 0.00, 0.00, 18.00, 'ACTIVE', 1, '2026-02-09 21:26:32', '2026-02-10 20:24:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `service_categories`
--

CREATE TABLE `service_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `category_code` varchar(40) NOT NULL,
  `category_name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_categories`
--

INSERT INTO `service_categories` (`id`, `company_id`, `category_code`, `category_name`, `description`, `status_code`, `deleted_at`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'MECHANICAL', 'Mechanical', 'Mechanical repairs and periodic maintenance', 'ACTIVE', NULL, 1, '2026-02-10 20:22:01', '2026-02-10 20:22:01'),
(2, 1, 'ELECTRICAL', 'Electrical', 'Electrical diagnostics and repair', 'ACTIVE', NULL, 1, '2026-02-10 20:22:01', '2026-02-10 20:22:01'),
(3, 1, 'BODYWORK', 'Bodywork', 'Body repairs, denting, painting and alignment', 'ACTIVE', NULL, 1, '2026-02-10 20:22:01', '2026-02-10 20:22:01'),
(4, 1, 'AC', 'AC', 'Air-conditioning service and repairs', 'ACTIVE', NULL, 1, '2026-02-10 20:22:01', '2026-02-10 20:22:01'),
(5, 1, 'DETAILING', 'Detailing', 'Cleaning, polishing and detailing services', 'ACTIVE', NULL, 1, '2026-02-10 20:22:01', '2026-02-10 20:22:01');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED DEFAULT NULL,
  `setting_group` varchar(80) NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `value_type` enum('STRING','NUMBER','BOOLEAN','JSON') NOT NULL DEFAULT 'STRING',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `company_id`, `garage_id`, `setting_group`, `setting_key`, `setting_value`, `value_type`, `status_code`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, NULL, 'GST', 'default_service_gst_rate', '18', 'NUMBER', 'ACTIVE', 1, '2026-02-09 19:58:20', '2026-02-09 19:58:20', NULL),
(2, 1, NULL, 'GST', 'default_parts_gst_rate', '18', 'NUMBER', 'ACTIVE', 1, '2026-02-09 19:58:20', '2026-02-09 19:58:20', NULL),
(3, 1, NULL, 'BILLING', 'invoice_prefix', 'INV', 'STRING', 'ACTIVE', 1, '2026-02-09 19:58:20', '2026-02-09 19:58:20', NULL),
(4, 1, NULL, 'GENERAL', 'timezone', 'Asia/Kolkata', 'STRING', 'ACTIVE', 1, '2026-02-09 19:58:20', '2026-02-09 19:58:20', NULL),
(5, 1, NULL, 'GST', 'default_service_gst_rate', '18', 'NUMBER', 'ACTIVE', 1, '2026-02-09 20:36:35', '2026-02-09 20:36:35', NULL),
(6, 1, NULL, 'GST', 'default_parts_gst_rate', '18', 'NUMBER', 'ACTIVE', 1, '2026-02-09 20:36:35', '2026-02-09 20:36:35', NULL),
(7, 1, NULL, 'BILLING', 'invoice_prefix', 'INV', 'STRING', 'ACTIVE', 1, '2026-02-09 20:36:35', '2026-02-09 20:36:35', NULL),
(8, 1, NULL, 'GENERAL', 'timezone', 'Asia/Kolkata', 'STRING', 'ACTIVE', 1, '2026-02-09 20:36:35', '2026-02-10 15:00:43', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `temp_stock_entries`
--

CREATE TABLE `temp_stock_entries` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `temp_ref` varchar(40) NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `status_code` enum('OPEN','RETURNED','PURCHASED','CONSUMED') NOT NULL DEFAULT 'OPEN',
  `notes` varchar(255) DEFAULT NULL,
  `resolution_notes` varchar(255) DEFAULT NULL,
  `purchase_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `temp_stock_entries`
--

INSERT INTO `temp_stock_entries` (`id`, `company_id`, `garage_id`, `temp_ref`, `part_id`, `quantity`, `status_code`, `notes`, `resolution_notes`, `purchase_id`, `created_by`, `resolved_by`, `resolved_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'TMP-260211030035-341', 3, 10.00, 'CONSUMED', '10', NULL, NULL, 1, 1, '2026-02-11 03:01:35', '2026-02-10 21:30:35', '2026-02-10 21:31:35'),
(2, 1, 1, 'TMP-260211030230-053', 4, 1000.00, 'PURCHASED', NULL, NULL, 3, 1, 1, '2026-02-11 03:03:22', '2026-02-10 21:32:30', '2026-02-10 21:33:22'),
(3, 1, 1, 'TMP-260211030249-493', 1, 2.00, 'RETURNED', 'SMOKE_TSM_20260211030107_RET', 'SMOKE_TSM_20260211030107_RETURNED_OK', NULL, 1, 1, '2026-02-11 03:03:12', '2026-02-10 21:32:49', '2026-02-10 21:33:12'),
(4, 1, 1, 'TMP-260211030343-584', 1, 3.00, 'PURCHASED', 'SMOKE_TSM_20260211030107_PUR', 'SMOKE_TSM_20260211030107_PURCHASED_OK', 4, 1, 1, '2026-02-11 03:04:09', '2026-02-10 21:33:43', '2026-02-10 21:34:09'),
(5, 1, 1, 'TMP-260211030447-233', 1, 1.00, 'CONSUMED', 'SMOKE_TSM_20260211030107_CONS', 'SMOKE_TSM_20260211030107_CONSUMED_OK', NULL, 1, 1, '2026-02-11 03:05:10', '2026-02-10 21:34:47', '2026-02-10 21:35:10'),
(6, 1, 1, 'TMP-260211030451-240', 2, 1.00, 'CONSUMED', NULL, NULL, NULL, 1, 1, '2026-02-11 03:05:12', '2026-02-10 21:34:51', '2026-02-10 21:35:12'),
(7, 1, 1, 'TMP-260211030538-343', 1, 4.00, 'OPEN', 'SMOKE_TSM_20260211030107_OPEN_ONLY', NULL, NULL, 1, NULL, NULL, '2026-02-10 21:35:38', '2026-02-10 21:35:38');

-- --------------------------------------------------------

--
-- Table structure for table `temp_stock_events`
--

CREATE TABLE `temp_stock_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `temp_entry_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL,
  `event_type` enum('TEMP_IN','RETURNED','PURCHASED','CONSUMED') NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `from_status` enum('OPEN','RETURNED','PURCHASED','CONSUMED') DEFAULT NULL,
  `to_status` enum('OPEN','RETURNED','PURCHASED','CONSUMED') DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `purchase_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `temp_stock_events`
--

INSERT INTO `temp_stock_events` (`id`, `temp_entry_id`, `company_id`, `garage_id`, `event_type`, `quantity`, `from_status`, `to_status`, `notes`, `purchase_id`, `created_by`, `created_at`) VALUES
(1, 1, 1, 1, 'TEMP_IN', 10.00, NULL, 'OPEN', '10', NULL, 1, '2026-02-10 21:30:35'),
(2, 1, 1, 1, 'CONSUMED', 10.00, 'OPEN', 'CONSUMED', NULL, NULL, 1, '2026-02-10 21:31:35'),
(3, 2, 1, 1, 'TEMP_IN', 1000.00, NULL, 'OPEN', NULL, NULL, 1, '2026-02-10 21:32:30'),
(4, 3, 1, 1, 'TEMP_IN', 2.00, NULL, 'OPEN', 'SMOKE_TSM_20260211030107_RET', NULL, 1, '2026-02-10 21:32:49'),
(5, 3, 1, 1, 'RETURNED', 2.00, 'OPEN', 'RETURNED', 'SMOKE_TSM_20260211030107_RETURNED_OK', NULL, 1, '2026-02-10 21:33:12'),
(6, 2, 1, 1, 'PURCHASED', 1000.00, 'OPEN', 'PURCHASED', NULL, 3, 1, '2026-02-10 21:33:22'),
(7, 4, 1, 1, 'TEMP_IN', 3.00, NULL, 'OPEN', 'SMOKE_TSM_20260211030107_PUR', NULL, 1, '2026-02-10 21:33:43'),
(8, 4, 1, 1, 'PURCHASED', 3.00, 'OPEN', 'PURCHASED', 'SMOKE_TSM_20260211030107_PURCHASED_OK', 4, 1, '2026-02-10 21:34:09'),
(9, 5, 1, 1, 'TEMP_IN', 1.00, NULL, 'OPEN', 'SMOKE_TSM_20260211030107_CONS', NULL, 1, '2026-02-10 21:34:47'),
(10, 6, 1, 1, 'TEMP_IN', 1.00, NULL, 'OPEN', NULL, NULL, 1, '2026-02-10 21:34:51'),
(11, 5, 1, 1, 'CONSUMED', 1.00, 'OPEN', 'CONSUMED', 'SMOKE_TSM_20260211030107_CONSUMED_OK', NULL, 1, '2026-02-10 21:35:10'),
(12, 6, 1, 1, 'CONSUMED', 1.00, 'OPEN', 'CONSUMED', NULL, NULL, 1, '2026-02-10 21:35:12'),
(13, 7, 1, 1, 'TEMP_IN', 4.00, NULL, 'OPEN', 'SMOKE_TSM_20260211030107_OPEN_ONLY', NULL, 1, '2026-02-10 21:35:38');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `primary_garage_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `company_id`, `role_id`, `primary_garage_id`, `name`, `email`, `username`, `password_hash`, `phone`, `is_active`, `last_login_at`, `created_at`, `updated_at`, `status_code`, `deleted_at`) VALUES
(1, 1, 1, 1, 'System Admin', 'admin@guruautocars.in', 'admin', '$2y$10$5Hg.0sOfS8iPW6Ehxkn1YOwtiLxQvvTMkLP/4.SEJATNb1i9.UlMq', '+91-9000000000', 1, '2026-02-13 05:59:54', '2026-02-08 22:01:27', '2026-02-13 00:29:54', 'ACTIVE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_garages`
--

CREATE TABLE `user_garages` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `garage_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_garages`
--

INSERT INTO `user_garages` (`user_id`, `garage_id`) VALUES
(1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `registration_no` varchar(30) NOT NULL,
  `vehicle_type` enum('2W','4W','COMMERCIAL') NOT NULL,
  `brand` varchar(80) NOT NULL,
  `brand_id` int(10) UNSIGNED DEFAULT NULL,
  `model` varchar(100) NOT NULL,
  `model_id` int(10) UNSIGNED DEFAULT NULL,
  `variant` varchar(100) DEFAULT NULL,
  `variant_id` int(10) UNSIGNED DEFAULT NULL,
  `fuel_type` enum('PETROL','DIESEL','CNG','EV','HYBRID','OTHER') NOT NULL DEFAULT 'PETROL',
  `model_year` smallint(5) UNSIGNED DEFAULT NULL,
  `model_year_id` int(10) UNSIGNED DEFAULT NULL,
  `color` varchar(40) DEFAULT NULL,
  `color_id` int(10) UNSIGNED DEFAULT NULL,
  `chassis_no` varchar(60) DEFAULT NULL,
  `engine_no` varchar(60) DEFAULT NULL,
  `odometer_km` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vis_variant_id` int(10) UNSIGNED DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `company_id`, `customer_id`, `registration_no`, `vehicle_type`, `brand`, `brand_id`, `model`, `model_id`, `variant`, `variant_id`, `fuel_type`, `model_year`, `model_year_id`, `color`, `color_id`, `chassis_no`, `engine_no`, `odometer_km`, `notes`, `is_active`, `created_at`, `updated_at`, `vis_variant_id`, `status_code`, `deleted_at`) VALUES
(1, 1, 1, 'MH12AB1234', '4W', 'Maruti Suzuki', 3, 'Swift', 3, NULL, NULL, 'PETROL', 2021, 32, 'White', 1, NULL, NULL, 25500, NULL, 1, '2026-02-08 22:01:27', '2026-02-10 19:48:07', NULL, 'ACTIVE', NULL),
(2, 1, 2, 'MH14CD5678', '2W', 'Honda', 2, 'Activa', 2, NULL, NULL, 'PETROL', 2020, 31, 'Grey', 4, NULL, NULL, 18200, NULL, 1, '2026-02-08 22:01:27', '2026-02-10 19:48:07', NULL, 'ACTIVE', NULL),
(3, 1, 2, 'ZSDVZDVVZSDV', '4W', 'sujuki', 4, 'baleno', 4, NULL, NULL, 'PETROL', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-02-09 21:43:48', '2026-02-10 19:48:07', 1, 'ACTIVE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_brands`
--

CREATE TABLE `vehicle_brands` (
  `id` int(10) UNSIGNED NOT NULL,
  `brand_name` varchar(100) NOT NULL,
  `vis_brand_id` int(10) UNSIGNED DEFAULT NULL,
  `source_code` enum('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_brands`
--

INSERT INTO `vehicle_brands` (`id`, `brand_name`, `vis_brand_id`, `source_code`, `status_code`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 'Suzuki', 1, 'VIS', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(2, 'Honda', NULL, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(3, 'Maruti Suzuki', NULL, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(4, 'sujuki', NULL, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_colors`
--

CREATE TABLE `vehicle_colors` (
  `id` int(10) UNSIGNED NOT NULL,
  `color_name` varchar(60) NOT NULL,
  `source_code` enum('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_colors`
--

INSERT INTO `vehicle_colors` (`id`, `color_name`, `source_code`, `status_code`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 'White', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(2, 'Black', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(3, 'Silver', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(4, 'Grey', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(5, 'Red', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(6, 'Blue', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(7, 'Brown', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(8, 'Green', 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_history`
--

CREATE TABLE `vehicle_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(40) NOT NULL,
  `action_note` varchar(255) DEFAULT NULL,
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot_json`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_history`
--

INSERT INTO `vehicle_history` (`id`, `vehicle_id`, `action_type`, `action_note`, `snapshot_json`, `created_by`, `created_at`) VALUES
(1, 3, 'CREATE', 'Vehicle created', '{\"registration_no\":\"ZSDVZDVVZSDV\",\"status_code\":\"ACTIVE\",\"vis_variant\":\"Suzuki \\/ Baleno \\/ VXi\"}', 1, '2026-02-09 21:43:48');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_models`
--

CREATE TABLE `vehicle_models` (
  `id` int(10) UNSIGNED NOT NULL,
  `brand_id` int(10) UNSIGNED NOT NULL,
  `model_name` varchar(120) NOT NULL,
  `vehicle_type` enum('2W','4W','COMMERCIAL') DEFAULT NULL,
  `vis_model_id` int(10) UNSIGNED DEFAULT NULL,
  `source_code` enum('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_models`
--

INSERT INTO `vehicle_models` (`id`, `brand_id`, `model_name`, `vehicle_type`, `vis_model_id`, `source_code`, `status_code`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'Baleno', '4W', 1, 'VIS', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(2, 2, 'Activa', NULL, NULL, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(3, 3, 'Swift', NULL, NULL, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(4, 4, 'baleno', NULL, NULL, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_model_years`
--

CREATE TABLE `vehicle_model_years` (
  `id` int(10) UNSIGNED NOT NULL,
  `year_value` smallint(5) UNSIGNED NOT NULL,
  `source_code` enum('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_model_years`
--

INSERT INTO `vehicle_model_years` (`id`, `year_value`, `source_code`, `status_code`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 1990, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(2, 1991, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(3, 1992, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(4, 1993, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(5, 1994, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(6, 1995, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(7, 1996, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(8, 1997, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(9, 1998, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(10, 1999, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(11, 2000, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(12, 2001, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(13, 2002, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(14, 2003, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(15, 2004, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(16, 2005, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(17, 2006, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(18, 2007, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(19, 2008, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(20, 2009, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(21, 2010, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(22, 2011, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(23, 2012, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(24, 2013, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(25, 2014, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(26, 2015, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(27, 2016, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(28, 2017, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(29, 2018, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(30, 2019, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(31, 2020, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(32, 2021, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(33, 2022, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(34, 2023, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(35, 2024, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(36, 2025, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(37, 2026, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07'),
(38, 2027, 'MANUAL', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_variants`
--

CREATE TABLE `vehicle_variants` (
  `id` int(10) UNSIGNED NOT NULL,
  `model_id` int(10) UNSIGNED NOT NULL,
  `variant_name` varchar(150) NOT NULL,
  `fuel_type` enum('PETROL','DIESEL','CNG','EV','HYBRID','OTHER') DEFAULT NULL,
  `engine_cc` varchar(30) DEFAULT NULL,
  `vis_variant_id` int(10) UNSIGNED DEFAULT NULL,
  `source_code` enum('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_variants`
--

INSERT INTO `vehicle_variants` (`id`, `model_id`, `variant_name`, `fuel_type`, `engine_cc`, `vis_variant_id`, `source_code`, `status_code`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'VXi', 'PETROL', '1104', 1, 'VIS', 'ACTIVE', NULL, '2026-02-10 19:48:07', '2026-02-10 19:48:07');

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `vendor_code` varchar(40) NOT NULL,
  `vendor_name` varchar(150) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `gstin` varchar(15) DEFAULT NULL,
  `address_line1` varchar(200) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vendors`
--

INSERT INTO `vendors` (`id`, `company_id`, `vendor_code`, `vendor_name`, `contact_person`, `phone`, `email`, `gstin`, `address_line1`, `city`, `state`, `pincode`, `status_code`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'ZSXDFV', 'zdcvzxvc', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'ACTIVE', '2026-02-10 20:50:40', '2026-02-10 20:50:40', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vis_brands`
--

CREATE TABLE `vis_brands` (
  `id` int(10) UNSIGNED NOT NULL,
  `brand_name` varchar(100) NOT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vis_brands`
--

INSERT INTO `vis_brands` (`id`, `brand_name`, `status_code`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Suzuki', 'ACTIVE', '2026-02-09 21:40:28', '2026-02-09 21:40:28', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vis_models`
--

CREATE TABLE `vis_models` (
  `id` int(10) UNSIGNED NOT NULL,
  `brand_id` int(10) UNSIGNED NOT NULL,
  `model_name` varchar(120) NOT NULL,
  `vehicle_type` enum('2W','4W','COMMERCIAL') NOT NULL DEFAULT '4W',
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vis_models`
--

INSERT INTO `vis_models` (`id`, `brand_id`, `model_name`, `vehicle_type`, `status_code`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'Baleno', '4W', 'ACTIVE', '2026-02-09 21:40:38', '2026-02-09 21:40:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vis_part_compatibility`
--

CREATE TABLE `vis_part_compatibility` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `variant_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `compatibility_note` varchar(255) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vis_part_compatibility`
--

INSERT INTO `vis_part_compatibility` (`id`, `company_id`, `variant_id`, `part_id`, `compatibility_note`, `status_code`, `created_at`, `deleted_at`) VALUES
(1, 1, 1, 1, 'test', 'ACTIVE', '2026-02-09 21:41:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vis_service_part_map`
--

CREATE TABLE `vis_service_part_map` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vis_service_part_map`
--

INSERT INTO `vis_service_part_map` (`id`, `company_id`, `service_id`, `part_id`, `is_required`, `status_code`, `created_at`, `deleted_at`) VALUES
(1, 1, 1, 3, 0, 'ACTIVE', '2026-02-09 21:41:56', NULL),
(2, 1, 1, 2, 1, 'ACTIVE', '2026-02-09 21:53:15', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vis_variants`
--

CREATE TABLE `vis_variants` (
  `id` int(10) UNSIGNED NOT NULL,
  `model_id` int(10) UNSIGNED NOT NULL,
  `variant_name` varchar(150) NOT NULL,
  `fuel_type` enum('PETROL','DIESEL','CNG','EV','HYBRID','OTHER') NOT NULL DEFAULT 'PETROL',
  `engine_cc` varchar(30) DEFAULT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vis_variants`
--

INSERT INTO `vis_variants` (`id`, `model_id`, `variant_name`, `fuel_type`, `engine_cc`, `status_code`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'VXi', 'PETROL', '1104', 'ACTIVE', '2026-02-09 21:40:52', '2026-02-09 21:40:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vis_variant_specs`
--

CREATE TABLE `vis_variant_specs` (
  `id` int(10) UNSIGNED NOT NULL,
  `variant_id` int(10) UNSIGNED NOT NULL,
  `spec_key` varchar(80) NOT NULL,
  `spec_value` varchar(255) NOT NULL,
  `status_code` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vis_variant_specs`
--

INSERT INTO `vis_variant_specs` (`id`, `variant_id`, `spec_key`, `spec_value`, `status_code`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'oil seal', '4405-05', 'ACTIVE', '2026-02-09 21:41:10', '2026-02-12 23:20:52', NULL),
(2, 1, 'oil seal', '4405-05', 'ACTIVE', '2026-02-10 21:19:05', '2026-02-12 23:20:56', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_logs_module` (`module_name`),
  ADD KEY `idx_audit_logs_created_at` (`created_at`),
  ADD KEY `idx_audit_scope_created` (`company_id`,`garage_id`,`created_at`),
  ADD KEY `idx_audit_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_audit_entity_action` (`entity_name`,`action_name`,`created_at`),
  ADD KEY `idx_audit_source_created` (`source_channel`,`created_at`),
  ADD KEY `idx_audit_request_id` (`request_id`);

--
-- Indexes for table `backup_integrity_checks`
--
ALTER TABLE `backup_integrity_checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_backup_checks_scope_date` (`company_id`,`checked_at`),
  ADD KEY `idx_backup_checks_result` (`result_code`,`checked_at`),
  ADD KEY `fk_backup_checks_checked_by` (`checked_by`);

--
-- Indexes for table `backup_runs`
--
ALTER TABLE `backup_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_backup_runs_scope_date` (`company_id`,`created_at`),
  ADD KEY `idx_backup_runs_status` (`status_code`,`created_at`),
  ADD KEY `fk_backup_runs_created_by` (`created_by`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gstin` (`gstin`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_customers_created_by` (`created_by`),
  ADD KEY `idx_customers_company_phone` (`company_id`,`phone`),
  ADD KEY `idx_customers_name` (`full_name`);

--
-- Indexes for table `customer_history`
--
ALTER TABLE `customer_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer_history_customer` (`customer_id`),
  ADD KEY `idx_customer_history_created` (`created_at`),
  ADD KEY `fk_customer_history_created_by` (`created_by`);

--
-- Indexes for table `data_export_logs`
--
ALTER TABLE `data_export_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_export_logs_scope_date` (`company_id`,`garage_id`,`requested_at`),
  ADD KEY `idx_export_logs_module` (`module_key`,`requested_at`),
  ADD KEY `fk_export_logs_requested_by` (`requested_by`);

--
-- Indexes for table `estimates`
--
ALTER TABLE `estimates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_estimate_number_per_garage` (`garage_id`,`estimate_number`),
  ADD KEY `idx_estimates_scope` (`company_id`,`garage_id`,`estimate_status`,`status_code`),
  ADD KEY `idx_estimates_customer` (`customer_id`),
  ADD KEY `idx_estimates_vehicle` (`vehicle_id`),
  ADD KEY `idx_estimates_created` (`created_at`),
  ADD KEY `idx_estimates_converted_job` (`converted_job_card_id`),
  ADD KEY `fk_estimates_created_by` (`created_by`),
  ADD KEY `fk_estimates_updated_by` (`updated_by`);

--
-- Indexes for table `estimate_counters`
--
ALTER TABLE `estimate_counters`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `estimate_history`
--
ALTER TABLE `estimate_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_estimate_history_estimate_created` (`estimate_id`,`created_at`),
  ADD KEY `idx_estimate_history_action` (`action_type`),
  ADD KEY `fk_estimate_history_created_by` (`created_by`);

--
-- Indexes for table `estimate_parts`
--
ALTER TABLE `estimate_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_estimate_parts_estimate` (`estimate_id`),
  ADD KEY `idx_estimate_parts_part` (`part_id`);

--
-- Indexes for table `estimate_services`
--
ALTER TABLE `estimate_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_estimate_services_estimate` (`estimate_id`),
  ADD KEY `idx_estimate_services_service` (`service_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_expense_source` (`company_id`,`source_type`,`source_id`,`entry_type`),
  ADD KEY `idx_expense_scope_date` (`company_id`,`garage_id`,`expense_date`),
  ADD KEY `idx_expense_category` (`category_id`),
  ADD KEY `fk_expense_garage` (`garage_id`),
  ADD KEY `fk_expense_reversed` (`reversed_expense_id`),
  ADD KEY `fk_expense_created_by` (`created_by`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_expense_category` (`company_id`,`garage_id`,`category_name`),
  ADD KEY `idx_expense_category_scope` (`company_id`,`garage_id`),
  ADD KEY `fk_expense_category_garage` (`garage_id`),
  ADD KEY `fk_expense_category_created_by` (`created_by`);

--
-- Indexes for table `financial_years`
--
ALTER TABLE `financial_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_financial_year` (`company_id`,`fy_label`),
  ADD KEY `idx_financial_year_dates` (`start_date`,`end_date`),
  ADD KEY `fk_financial_years_created_by` (`created_by`);

--
-- Indexes for table `garages`
--
ALTER TABLE `garages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_garage_code_per_company` (`company_id`,`code`),
  ADD KEY `idx_garage_company_status` (`company_id`,`status`);

--
-- Indexes for table `garage_inventory`
--
ALTER TABLE `garage_inventory`
  ADD PRIMARY KEY (`garage_id`,`part_id`),
  ADD KEY `fk_garage_inventory_part` (`part_id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_inventory_movement_uid` (`movement_uid`),
  ADD KEY `fk_inventory_part` (`part_id`),
  ADD KEY `fk_inventory_created_by` (`created_by`),
  ADD KEY `idx_inventory_created_at` (`created_at`),
  ADD KEY `idx_inventory_garage_part` (`garage_id`,`part_id`),
  ADD KEY `idx_inventory_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_inventory_movements_analytics_scope` (`company_id`,`garage_id`,`created_at`,`movement_type`,`reference_type`);

--
-- Indexes for table `inventory_transfers`
--
ALTER TABLE `inventory_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_inventory_transfer_ref` (`company_id`,`transfer_ref`),
  ADD UNIQUE KEY `uniq_inventory_transfer_request` (`request_uid`),
  ADD KEY `idx_inventory_transfer_company_created` (`company_id`,`created_at`),
  ADD KEY `idx_inventory_transfer_from_garage` (`from_garage_id`),
  ADD KEY `idx_inventory_transfer_to_garage` (`to_garage_id`),
  ADD KEY `idx_inventory_transfer_part` (`part_id`),
  ADD KEY `fk_inventory_transfer_created_by` (`created_by`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_invoice_number_per_garage` (`garage_id`,`invoice_number`),
  ADD UNIQUE KEY `uniq_invoice_job_card` (`job_card_id`),
  ADD KEY `fk_invoices_customer` (`customer_id`),
  ADD KEY `fk_invoices_vehicle` (`vehicle_id`),
  ADD KEY `fk_invoices_created_by` (`created_by`),
  ADD KEY `idx_invoices_date` (`invoice_date`),
  ADD KEY `idx_invoices_payment_status` (`payment_status`),
  ADD KEY `idx_invoices_status` (`invoice_status`),
  ADD KEY `idx_invoices_company_garage_status` (`company_id`,`garage_id`,`invoice_status`,`invoice_date`),
  ADD KEY `idx_invoices_financial_year` (`garage_id`,`financial_year_label`,`sequence_number`),
  ADD KEY `idx_invoices_analytics_scope` (`company_id`,`garage_id`,`invoice_status`,`invoice_date`,`job_card_id`);

--
-- Indexes for table `invoice_counters`
--
ALTER TABLE `invoice_counters`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_invoice_items_part` (`part_id`),
  ADD KEY `idx_invoice_items_invoice` (`invoice_id`),
  ADD KEY `idx_invoice_items_type` (`invoice_id`,`item_type`),
  ADD KEY `idx_invoice_items_service` (`service_id`);

--
-- Indexes for table `invoice_number_sequences`
--
ALTER TABLE `invoice_number_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_invoice_sequence` (`garage_id`,`financial_year_label`),
  ADD KEY `idx_invoice_sequence_company` (`company_id`,`garage_id`),
  ADD KEY `idx_invoice_sequence_fy` (`financial_year_id`);

--
-- Indexes for table `invoice_payment_history`
--
ALTER TABLE `invoice_payment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_payment_history_invoice` (`invoice_id`,`created_at`),
  ADD KEY `idx_invoice_payment_history_payment` (`payment_id`),
  ADD KEY `fk_invoice_payment_history_user` (`created_by`);

--
-- Indexes for table `invoice_status_history`
--
ALTER TABLE `invoice_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_status_history_invoice` (`invoice_id`,`created_at`),
  ADD KEY `idx_invoice_status_history_action` (`action_type`),
  ADD KEY `fk_invoice_status_history_user` (`created_by`);

--
-- Indexes for table `job_assignments`
--
ALTER TABLE `job_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_job_assignment_user` (`job_card_id`,`user_id`),
  ADD KEY `idx_job_assignments_job` (`job_card_id`,`status_code`),
  ADD KEY `idx_job_assignments_user` (`user_id`),
  ADD KEY `fk_job_assignments_created_by` (`created_by`),
  ADD KEY `idx_job_assignments_analytics_scope` (`user_id`,`status_code`,`job_card_id`);

--
-- Indexes for table `job_cards`
--
ALTER TABLE `job_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_job_number_per_garage` (`garage_id`,`job_number`),
  ADD KEY `fk_job_cards_customer` (`customer_id`),
  ADD KEY `fk_job_cards_assigned_to` (`assigned_to`),
  ADD KEY `fk_job_cards_service_advisor` (`service_advisor_id`),
  ADD KEY `fk_job_cards_created_by` (`created_by`),
  ADD KEY `fk_job_cards_updated_by` (`updated_by`),
  ADD KEY `idx_job_status` (`status`),
  ADD KEY `idx_job_opened` (`opened_at`),
  ADD KEY `idx_job_cards_status_code` (`status_code`),
  ADD KEY `idx_job_cards_company_garage_status` (`company_id`,`garage_id`,`status`),
  ADD KEY `idx_job_cards_analytics_scope` (`company_id`,`garage_id`,`status_code`,`status`,`closed_at`),
  ADD KEY `idx_job_cards_estimate_id` (`estimate_id`),
  ADD KEY `idx_job_cards_vehicle_odometer` (`vehicle_id`,`odometer_km`);

--
-- Indexes for table `job_counters`
--
ALTER TABLE `job_counters`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `job_history`
--
ALTER TABLE `job_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_job_history_job_created` (`job_card_id`,`created_at`),
  ADD KEY `idx_job_history_action` (`action_type`),
  ADD KEY `fk_job_history_created_by` (`created_by`);

--
-- Indexes for table `job_issues`
--
ALTER TABLE `job_issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_job_issues_job_card` (`job_card_id`);

--
-- Indexes for table `job_labor`
--
ALTER TABLE `job_labor`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_job_labor_job_card` (`job_card_id`),
  ADD KEY `idx_job_labor_service` (`service_id`),
  ADD KEY `idx_job_labor_execution_payable` (`execution_type`,`outsource_payable_status`),
  ADD KEY `idx_job_labor_outsource_vendor` (`outsource_vendor_id`),
  ADD KEY `idx_job_labor_outsource_paid_at` (`outsource_paid_at`),
  ADD KEY `idx_job_labor_outsource_expected_return` (`outsource_expected_return_date`);

--
-- Indexes for table `job_parts`
--
ALTER TABLE `job_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_job_parts_job_card` (`job_card_id`),
  ADD KEY `idx_job_parts_part` (`part_id`);

--
-- Indexes for table `outsourced_works`
--
ALTER TABLE `outsourced_works`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_outsourced_work_job_labor` (`job_labor_id`),
  ADD KEY `idx_outsourced_works_scope_status` (`company_id`,`garage_id`,`current_status`,`status_code`),
  ADD KEY `idx_outsourced_works_vendor_status` (`vendor_id`,`current_status`,`status_code`),
  ADD KEY `idx_outsourced_works_job` (`job_card_id`,`job_labor_id`),
  ADD KEY `idx_outsourced_works_expected_return` (`expected_return_date`),
  ADD KEY `idx_outsourced_works_paid_at` (`paid_at`),
  ADD KEY `fk_outsourced_works_garage` (`garage_id`),
  ADD KEY `fk_outsourced_works_created_by` (`created_by`),
  ADD KEY `fk_outsourced_works_updated_by` (`updated_by`);

--
-- Indexes for table `outsourced_work_history`
--
ALTER TABLE `outsourced_work_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_outsourced_work_history_work` (`outsourced_work_id`,`created_at`),
  ADD KEY `idx_outsourced_work_history_action` (`action_type`,`created_at`),
  ADD KEY `fk_outsourced_work_history_created_by` (`created_by`);

--
-- Indexes for table `outsourced_work_payments`
--
ALTER TABLE `outsourced_work_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_outsourced_work_payments_work_date` (`outsourced_work_id`,`payment_date`),
  ADD KEY `idx_outsourced_work_payments_scope_date` (`company_id`,`garage_id`,`payment_date`),
  ADD KEY `idx_outsourced_work_payments_entry` (`entry_type`,`created_at`),
  ADD KEY `idx_outsourced_work_payments_reversed` (`reversed_payment_id`),
  ADD KEY `fk_outsourced_work_payments_garage` (`garage_id`),
  ADD KEY `fk_outsourced_work_payments_created_by` (`created_by`);

--
-- Indexes for table `parts`
--
ALTER TABLE `parts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_parts_sku` (`company_id`,`part_sku`),
  ADD KEY `idx_parts_name` (`part_name`);

--
-- Indexes for table `part_categories`
--
ALTER TABLE `part_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_part_category_code` (`company_id`,`category_code`),
  ADD UNIQUE KEY `uniq_part_category_name` (`company_id`,`category_name`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_payments_received_by` (`received_by`),
  ADD KEY `idx_payments_invoice` (`invoice_id`),
  ADD KEY `idx_payments_paid_on` (`paid_on`),
  ADD KEY `idx_payments_analytics_scope` (`invoice_id`,`paid_on`,`payment_mode`),
  ADD KEY `idx_payments_invoice_entry` (`invoice_id`,`entry_type`,`paid_on`),
  ADD KEY `idx_payments_reversed` (`reversed_payment_id`),
  ADD KEY `idx_payments_is_reversed` (`is_reversed`,`paid_on`);

--
-- Indexes for table `payroll_advances`
--
ALTER TABLE `payroll_advances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_adv_user_date` (`user_id`,`advance_date`),
  ADD KEY `idx_payroll_adv_scope` (`company_id`,`garage_id`,`status`),
  ADD KEY `fk_payroll_adv_garage` (`garage_id`);

--
-- Indexes for table `payroll_loans`
--
ALTER TABLE `payroll_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_loan_user_date` (`user_id`,`loan_date`),
  ADD KEY `idx_payroll_loan_scope` (`company_id`,`garage_id`,`status`),
  ADD KEY `fk_payroll_loan_garage` (`garage_id`);

--
-- Indexes for table `payroll_loan_payments`
--
ALTER TABLE `payroll_loan_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_loan_pay_scope` (`company_id`,`garage_id`,`payment_date`),
  ADD KEY `idx_payroll_loan_pay_entry` (`entry_type`),
  ADD KEY `idx_payroll_loan_pay_loan` (`loan_id`),
  ADD KEY `fk_payroll_loan_pay_garage` (`garage_id`),
  ADD KEY `fk_payroll_loan_pay_salary_item` (`salary_item_id`),
  ADD KEY `fk_payroll_loan_pay_reversed` (`reversed_payment_id`);

--
-- Indexes for table `payroll_salary_items`
--
ALTER TABLE `payroll_salary_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_item_sheet` (`sheet_id`),
  ADD KEY `idx_payroll_item_user` (`user_id`);

--
-- Indexes for table `payroll_salary_payments`
--
ALTER TABLE `payroll_salary_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_pay_scope` (`company_id`,`garage_id`,`payment_date`),
  ADD KEY `idx_payroll_pay_entry` (`entry_type`),
  ADD KEY `idx_payroll_pay_item` (`salary_item_id`),
  ADD KEY `fk_payroll_pay_sheet` (`sheet_id`),
  ADD KEY `fk_payroll_pay_user` (`user_id`),
  ADD KEY `fk_payroll_pay_garage` (`garage_id`),
  ADD KEY `fk_payroll_pay_reversed` (`reversed_payment_id`);

--
-- Indexes for table `payroll_salary_sheets`
--
ALTER TABLE `payroll_salary_sheets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_payroll_sheet` (`company_id`,`garage_id`,`salary_month`),
  ADD KEY `idx_payroll_sheet_status` (`status`),
  ADD KEY `fk_payroll_sheet_garage` (`garage_id`),
  ADD KEY `fk_payroll_sheet_locked_by` (`locked_by`),
  ADD KEY `fk_payroll_sheet_created_by` (`created_by`);

--
-- Indexes for table `payroll_salary_structures`
--
ALTER TABLE `payroll_salary_structures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_payroll_structure_user_garage` (`user_id`,`garage_id`),
  ADD KEY `idx_payroll_structure_company_garage` (`company_id`,`garage_id`),
  ADD KEY `fk_payroll_structure_garage` (`garage_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `perm_key` (`perm_key`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchases_scope_date` (`company_id`,`garage_id`,`purchase_date`),
  ADD KEY `idx_purchases_vendor` (`vendor_id`),
  ADD KEY `idx_purchases_assignment` (`assignment_status`,`purchase_status`),
  ADD KEY `idx_purchases_payment` (`payment_status`),
  ADD KEY `fk_purchases_garage` (`garage_id`),
  ADD KEY `fk_purchases_created_by` (`created_by`),
  ADD KEY `fk_purchases_finalized_by` (`finalized_by`),
  ADD KEY `idx_purchases_scope_status` (`company_id`,`garage_id`,`status_code`,`purchase_date`),
  ADD KEY `idx_purchases_deleted` (`status_code`,`deleted_at`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_items_purchase` (`purchase_id`),
  ADD KEY `idx_purchase_items_part` (`part_id`);

--
-- Indexes for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_payments_purchase_date` (`purchase_id`,`payment_date`),
  ADD KEY `idx_purchase_payments_scope_date` (`company_id`,`garage_id`,`payment_date`),
  ADD KEY `idx_purchase_payments_entry` (`entry_type`,`created_at`),
  ADD KEY `idx_purchase_payments_reversed` (`reversed_payment_id`),
  ADD KEY `fk_purchase_payments_garage` (`garage_id`),
  ADD KEY `fk_purchase_payments_created_by` (`created_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_key` (`role_key`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_role_permissions_permission` (`permission_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_service_code` (`company_id`,`service_code`),
  ADD KEY `idx_service_name` (`service_name`),
  ADD KEY `fk_services_created_by` (`created_by`),
  ADD KEY `idx_services_category` (`category_id`);

--
-- Indexes for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_service_category_code` (`company_id`,`category_code`),
  ADD UNIQUE KEY `uniq_service_category_name` (`company_id`,`category_name`),
  ADD KEY `idx_service_category_status` (`company_id`,`status_code`),
  ADD KEY `fk_service_categories_created_by` (`created_by`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_setting_scope` (`company_id`,`garage_id`,`setting_key`),
  ADD KEY `idx_setting_group` (`setting_group`),
  ADD KEY `fk_system_settings_garage` (`garage_id`),
  ADD KEY `fk_system_settings_created_by` (`created_by`);

--
-- Indexes for table `temp_stock_entries`
--
ALTER TABLE `temp_stock_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_temp_stock_ref` (`company_id`,`temp_ref`),
  ADD KEY `idx_temp_stock_scope_status` (`company_id`,`garage_id`,`status_code`,`created_at`),
  ADD KEY `idx_temp_stock_part_status` (`part_id`,`status_code`),
  ADD KEY `idx_temp_stock_purchase` (`purchase_id`),
  ADD KEY `fk_temp_stock_garage` (`garage_id`),
  ADD KEY `fk_temp_stock_created_by` (`created_by`),
  ADD KEY `fk_temp_stock_resolved_by` (`resolved_by`);

--
-- Indexes for table `temp_stock_events`
--
ALTER TABLE `temp_stock_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_temp_stock_events_entry_created` (`temp_entry_id`,`created_at`),
  ADD KEY `idx_temp_stock_events_scope_created` (`company_id`,`garage_id`,`created_at`),
  ADD KEY `idx_temp_stock_events_type_created` (`event_type`,`created_at`),
  ADD KEY `fk_temp_stock_events_garage` (`garage_id`),
  ADD KEY `fk_temp_stock_events_created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_users_role` (`role_id`),
  ADD KEY `fk_users_primary_garage` (`primary_garage_id`),
  ADD KEY `idx_users_company_role` (`company_id`,`role_id`),
  ADD KEY `idx_users_active` (`is_active`);

--
-- Indexes for table `user_garages`
--
ALTER TABLE `user_garages`
  ADD PRIMARY KEY (`user_id`,`garage_id`),
  ADD KEY `fk_user_garages_garage` (`garage_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_registration` (`company_id`,`registration_no`),
  ADD KEY `idx_vehicles_customer` (`customer_id`),
  ADD KEY `idx_vehicles_brand_model` (`brand`,`model`),
  ADD KEY `idx_vehicles_brand_id` (`brand_id`),
  ADD KEY `idx_vehicles_model_id` (`model_id`),
  ADD KEY `idx_vehicles_variant_id` (`variant_id`),
  ADD KEY `idx_vehicles_model_year_id` (`model_year_id`),
  ADD KEY `idx_vehicles_color_id` (`color_id`);

--
-- Indexes for table `vehicle_brands`
--
ALTER TABLE `vehicle_brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_brand_name` (`brand_name`),
  ADD KEY `idx_vehicle_brands_status` (`status_code`),
  ADD KEY `idx_vehicle_brands_vis` (`vis_brand_id`);

--
-- Indexes for table `vehicle_colors`
--
ALTER TABLE `vehicle_colors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_color_name` (`color_name`),
  ADD KEY `idx_vehicle_colors_status` (`status_code`);

--
-- Indexes for table `vehicle_history`
--
ALTER TABLE `vehicle_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_history_vehicle` (`vehicle_id`),
  ADD KEY `idx_vehicle_history_created` (`created_at`),
  ADD KEY `fk_vehicle_history_created_by` (`created_by`);

--
-- Indexes for table `vehicle_models`
--
ALTER TABLE `vehicle_models`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_model_name` (`brand_id`,`model_name`),
  ADD KEY `idx_vehicle_models_status` (`status_code`),
  ADD KEY `idx_vehicle_models_vis` (`vis_model_id`);

--
-- Indexes for table `vehicle_model_years`
--
ALTER TABLE `vehicle_model_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_model_year` (`year_value`),
  ADD KEY `idx_vehicle_model_years_status` (`status_code`);

--
-- Indexes for table `vehicle_variants`
--
ALTER TABLE `vehicle_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_variant_name` (`model_id`,`variant_name`),
  ADD KEY `idx_vehicle_variants_status` (`status_code`),
  ADD KEY `idx_vehicle_variants_vis` (`vis_variant_id`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vendor_code` (`company_id`,`vendor_code`),
  ADD KEY `idx_vendor_name` (`vendor_name`);

--
-- Indexes for table `vis_brands`
--
ALTER TABLE `vis_brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vis_brand_name` (`brand_name`);

--
-- Indexes for table `vis_models`
--
ALTER TABLE `vis_models`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vis_model_name` (`brand_id`,`model_name`);

--
-- Indexes for table `vis_part_compatibility`
--
ALTER TABLE `vis_part_compatibility`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vis_part_compatibility` (`company_id`,`variant_id`,`part_id`),
  ADD KEY `idx_vis_compat_variant` (`variant_id`),
  ADD KEY `idx_vis_compat_part` (`part_id`);

--
-- Indexes for table `vis_service_part_map`
--
ALTER TABLE `vis_service_part_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vis_service_part` (`company_id`,`service_id`,`part_id`),
  ADD KEY `idx_vis_service_map_service` (`service_id`),
  ADD KEY `idx_vis_service_map_part` (`part_id`);

--
-- Indexes for table `vis_variants`
--
ALTER TABLE `vis_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vis_variant_name` (`model_id`,`variant_name`);

--
-- Indexes for table `vis_variant_specs`
--
ALTER TABLE `vis_variant_specs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vis_variant_specs_variant` (`variant_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=166;

--
-- AUTO_INCREMENT for table `backup_integrity_checks`
--
ALTER TABLE `backup_integrity_checks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `backup_runs`
--
ALTER TABLE `backup_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `customer_history`
--
ALTER TABLE `customer_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `data_export_logs`
--
ALTER TABLE `data_export_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `estimates`
--
ALTER TABLE `estimates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `estimate_history`
--
ALTER TABLE `estimate_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `estimate_parts`
--
ALTER TABLE `estimate_parts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `estimate_services`
--
ALTER TABLE `estimate_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `financial_years`
--
ALTER TABLE `financial_years`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `garages`
--
ALTER TABLE `garages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `inventory_transfers`
--
ALTER TABLE `inventory_transfers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `invoice_number_sequences`
--
ALTER TABLE `invoice_number_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `invoice_payment_history`
--
ALTER TABLE `invoice_payment_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `invoice_status_history`
--
ALTER TABLE `invoice_status_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `job_assignments`
--
ALTER TABLE `job_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `job_cards`
--
ALTER TABLE `job_cards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `job_history`
--
ALTER TABLE `job_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `job_issues`
--
ALTER TABLE `job_issues`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `job_labor`
--
ALTER TABLE `job_labor`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `job_parts`
--
ALTER TABLE `job_parts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `outsourced_works`
--
ALTER TABLE `outsourced_works`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `outsourced_work_history`
--
ALTER TABLE `outsourced_work_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `outsourced_work_payments`
--
ALTER TABLE `outsourced_work_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `parts`
--
ALTER TABLE `parts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `part_categories`
--
ALTER TABLE `part_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `payroll_advances`
--
ALTER TABLE `payroll_advances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payroll_loans`
--
ALTER TABLE `payroll_loans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payroll_loan_payments`
--
ALTER TABLE `payroll_loan_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payroll_salary_items`
--
ALTER TABLE `payroll_salary_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payroll_salary_payments`
--
ALTER TABLE `payroll_salary_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `payroll_salary_sheets`
--
ALTER TABLE `payroll_salary_sheets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payroll_salary_structures`
--
ALTER TABLE `payroll_salary_structures`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `service_categories`
--
ALTER TABLE `service_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `temp_stock_entries`
--
ALTER TABLE `temp_stock_entries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `temp_stock_events`
--
ALTER TABLE `temp_stock_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `vehicle_brands`
--
ALTER TABLE `vehicle_brands`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicle_colors`
--
ALTER TABLE `vehicle_colors`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `vehicle_history`
--
ALTER TABLE `vehicle_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vehicle_models`
--
ALTER TABLE `vehicle_models`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicle_model_years`
--
ALTER TABLE `vehicle_model_years`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `vehicle_variants`
--
ALTER TABLE `vehicle_variants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vis_brands`
--
ALTER TABLE `vis_brands`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vis_models`
--
ALTER TABLE `vis_models`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vis_part_compatibility`
--
ALTER TABLE `vis_part_compatibility`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vis_service_part_map`
--
ALTER TABLE `vis_service_part_map`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vis_variants`
--
ALTER TABLE `vis_variants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vis_variant_specs`
--
ALTER TABLE `vis_variant_specs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `backup_integrity_checks`
--
ALTER TABLE `backup_integrity_checks`
  ADD CONSTRAINT `fk_backup_checks_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_backup_checks_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `backup_runs`
--
ALTER TABLE `backup_runs`
  ADD CONSTRAINT `fk_backup_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_backup_runs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_customers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_history`
--
ALTER TABLE `customer_history`
  ADD CONSTRAINT `fk_customer_history_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_customer_history_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `data_export_logs`
--
ALTER TABLE `data_export_logs`
  ADD CONSTRAINT `fk_export_logs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_export_logs_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `estimates`
--
ALTER TABLE `estimates`
  ADD CONSTRAINT `fk_estimates_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_estimates_converted_job` FOREIGN KEY (`converted_job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_estimates_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_estimates_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_estimates_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`),
  ADD CONSTRAINT `fk_estimates_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_estimates_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);

--
-- Constraints for table `estimate_counters`
--
ALTER TABLE `estimate_counters`
  ADD CONSTRAINT `fk_estimate_counters_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `estimate_history`
--
ALTER TABLE `estimate_history`
  ADD CONSTRAINT `fk_estimate_history_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_estimate_history_estimate` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `estimate_parts`
--
ALTER TABLE `estimate_parts`
  ADD CONSTRAINT `fk_estimate_parts_estimate` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_estimate_parts_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`);

--
-- Constraints for table `estimate_services`
--
ALTER TABLE `estimate_services`
  ADD CONSTRAINT `fk_estimate_services_estimate` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_estimate_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_expense_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_expense_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_expense_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_expense_reversed` FOREIGN KEY (`reversed_expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD CONSTRAINT `fk_expense_category_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_expense_category_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_expense_category_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `financial_years`
--
ALTER TABLE `financial_years`
  ADD CONSTRAINT `fk_financial_years_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_financial_years_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `garages`
--
ALTER TABLE `garages`
  ADD CONSTRAINT `fk_garages_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `garage_inventory`
--
ALTER TABLE `garage_inventory`
  ADD CONSTRAINT `fk_garage_inventory_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_garage_inventory_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `fk_inventory_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inventory_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`);

--
-- Constraints for table `inventory_transfers`
--
ALTER TABLE `inventory_transfers`
  ADD CONSTRAINT `fk_inventory_transfer_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_inventory_transfer_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_inventory_transfer_from_garage` FOREIGN KEY (`from_garage_id`) REFERENCES `garages` (`id`),
  ADD CONSTRAINT `fk_inventory_transfer_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`),
  ADD CONSTRAINT `fk_inventory_transfer_to_garage` FOREIGN KEY (`to_garage_id`) REFERENCES `garages` (`id`);

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_invoices_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`),
  ADD CONSTRAINT `fk_invoices_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`),
  ADD CONSTRAINT `fk_invoices_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);

--
-- Constraints for table `invoice_counters`
--
ALTER TABLE `invoice_counters`
  ADD CONSTRAINT `fk_invoice_counters_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_number_sequences`
--
ALTER TABLE `invoice_number_sequences`
  ADD CONSTRAINT `fk_invoice_sequence_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoice_sequence_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_payment_history`
--
ALTER TABLE `invoice_payment_history`
  ADD CONSTRAINT `fk_invoice_payment_history_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoice_payment_history_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_invoice_payment_history_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_status_history`
--
ALTER TABLE `invoice_status_history`
  ADD CONSTRAINT `fk_invoice_status_history_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoice_status_history_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `job_assignments`
--
ALTER TABLE `job_assignments`
  ADD CONSTRAINT `fk_job_assignments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_assignments_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_job_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_cards`
--
ALTER TABLE `job_cards`
  ADD CONSTRAINT `fk_job_cards_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_cards_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_job_cards_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_cards_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_job_cards_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`),
  ADD CONSTRAINT `fk_job_cards_service_advisor` FOREIGN KEY (`service_advisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_cards_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_cards_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);

--
-- Constraints for table `job_counters`
--
ALTER TABLE `job_counters`
  ADD CONSTRAINT `fk_job_counters_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_history`
--
ALTER TABLE `job_history`
  ADD CONSTRAINT `fk_job_history_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_history_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_issues`
--
ALTER TABLE `job_issues`
  ADD CONSTRAINT `fk_job_issues_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_labor`
--
ALTER TABLE `job_labor`
  ADD CONSTRAINT `fk_job_labor_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_parts`
--
ALTER TABLE `job_parts`
  ADD CONSTRAINT `fk_job_parts_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_job_parts_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`);

--
-- Constraints for table `outsourced_works`
--
ALTER TABLE `outsourced_works`
  ADD CONSTRAINT `fk_outsourced_works_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_outsourced_works_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_outsourced_works_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_outsourced_works_job_card` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_outsourced_works_job_labor` FOREIGN KEY (`job_labor_id`) REFERENCES `job_labor` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_outsourced_works_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_outsourced_works_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `outsourced_work_history`
--
ALTER TABLE `outsourced_work_history`
  ADD CONSTRAINT `fk_outsourced_work_history_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_outsourced_work_history_work` FOREIGN KEY (`outsourced_work_id`) REFERENCES `outsourced_works` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `outsourced_work_payments`
--
ALTER TABLE `outsourced_work_payments`
  ADD CONSTRAINT `fk_outsourced_work_payments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_outsourced_work_payments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_outsourced_work_payments_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_outsourced_work_payments_reversed` FOREIGN KEY (`reversed_payment_id`) REFERENCES `outsourced_work_payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_outsourced_work_payments_work` FOREIGN KEY (`outsourced_work_id`) REFERENCES `outsourced_works` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parts`
--
ALTER TABLE `parts`
  ADD CONSTRAINT `fk_parts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `part_categories`
--
ALTER TABLE `part_categories`
  ADD CONSTRAINT `fk_part_categories_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payroll_advances`
--
ALTER TABLE `payroll_advances`
  ADD CONSTRAINT `fk_payroll_adv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_adv_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_adv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_loans`
--
ALTER TABLE `payroll_loans`
  ADD CONSTRAINT `fk_payroll_loan_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_loan_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_loan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_loan_payments`
--
ALTER TABLE `payroll_loan_payments`
  ADD CONSTRAINT `fk_payroll_loan_pay_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_loan_pay_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_loan_pay_loan` FOREIGN KEY (`loan_id`) REFERENCES `payroll_loans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_loan_pay_reversed` FOREIGN KEY (`reversed_payment_id`) REFERENCES `payroll_loan_payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payroll_loan_pay_salary_item` FOREIGN KEY (`salary_item_id`) REFERENCES `payroll_salary_items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payroll_salary_items`
--
ALTER TABLE `payroll_salary_items`
  ADD CONSTRAINT `fk_payroll_item_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `payroll_salary_sheets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_item_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_salary_payments`
--
ALTER TABLE `payroll_salary_payments`
  ADD CONSTRAINT `fk_payroll_pay_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_pay_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_pay_item` FOREIGN KEY (`salary_item_id`) REFERENCES `payroll_salary_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_pay_reversed` FOREIGN KEY (`reversed_payment_id`) REFERENCES `payroll_salary_payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payroll_pay_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `payroll_salary_sheets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_pay_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_salary_sheets`
--
ALTER TABLE `payroll_salary_sheets`
  ADD CONSTRAINT `fk_payroll_sheet_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_sheet_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payroll_sheet_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_sheet_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payroll_salary_structures`
--
ALTER TABLE `payroll_salary_structures`
  ADD CONSTRAINT `fk_payroll_structure_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_structure_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_structure_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_purchases_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchases_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purchases_finalized_by` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purchases_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchases_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `fk_purchase_items_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`),
  ADD CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  ADD CONSTRAINT `fk_purchase_payments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_payments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purchase_payments_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_payments_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_payments_reversed` FOREIGN KEY (`reversed_payment_id`) REFERENCES `purchase_payments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `fk_services_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_services_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_services_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD CONSTRAINT `fk_service_categories_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_service_categories_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `fk_system_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_system_settings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_system_settings_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `temp_stock_entries`
--
ALTER TABLE `temp_stock_entries`
  ADD CONSTRAINT `fk_temp_stock_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_temp_stock_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_temp_stock_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_temp_stock_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`),
  ADD CONSTRAINT `fk_temp_stock_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `temp_stock_events`
--
ALTER TABLE `temp_stock_events`
  ADD CONSTRAINT `fk_temp_stock_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_temp_stock_events_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_temp_stock_events_entry` FOREIGN KEY (`temp_entry_id`) REFERENCES `temp_stock_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_temp_stock_events_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_users_primary_garage` FOREIGN KEY (`primary_garage_id`) REFERENCES `garages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `user_garages`
--
ALTER TABLE `user_garages`
  ADD CONSTRAINT `fk_user_garages_garage` FOREIGN KEY (`garage_id`) REFERENCES `garages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_garages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_brand_id` FOREIGN KEY (`brand_id`) REFERENCES `vehicle_brands` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vehicles_color_id` FOREIGN KEY (`color_id`) REFERENCES `vehicle_colors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vehicles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vehicles_model_id` FOREIGN KEY (`model_id`) REFERENCES `vehicle_models` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vehicles_model_year_id` FOREIGN KEY (`model_year_id`) REFERENCES `vehicle_model_years` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vehicles_variant_id` FOREIGN KEY (`variant_id`) REFERENCES `vehicle_variants` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_brands`
--
ALTER TABLE `vehicle_brands`
  ADD CONSTRAINT `fk_vehicle_brands_vis` FOREIGN KEY (`vis_brand_id`) REFERENCES `vis_brands` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_history`
--
ALTER TABLE `vehicle_history`
  ADD CONSTRAINT `fk_vehicle_history_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vehicle_history_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_models`
--
ALTER TABLE `vehicle_models`
  ADD CONSTRAINT `fk_vehicle_models_brand` FOREIGN KEY (`brand_id`) REFERENCES `vehicle_brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vehicle_models_vis` FOREIGN KEY (`vis_model_id`) REFERENCES `vis_models` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_variants`
--
ALTER TABLE `vehicle_variants`
  ADD CONSTRAINT `fk_vehicle_variants_model` FOREIGN KEY (`model_id`) REFERENCES `vehicle_models` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vehicle_variants_vis` FOREIGN KEY (`vis_variant_id`) REFERENCES `vis_variants` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vendors`
--
ALTER TABLE `vendors`
  ADD CONSTRAINT `fk_vendors_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vis_models`
--
ALTER TABLE `vis_models`
  ADD CONSTRAINT `fk_vis_models_brand` FOREIGN KEY (`brand_id`) REFERENCES `vis_brands` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vis_part_compatibility`
--
ALTER TABLE `vis_part_compatibility`
  ADD CONSTRAINT `fk_vis_compat_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vis_compat_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vis_compat_variant` FOREIGN KEY (`variant_id`) REFERENCES `vis_variants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vis_service_part_map`
--
ALTER TABLE `vis_service_part_map`
  ADD CONSTRAINT `fk_vis_service_map_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vis_service_map_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vis_service_map_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vis_variants`
--
ALTER TABLE `vis_variants`
  ADD CONSTRAINT `fk_vis_variants_model` FOREIGN KEY (`model_id`) REFERENCES `vis_models` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vis_variant_specs`
--
ALTER TABLE `vis_variant_specs`
  ADD CONSTRAINT `fk_vis_variant_specs_variant` FOREIGN KEY (`variant_id`) REFERENCES `vis_variants` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
