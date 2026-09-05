-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql203.infinityfree.com
-- Generation Time: Jul 23, 2026 at 03:52 AM
-- Server version: 11.4.12-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_42343630_dts`
--

-- --------------------------------------------------------

--
-- Table structure for table `arta_levels`
--

DROP TABLE IF EXISTS `arta_levels`;
CREATE TABLE `arta_levels` (
  `id` int(11) NOT NULL,
  `level_name` varchar(50) NOT NULL,
  `processing_days` int(11) NOT NULL DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `arta_levels`
--

INSERT INTO `arta_levels` (`id`, `level_name`, `processing_days`) VALUES
(1, 'Simple', 3),
(2, 'Complex', 7),
(3, 'Highly Technical', 20);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `voucher_code` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `action_taken` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `processed_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `voucher_code`, `department`, `action_taken`, `remarks`, `processed_by_user_id`, `created_at`) VALUES
(1, 'NAAP-2026-7956', 'Human Resource Management Services Division', 'Scan-to-Receive', 'Physical document received at station', 3, '2026-07-10 04:07:24'),
(2, 'NAAP-2026-7956', 'Human Resource Management Services Division', 'Accepted', '', 3, '2026-07-10 04:07:42'),
(3, 'NAAP-2026-8740', 'Cultural Affairs Unit', 'Scan-to-Receive', 'Physical document received at station', 4, '2026-07-10 11:07:09'),
(4, 'NAAP-2026-8740', 'Cultural Affairs Unit', 'Accepted', 'SDASDWASDWA', 4, '2026-07-10 11:08:17'),
(5, 'NAAP-2026-8740', 'VPAA', 'Scan-to-Receive', 'Physical document received at station', 6, '2026-07-10 11:36:59'),
(6, 'NAAP-2026-8740', 'VPAA', 'Accepted', '', 6, '2026-07-10 11:37:11'),
(7, 'NAAP-2026-8740', 'Budget Office', 'Scan-to-Receive', 'Physical document received at station', 8, '2026-07-10 11:43:44'),
(8, 'NAAP-2026-8740', 'Budget Office', 'Accepted', '', 8, '2026-07-10 11:43:53'),
(9, 'NAAP-2026-8740', 'Accounting Office', 'Scan-to-Receive', 'Physical document received at station', 2, '2026-07-10 11:49:44'),
(10, 'NAAP-2026-8740', 'Accounting Office', 'Accepted', '', 2, '2026-07-10 11:49:56'),
(11, 'NAAP-2026-8740', 'Cash Services – Collecting Office', 'Scan-to-Receive', 'Physical document received at station', 9, '2026-07-10 12:18:26'),
(12, 'NAAP-2026-8740', 'Cash Services – Collecting Office', 'Accepted', '', 9, '2026-07-10 13:14:51'),
(13, 'NAAP-2026-6376', 'Cultural Affairs Unit', 'Scan-to-Receive', 'Physical document received at station', 4, '2026-07-22 11:05:25'),
(14, 'NAAP-2026-6376', 'Cultural Affairs Unit', 'Accepted', '', 4, '2026-07-22 11:06:14'),
(15, 'NAAP-2026-6376', 'Management Information System Office', 'Scan-to-Receive', 'Physical document received at station', 1, '2026-07-22 11:11:11'),
(16, 'NAAP-2026-6376', 'Management Information System Office', 'Accepted', '', 1, '2026-07-22 11:11:52');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs_archive`
--

DROP TABLE IF EXISTS `audit_logs_archive`;
CREATE TABLE `audit_logs_archive` (
  `log_id` int(11) NOT NULL,
  `voucher_code` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `action_taken` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `processed_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_signatory` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `is_signatory`, `is_active`) VALUES
(1, 'Accounting Office', 1, 1),
(2, 'Admission Office', 1, 1),
(3, 'Auxiliary Services and Resource Generation Office', 1, 1),
(4, 'Bids and Awards Committee', 1, 1),
(5, 'Budget Office', 1, 1),
(6, 'Cash Services – Collecting Office', 1, 1),
(7, 'College and Board Secretary\'s Office', 1, 1),
(8, 'College Library', 1, 1),
(9, 'Community Extension Services', 1, 1),
(10, 'Cultural Affairs Unit', 1, 1),
(11, 'General Services Department', 1, 1),
(12, 'Guidance Services Unit', 1, 1),
(13, 'Human Resource Management Services Division', 1, 1),
(14, 'Management Information System Office', 1, 1),
(15, 'Medical Unit', 1, 1),
(16, 'National Service Training Program (NSTP)', 1, 1),
(17, 'Office of Student Affairs', 1, 1),
(18, 'PE and Sports Development Unit', 1, 1),
(19, 'Procurement Unit', 1, 1),
(20, 'Quality Assurance Center', 1, 1),
(21, 'Records Office', 1, 1),
(22, 'Research and Development Center', 1, 1),
(23, 'Registrar\'s Office', 1, 1),
(24, 'Supply and Property Office', 1, 1),
(25, 'Institute of Computer Studies (ICS) Office', 1, 1),
(26, 'Institute of Engineering and Technology (IET) Office', 1, 1),
(27, 'Institute of Liberal Arts and Sciences (ILAS) Office', 1, 1),
(28, 'Institute of Graduate Studies (IGS) Office', 1, 1),
(29, 'VPAA', 1, 1),
(30, 'VPAF – Vice President for Administration and Finance', 1, 1),
(32, 'test1', 1, 1),
(33, 'VP for Admin & Finance', 1, 1),
(34, 'Office of the President', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

DROP TABLE IF EXISTS `document_types`;
CREATE TABLE `document_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'General',
  `arta_level` varchar(50) NOT NULL DEFAULT 'Simple',
  `workflow_type` varchar(50) NOT NULL DEFAULT 'Approval',
  `final_status_text` varchar(100) DEFAULT NULL,
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `default_workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system_default` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `name`, `category`, `arta_level`, `workflow_type`, `final_status_text`, `requirements`, `default_workflow`, `created_by_user_id`, `description`, `is_active`, `is_system_default`) VALUES
(31, 'Reimbursement Claim', 'General', 'Simple', 'Approval', NULL, NULL, '[\"Accounting Office Office\", \"Cash Services - Collecting Office\", \"VP for Admin & Finance\"]', NULL, NULL, 1, 0),
(32, 'Leave Application', 'General', 'Simple', 'Approval', NULL, NULL, '[\"Human Resource Management Services Division\", \"VP for Admin & Finance\"]', NULL, NULL, 1, 0),
(33, 'Event Proposal', 'General', 'Complex', 'Approval', NULL, NULL, '[\"Office of Student Affairs\", \"VPAA\", \"Office of the President\"]', NULL, NULL, 1, 0),
(34, 'Budget Realignment Request', 'General', 'Highly Technical', 'Approval', NULL, NULL, '[\"Budget Office\", \"Accounting Office Office\", \"VP for Admin & Finance\", \"Office of the President\"]', NULL, NULL, 1, 0),
(35, 'Student Enrollment Form', 'General', 'Simple', 'Transfer', NULL, NULL, '[\"Registrar\'s Office\"]', NULL, NULL, 1, 0),
(36, 'Research Grant Application', 'General', 'Highly Technical', 'Approval', NULL, NULL, '[\"Research and Development Center\", \"VPAA\", \"Office of the President\"]', NULL, NULL, 1, 0),
(37, 'IT Equipment Request', 'General', 'Simple', 'Approval', NULL, NULL, '[\"Management Information System Office\", \"Procurement Unit\", \"VP for Admin & Finance\"]', NULL, NULL, 1, 0),
(38, 'General Document Filing', 'General', 'Simple', 'Transfer', NULL, NULL, '[\"General Services Department\"]', NULL, NULL, 1, 0),
(47, 'FinalExamAppoval', 'General', 'Complex', 'Approval', NULL, NULL, '[\"VP for Admin & Finance\"]', 11, NULL, 1, 0),
(49, 'test', 'General', 'Simple', 'Approval', NULL, '[\"req1\",\"req2\",\"req3\"]', '[\"Cultural Affairs Unit (Head)\",\"Management Information System Office (Head)\"]', 5, NULL, 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `description` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_titles`
--

DROP TABLE IF EXISTS `job_titles`;
CREATE TABLE `job_titles` (
  `id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `title_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_titles`
--

INSERT INTO `job_titles` (`id`, `department_name`, `title_name`) VALUES
(1, 'Accounting Office', 'Head'),
(2, 'Accounting Office', 'Staff'),
(3, 'Accounting Office', 'Accountant'),
(4, 'Admission Office', 'Head'),
(5, 'Admission Office', 'Staff'),
(6, 'Admission Office', 'Admissions Officer'),
(7, 'Auxiliary Services and Resource Generation Office', 'Head'),
(8, 'Auxiliary Services and Resource Generation Office', 'Staff'),
(9, 'Auxiliary Services and Resource Generation Office', 'Officer'),
(10, 'Bids and Awards Committee', 'Head'),
(11, 'Bids and Awards Committee', 'Staff'),
(12, 'Bids and Awards Committee', 'Committee Member'),
(13, 'Budget Office', 'Head'),
(14, 'Budget Office', 'Staff'),
(15, 'Budget Office', 'Budget Officer'),
(16, 'Cash Services – Collecting Office', 'Head'),
(17, 'Cash Services – Collecting Office', 'Staff'),
(18, 'Cash Services – Collecting Office', 'Cashier'),
(19, 'College and Board Secretary\'s Office', 'Head'),
(20, 'College and Board Secretary\'s Office', 'Staff'),
(21, 'College and Board Secretary\'s Office', 'Board Secretary'),
(22, 'College Library', 'Head'),
(23, 'College Library', 'Staff'),
(24, 'College Library', 'Librarian'),
(25, 'Community Extension Services', 'Head'),
(26, 'Community Extension Services', 'Staff'),
(27, 'Community Extension Services', 'Extension Coordinator'),
(28, 'Cultural Affairs Unit', 'Head'),
(29, 'Cultural Affairs Unit', 'Staff'),
(30, 'Cultural Affairs Unit', 'Coordinator'),
(31, 'General Services Department', 'Head'),
(32, 'General Services Department', 'Staff'),
(33, 'General Services Department', 'Services Officer'),
(34, 'Guidance Services Unit', 'Head'),
(35, 'Guidance Services Unit', 'Staff'),
(36, 'Guidance Services Unit', 'Guidance Counselor'),
(37, 'Human Resource Management Services Division', 'Head'),
(38, 'Human Resource Management Services Division', 'Staff'),
(39, 'Human Resource Management Services Division', 'HR Officer'),
(40, 'Management Information System Office', 'Head'),
(41, 'Management Information System Office', 'Staff'),
(42, 'Management Information System Office', 'System Administrator'),
(43, 'Medical Unit', 'Head'),
(44, 'Medical Unit', 'Staff'),
(45, 'Medical Unit', 'Nurse'),
(46, 'National Service Training Program (NSTP)', 'Head'),
(47, 'National Service Training Program (NSTP)', 'Staff'),
(48, 'National Service Training Program (NSTP)', 'NSTP Coordinator'),
(49, 'Office of Student Affairs', 'Head'),
(50, 'Office of Student Affairs', 'Staff'),
(51, 'Office of Student Affairs', 'Student Affairs Coordinator'),
(52, 'PE and Sports Development Unit', 'Head'),
(53, 'PE and Sports Development Unit', 'Staff'),
(54, 'PE and Sports Development Unit', 'Coach'),
(55, 'Procurement Unit', 'Head'),
(56, 'Procurement Unit', 'Staff'),
(57, 'Procurement Unit', 'Procurement Officer'),
(58, 'Quality Assurance Center', 'Head'),
(59, 'Quality Assurance Center', 'Staff'),
(60, 'Quality Assurance Center', 'QA Officer'),
(61, 'Records Office', 'Head'),
(62, 'Records Office', 'Staff'),
(63, 'Records Office', 'Records Officer'),
(64, 'Research and Development Center', 'Head'),
(65, 'Research and Development Center', 'Staff'),
(66, 'Research and Development Center', 'Researcher'),
(67, 'Registrar\'s Office', 'Head'),
(68, 'Registrar\'s Office', 'Staff'),
(69, 'Registrar\'s Office', 'Registrar Staff'),
(70, 'Supply and Property Office', 'Head'),
(71, 'Supply and Property Office', 'Staff'),
(72, 'Supply and Property Office', 'Property Custodian'),
(73, 'Institute of Computer Studies (ICS) Office', 'Dean'),
(74, 'Institute of Computer Studies (ICS) Office', 'Processor'),
(75, 'Institute of Computer Studies (ICS) Office', 'Head'),
(76, 'Institute of Computer Studies (ICS) Office', 'Staff'),
(77, 'Institute of Engineering and Technology (IET) Office', 'Dean'),
(78, 'Institute of Engineering and Technology (IET) Office', 'Processor'),
(79, 'Institute of Engineering and Technology (IET) Office', 'Head'),
(80, 'Institute of Engineering and Technology (IET) Office', 'Staff'),
(81, 'Institute of Liberal Arts and Sciences (ILAS) Office', 'Dean'),
(82, 'Institute of Liberal Arts and Sciences (ILAS) Office', 'Processor'),
(83, 'Institute of Liberal Arts and Sciences (ILAS) Office', 'Head'),
(84, 'Institute of Liberal Arts and Sciences (ILAS) Office', 'Staff'),
(85, 'Institute of Graduate Studies (IGS) Office', 'Dean'),
(86, 'Institute of Graduate Studies (IGS) Office', 'Processor'),
(87, 'Institute of Graduate Studies (IGS) Office', 'Head'),
(88, 'Institute of Graduate Studies (IGS) Office', 'Staff'),
(89, 'VPAA', 'Vice President'),
(90, 'VPAA', 'Staff'),
(91, 'VPAF – Vice President for Administration and Finance', 'Vice President'),
(92, 'VPAF – Vice President for Administration and Finance', 'Staff'),
(93, 'test1', 'test2');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int(11) NOT NULL,
  `migration_name` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration_name`, `applied_at`) VALUES
(1, 'initial_system_setup_20240101', '2026-04-03 11:51:32'),
(2, 'remove_handler_status_column_20240216', '2026-04-03 11:51:32'),
(3, 'add_arta_levels_table_20240217', '2026-04-03 11:51:32'),
(4, 'add_financial_doc_type_20240218', '2026-04-03 12:22:11'),
(5, 'add_arta_to_voucher_types_20240219', '2026-04-03 12:29:37'),
(6, 'add_session_token_to_users_20240220', '2026-04-04 01:41:17'),
(7, 'add_notifications_table_20240405', '2026-04-04 02:47:06'),
(8, 'add_link_to_notifications_20240406', '2026-04-04 02:47:06'),
(9, 'add_requirements_to_doc_types_20240407', '2026-04-04 07:26:16'),
(10, 'add_deadline_warning_flag_20240408', '2026-04-04 07:26:16'),
(11, 'add_min_max_to_voucher_types_20240409', '2026-04-04 11:41:56'),
(12, 'add_general_min_max_settings_20240410', '2026-04-04 15:10:56'),
(13, 'add_google_auth_secret_to_users_20240411', '2026-04-04 15:10:56'),
(14, 'add_google_auth_secret_to_users_20240521', '2026-04-04 15:55:27'),
(15, 'add_2fa_secret_to_users_20240521', '2026-04-04 16:45:19'),
(16, 'add_category_to_document_types_20240522', '2026-04-05 10:23:14'),
(17, 'add_category_to_voucher_types_20240523', '2026-04-05 10:23:14'),
(18, 'add_pending_doc_types_and_nullable_voucher_doc_type_20240522', '2026-04-07 12:44:22'),
(19, 'standardize_vpaf_to_vp_for_admin_finance_20240522', '2026-04-14 15:22:07'),
(20, 'standardize_disbursing_department_20240523', '2026-04-14 15:55:59'),
(21, 'standardize_disbursing_in_templates_and_vouchers_20240524', '2026-04-14 16:03:07'),
(22, 'add_data_retention_and_archiving_20240525', '2026-07-10 10:50:44'),
(23, 'standardize_vpaa_department_name_20240710', '2026-07-10 11:28:10'),
(24, 'standardize_department_names_in_workflows_20240711', '2026-07-10 11:32:54'),
(25, 'standardize_disbursing_to_cash_services_20240712', '2026-07-10 11:48:40'),
(26, 'standardize_dash_characters_to_hyphens_20240713', '2026-07-10 12:05:01'),
(27, 'force_standardize_vpaa_role_name_20240712', '2026-07-10 12:05:01'),
(28, 'consolidated_workflow_name_standardization_20240714', '2026-07-22 09:19:12'),
(29, 'add_password_reset_feature_20240715', '2026-07-22 09:54:14'),
(30, 'add_force_password_change_feature_20240716', '2026-07-22 10:22:17');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `voucher_code` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `voucher_code`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 2, NULL, 'Your document NAAP-2026-7956 has been successfully submitted and is now pending review.', 'track.php?track_id=NAAP-2026-7956', 1, '2026-07-10 04:02:08'),
(2, 3, NULL, 'Heads up! A new document (NAAP-2026-7956) from Accounting Head is en route to your office.', 'queue.php', 1, '2026-07-10 04:02:08'),
(3, 2, NULL, 'Your document NAAP-2026-7956 has been received by the Human Resource Management Services Division office and is now in their queue.', 'track.php?track_id=NAAP-2026-7956', 1, '2026-07-10 04:07:24'),
(4, 2, NULL, 'Good news! Your document NAAP-2026-7956 has been fully approved and is now Received.', 'track.php?track_id=NAAP-2026-7956', 1, '2026-07-10 04:07:42'),
(5, 5, NULL, 'Your document NAAP-2026-8740 has been successfully submitted and is now pending review.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 11:06:12'),
(6, 4, NULL, 'Heads up! A new document (NAAP-2026-8740) from cau is en route to your office.', 'queue.php', 1, '2026-07-10 11:06:12'),
(7, 5, NULL, 'Your document NAAP-2026-8740 has been received by the Cultural Affairs Unit office and is now in their queue.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 11:07:09'),
(8, 5, NULL, 'Your document NAAP-2026-8740 has been received by the VPAA office and is now in their queue.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 11:36:59'),
(9, 5, NULL, 'Your document NAAP-2026-8740 has been received by the Budget Office office and is now in their queue.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 11:43:44'),
(10, 2, NULL, 'Heads up! Document NAAP-2026-8740 has been processed by Budget Office and is now en route to your office.', 'queue.php', 1, '2026-07-10 11:43:53'),
(11, 5, NULL, 'Your document NAAP-2026-8740 has been received by the Accounting Office office and is now in their queue.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 11:49:44'),
(12, 5, NULL, 'Your document NAAP-2026-8740 has been received by the Cash Services – Collecting Office office and is now in their queue.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 12:18:26'),
(13, 5, NULL, 'Good news! Your document NAAP-2026-8740 has been fully approved and is now Ready for Release.', 'track.php?track_id=NAAP-2026-8740', 1, '2026-07-10 13:14:51'),
(14, 1, NULL, 'Password reset requested for user: Andrio Guda (Andrio Guda). Please go to System Settings to resolve.', 'settings.php', 1, '2026-07-22 10:21:32'),
(15, 1, NULL, 'Password reset requested for user: Andrio Guda (Andrio Guda). Please go to System Settings to resolve.', 'settings.php', 1, '2026-07-22 10:24:10'),
(16, 5, NULL, 'Your document NAAP-2026-6376 has been successfully submitted and is now pending review.', 'track.php?track_id=NAAP-2026-6376', 1, '2026-07-22 10:47:57'),
(17, 4, NULL, 'Heads up! A new document (NAAP-2026-6376) from cau is en route to your office.', 'queue.php', 1, '2026-07-22 10:47:57'),
(18, 5, NULL, 'Your document NAAP-2026-6376 has been received by the Cultural Affairs Unit office and is now in their queue.', 'track.php?track_id=NAAP-2026-6376', 1, '2026-07-22 11:05:25'),
(19, 1, NULL, 'Heads up! Document NAAP-2026-6376 has been processed by Cultural Affairs Unit and is now en route to your office.', 'queue.php', 1, '2026-07-22 11:06:14'),
(20, 5, NULL, 'Your document NAAP-2026-6376 has been received by the Management Information System Office office and is now in their queue.', 'track.php?track_id=NAAP-2026-6376', 1, '2026-07-22 11:11:11'),
(21, 5, NULL, 'Good news! Your document NAAP-2026-6376 has been fully approved and is now Ready for Release.', 'track.php?track_id=NAAP-2026-6376', 1, '2026-07-22 11:11:52');

-- --------------------------------------------------------

--
-- Table structure for table `pending_document_types`
--

DROP TABLE IF EXISTS `pending_document_types`;
CREATE TABLE `pending_document_types` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `arta_level` varchar(50) NOT NULL DEFAULT 'Simple',
  `workflow_type` varchar(50) NOT NULL DEFAULT 'Approval',
  `final_status_text` varchar(100) DEFAULT NULL,
  `default_workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(50) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pending_document_types`
--

INSERT INTO `pending_document_types` (`id`, `name`, `arta_level`, `workflow_type`, `final_status_text`, `default_workflow`, `created_by_user_id`, `requested_at`, `status`) VALUES
(3, 'hello', 'Simple', 'Transfer', '', '0', NULL, '2026-04-07 13:19:18', 'approved');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('archive_retention_days', '365'),
('dss_history_enabled', '1'),
('dss_rejection_count', '3'),
('dss_submission_count', '10'),
('general_max_amount', '100000'),
('general_min_amount', '1000'),
('notification_retention_days', '90'),
('setting_audit', '1'),
('setting_email', '0'),
('setting_qr', '1'),
('setting_rule', '1');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `is_head` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(255) DEFAULT NULL,
  `session_token` varchar(255) DEFAULT NULL,
  `google_auth_secret` varchar(255) DEFAULT NULL,
  `password_reset_request` tinyint(1) NOT NULL DEFAULT 0,
  `password_reset_timestamp` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `full_name`, `job_title`, `is_head`, `email`, `session_token`, `google_auth_secret`, `password_reset_request`, `password_reset_timestamp`, `must_change_password`) VALUES
(1, 'admin', 'admin', 'Management Information System Office', 'System Administrator', 'System Administrator', 1, 'admin@yourschool.edu', '60bf1db4683bb604ea9d7c867b4f5232', NULL, 0, NULL, 0),
(2, 'acct_head', 'accthead', 'Accounting Office', 'Accounting Head', 'Head', 1, 'acct_head@yourschool.edu', '0af20b828b9dd73f892cf68e3d8589dd', NULL, 0, NULL, 0),
(3, 'hr_head', 'hrhead', 'Human Resource Management Services Division', 'HR Head', 'Head', 1, 'hr_head@yourschool.edu', 'e1d38edef92b377b2ad24ecb9aa1bf9d', NULL, 0, NULL, 0),
(4, 'Andrio Guda', '$2y$10$fT3bzgSbbfiaRPwnB9IbPugCBQyS0f6SArYugBwOK6vUMnVvpPvLq', 'Cultural Affairs Unit', 'Andrio Guda', 'Head', 1, 'naap.andrioguda@gmail.com', NULL, '4SSCRSLWZSQU5MMB', 0, NULL, 0),
(5, 'caustaff', '$2y$10$HMZBMRHzwvpMJX6o8j9zDuU7tlY0VCd6HnU5ClwLZDZrVUAR/roae', 'Cultural Affairs Unit', 'cau', 'Staff', 0, 'caustaff@gmail.com', '621fb0388c03f1825b315f912a3296fa', NULL, 0, NULL, 0),
(6, 'vpaahead', '$2y$10$gyyWBANAq5pIm31n3ArvUuGrD5D1NenT2IOA78zRDcfjzpUihFeda', 'VPAA', 'vpaahead', 'Vice President', 1, 'vpaahead@gmail.com', NULL, NULL, 0, NULL, 0),
(7, 'vpaastaff', '$2y$10$lQOGZQ6HR1tcVqzqqhpXZOm8ByUmt3SskOrFeZj/b2SAUxOpp9IrO', 'VPAA', 'vpaastaff', 'Staff', 0, 'vpaastaff@gmail.com', NULL, NULL, 0, NULL, 0),
(8, 'budgethead', '$2y$10$O1wOUrPsJpy0MXv3K87BQ.sjBCn8JB2yYOo/8NyNX.4tmTTUSQrJm', 'Budget Office', 'budgethead', 'Head', 1, 'budgethead@gmail.com', NULL, NULL, 0, NULL, 0),
(9, 'disbursinghead', '$2y$10$UVLQVt2pSvkxxAEcqyf2FOJ1JYTIy8Dj3YwgGouqsrqSksxKkG2Ym', 'Cash Services – Collecting Office', 'disbursinghead', 'Head', 1, 'disbursinghead@gmail.com', NULL, NULL, 0, NULL, 0),
(10, 'disbursingstaff', '$2y$10$G42s5bPxHrY9USzxTuGnvuwdWN1oysQ6JA.ZUN63RTmANEntDLGhu', 'Cash Services – Collecting Office', 'disbursingstaff', 'Cashier', 0, 'disbursingstaff@gmail.com', NULL, NULL, 0, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `validation_rules`
--

DROP TABLE IF EXISTS `validation_rules`;
CREATE TABLE `validation_rules` (
  `rule_id` int(11) NOT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `required_documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

DROP TABLE IF EXISTS `vouchers`;
CREATE TABLE `vouchers` (
  `voucher_code` varchar(50) NOT NULL,
  `requestor_id` int(11) DEFAULT NULL,
  `document_title` varchar(255) DEFAULT NULL,
  `doc_type_id` int(11) DEFAULT NULL,
  `voucher_type_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `payee_name` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `required_docs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `budget_code` varchar(100) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `workflow_type` varchar(50) DEFAULT 'Approval',
  `current_stage_index` int(11) DEFAULT NULL,
  `custom_workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `arta_deadline` date DEFAULT NULL,
  `deadline_warning_sent` tinyint(1) NOT NULL DEFAULT 0,
  `date_submitted` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vouchers`
--

INSERT INTO `vouchers` (`voucher_code`, `requestor_id`, `document_title`, `doc_type_id`, `voucher_type_id`, `reference_number`, `tags`, `document_type`, `payee_name`, `category`, `required_docs`, `amount`, `budget_code`, `purpose`, `status`, `workflow_type`, `current_stage_index`, `custom_workflow`, `arta_deadline`, `deadline_warning_sent`, `date_submitted`) VALUES
('NAAP-2026-6376', 5, 'test1', 49, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 'gdfgdfgdfg', 'Ready for Release', 'Approval', 2, '[\"Cultural Affairs Unit (Head)\",\"Management Information System Office (Head)\"]', '2026-07-27', 0, '2026-07-22 10:47:57'),
('NAAP-2026-7956', 2, 'Trial2', 48, NULL, 'Qwer', '', NULL, NULL, NULL, NULL, NULL, NULL, 'Qwert', 'Received', 'Transfer', 1, '[\"Human Resource Management Services Division (Head)\"]', '2026-07-15', 0, '2026-07-10 04:02:08'),
('NAAP-2026-8740', 5, 'Financial Voucher', NULL, 11, '', 'trial', NULL, NULL, NULL, NULL, '150.00', '', 'trial', 'Ready for Release', 'Approval', 5, '[\"Department Head\",\"VPAA\",\"Budget Office\",\"Accounting Office Office\",\"Cash Services - Collecting Office\"]', '2026-07-21', 0, '2026-07-10 11:06:12');

-- --------------------------------------------------------

--
-- Table structure for table `vouchers_archive`
--

DROP TABLE IF EXISTS `vouchers_archive`;
CREATE TABLE `vouchers_archive` (
  `voucher_code` varchar(50) NOT NULL,
  `requestor_id` int(11) DEFAULT NULL,
  `document_title` varchar(255) DEFAULT NULL,
  `doc_type_id` int(11) DEFAULT NULL,
  `voucher_type_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `payee_name` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `required_docs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `budget_code` varchar(100) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `workflow_type` varchar(50) DEFAULT 'Approval',
  `current_stage_index` int(11) DEFAULT NULL,
  `custom_workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `arta_deadline` date DEFAULT NULL,
  `deadline_warning_sent` tinyint(1) NOT NULL DEFAULT 0,
  `date_submitted` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voucher_documents`
--

DROP TABLE IF EXISTS `voucher_documents`;
CREATE TABLE `voucher_documents` (
  `doc_id` int(11) NOT NULL,
  `voucher_code` varchar(50) DEFAULT NULL,
  `document_name` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voucher_types`
--

DROP TABLE IF EXISTS `voucher_types`;
CREATE TABLE `voucher_types` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'General',
  `arta_level` varchar(50) NOT NULL DEFAULT 'Complex',
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `default_workflow` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `voucher_types`
--

INSERT INTO `voucher_types` (`id`, `name`, `category`, `arta_level`, `requirements`, `default_workflow`, `min_amount`, `max_amount`, `is_active`) VALUES
(8, 'Travel / Training / Seminar', 'General', 'Complex', '[\"Approved Travel Order\",\"Itinerary of Travel\",\"Estimate of Expenses\",\"Liquidation Report (post-travel)\",\"Official Receipts\",\"Certificate of Appearance/Attendance\"]', '[\"Department Head\", \"VPAA\", \"Budget Office\", \"Accounting Office Office\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(9, 'Payroll (Salaries/Wages)', 'General', 'Complex', '[\"Approved Payroll Register\",\"DTRs/Time Sheets\",\"Appointment/Contract (for new hires)\",\"BIR Form 2307/2316 (if applicable)\"]', '[\"Human Resource Management Services Division\", \"Budget Office\", \"Accounting Office Office\", \"VP for Admin & Finance\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(10, 'Petty Cash Fund (PCF)', 'General', 'Complex', '[\"PCF Request Form\",\"Approved Purpose/Activity\",\"Certification of no unliquidated cash advance\"]', '[\"Department Head\", \"Budget Office\", \"Accounting Office Office\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(11, 'Field / Activity Operating Expense', 'General', 'Complex', '[\"Approved Activity Proposal\",\"Program of Works/Budget Breakdown\",\"Attendance Sheet (if applicable)\",\"Official Receipts/Invoices\",\"Liquidation Report\"]', '[\"Department Head\", \"VPAA\", \"Budget Office\", \"Accounting Office Office\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(12, 'Procurement / Purchase of Goods', 'General', 'Complex', '[\"Purchase Request (PR)\",\"Request for Quotation (RFQ)\",\"Abstract of Quotations\",\"Purchase Order (PO) / Contract\",\"Delivery Receipt / IAR\",\"Supplier\'s Invoice\",\"Warranty Certificate (if applicable)\"]', '[\"Department Head\", \"Procurement Office\", \"Budget Office\", \"Accounting Office Office\", \"VP/Admin\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(13, 'Repair / Maintenance / Services', 'General', 'Complex', '[\"Job Order/Work Request\",\"Pre-Inspection Report\",\"Post-Inspection Report\",\"Official Receipts/Invoices\",\"Certificate of Completion\"]', '[\"Department Head\", \"Procurement Office\", \"Budget Office\", \"Accounting Office Office\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(14, 'Utilities (Water, Power, Internet)', 'General', 'Complex', '[\"Statement of Account / Bill\",\"Previous Payment Receipt (for verification)\"]', '[\"Accounting Office Office\", \"Budget Office\", \"VP/Admin\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(15, 'Honorarium / Allowances', 'General', 'Complex', '[\"Approved Request for Honorarium/Allowance\",\"List of Recipients\",\"Attendance Sheet (if applicable)\",\"Service Contract (if applicable)\"]', '[\"Department Head\", \"Human Resource Management Services Division\", \"Budget Office\", \"Accounting Office Office\", \"Cash Services - Collecting Office\"]', NULL, NULL, 1),
(26, 'test1', 'General', 'Simple', '[\"test1\"]', '[\"College Library\",\"Accounting Office Office (Head)\",\"Budget Office (Head)\"]', NULL, NULL, 1),
(27, 'test2', 'General', 'Simple', '[\"red\",\"blue\",\"green\",\"yellow\"]', '[\"Accounting Office Office (Head)\",\"Human Resource Management Services Division (Head)\"]', NULL, NULL, 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `arta_levels`
--
ALTER TABLE `arta_levels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `level_name` (`level_name`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `voucher_code` (`voucher_code`),
  ADD KEY `processed_by_user_id` (`processed_by_user_id`);

--
-- Indexes for table `audit_logs_archive`
--
ALTER TABLE `audit_logs_archive`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `voucher_code` (`voucher_code`),
  ADD KEY `processed_by_user_id` (`processed_by_user_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `name_2` (`name`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_date` (`holiday_date`);

--
-- Indexes for table `job_titles`
--
ALTER TABLE `job_titles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `migration_name` (`migration_name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pending_document_types`
--
ALTER TABLE `pending_document_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `validation_rules`
--
ALTER TABLE `validation_rules`
  ADD PRIMARY KEY (`rule_id`);

--
-- Indexes for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`voucher_code`),
  ADD KEY `requestor_id` (`requestor_id`);

--
-- Indexes for table `vouchers_archive`
--
ALTER TABLE `vouchers_archive`
  ADD PRIMARY KEY (`voucher_code`),
  ADD KEY `requestor_id` (`requestor_id`);

--
-- Indexes for table `voucher_documents`
--
ALTER TABLE `voucher_documents`
  ADD PRIMARY KEY (`doc_id`),
  ADD KEY `voucher_code` (`voucher_code`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `voucher_types`
--
ALTER TABLE `voucher_types`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `arta_levels`
--
ALTER TABLE `arta_levels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `audit_logs_archive`
--
ALTER TABLE `audit_logs_archive`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_titles`
--
ALTER TABLE `job_titles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `pending_document_types`
--
ALTER TABLE `pending_document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `validation_rules`
--
ALTER TABLE `validation_rules`
  MODIFY `rule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `voucher_documents`
--
ALTER TABLE `voucher_documents`
  MODIFY `doc_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `voucher_types`
--
ALTER TABLE `voucher_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`voucher_code`) REFERENCES `vouchers` (`voucher_code`) ON DELETE CASCADE,
  ADD CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`processed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD CONSTRAINT `vouchers_ibfk_1` FOREIGN KEY (`requestor_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `voucher_documents`
--
ALTER TABLE `voucher_documents`
  ADD CONSTRAINT `voucher_documents_ibfk_1` FOREIGN KEY (`voucher_code`) REFERENCES `vouchers` (`voucher_code`) ON DELETE CASCADE,
  ADD CONSTRAINT `voucher_documents_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
