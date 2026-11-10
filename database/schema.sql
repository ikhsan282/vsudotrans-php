-- =====================================================================
-- VSudoTrans — Sistem Manajemen Transportasi & Rental Kendaraan
-- PHP Native + MySQLi + Bootstrap 5
-- Canonical schema (fresh install) — SATU file, tanpa folder migrations
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+07:00";

CREATE DATABASE IF NOT EXISTS `vsudotrans` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `vsudotrans`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_trails`, `login_attempts`, `user_permissions`, `user_companies`, `employees`, `users`, `roles`, `permissions`, `branches`, `companies`, `groups`, `role_permissions`, `banks`, `countries`, `provinces`, `cities`, `districts`, `villages`, `unit_measures`, `brand_vehicles`, `category_vehicles`, `model_units`, `type_engines`, `vehicles`, `rutes`, `schedules`, `passengers`, `trip_schedules`, `travel_ticket_bookings`, `rental_car_bookings`, `rental_car_regulation_employees`, `booking_passengers`, `booking_status_history`, `trip_assignments`, `premi_configs`, `booking_cost_lines`, `document_sequences`, `payment_methods`, `payment_channels`, `payment_gateway_orders`, `bot_request_logs`, `invoices`, `invoice_revisions`, `job_grades`, `job_grade_amounts`, `job_titles`, `job_positions`, `organization_structures`, `employee_recognitions`, `employee_attendances`, `payroll_periods`, `payroll_items`, `cash_accounts`, `cash_transactions`, `wa_settings`, `wa_outbox`;
SET FOREIGN_KEY_CHECKS = 1;
SET NAMES utf8mb4;

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1. GROUPS (holding / grup perusahaan)
-- ---------------------------------------------------------------------
CREATE TABLE `groups` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_groups_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. COMPANIES (tenant)
-- ---------------------------------------------------------------------
CREATE TABLE `companies` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `group_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `country_code` VARCHAR(10) NOT NULL DEFAULT '+62',
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `website` VARCHAR(300) DEFAULT NULL,
  `email` VARCHAR(200) DEFAULT NULL,
  `address` VARCHAR(1000) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `logo` VARCHAR(255) DEFAULT NULL,
  `stamp` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companies_code` (`code`),
  KEY `ix_companies_group` (`group_id`),
  CONSTRAINT `fk_companies_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. BRANCHES (cabang per company)
-- ---------------------------------------------------------------------
CREATE TABLE `branches` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `country_code` VARCHAR(10) NOT NULL DEFAULT '+62',
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_company_code` (`company_id`, `code`),
  CONSTRAINT `fk_branches_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ROLES (global; company-level roles menyusul di modul lanjutan)
-- ---------------------------------------------------------------------
CREATE TABLE `roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `level` INT(11) NOT NULL DEFAULT 0,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `description`, `level`) VALUES
(1, 'Super Admin', 'Akses penuh lintas semua perusahaan', 1),
(2, 'Admin Perusahaan', 'Mengelola data dalam perusahaannya', 2),
(3, 'HRD', 'Manajemen karyawan & struktur organisasi', 3),
(4, 'Staff', 'Akses terbatas sesuai penugasan', 4),
(5, 'Viewer', 'Hanya melihat data', 5);

-- ---------------------------------------------------------------------
-- 5. PERMISSIONS
-- ---------------------------------------------------------------------
CREATE TABLE `permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `module` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('groups.view',       'Lihat grup perusahaan', 'groups'),
('groups.manage',     'Kelola grup perusahaan', 'groups'),
('companies.view',    'Lihat perusahaan', 'companies'),
('companies.manage',  'Kelola perusahaan', 'companies'),
('branches.view',     'Lihat cabang', 'branches'),
('branches.manage',   'Kelola cabang', 'branches'),
('employees.view',    'Lihat karyawan', 'employees'),
('employees.manage',  'Kelola karyawan', 'employees'),
('users.view',        'Lihat pengguna', 'users'),
('users.manage',      'Kelola pengguna', 'users'),
('roles.manage',      'Kelola role & hak akses', 'roles'),
('audit.view',        'Lihat audit trail', 'audit');

CREATE TABLE `role_permissions` (
  `role_id` INT(11) NOT NULL,
  `permission_id` INT(11) NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `ix_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Super Admin: semua
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;
-- Admin Perusahaan: semua kecuali kelola perusahaan/grup & role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `name` NOT IN ('companies.manage', 'groups.manage', 'roles.manage');
-- HRD: cabang + karyawan (view & manage), lihat perusahaan
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `name` IN
  ('companies.view','branches.view','branches.manage','employees.view','employees.manage');
-- Staff (4) & Viewer (5): grant dilakukan SETELAH semua permission modul baru dibuat
-- (lihat blok "PERMISSION MODUL BARU" di bawah), agar '%.view' mencakup travel/payroll/dll.

-- ---------------------------------------------------------------------
-- 6. USERS + multi-company access
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `role_id` INT(11) NOT NULL,
  `company_id` INT(11) DEFAULT NULL COMMENT 'Perusahaan aktif; NULL = lintas perusahaan',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` DATETIME DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `ix_users_company` (`company_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_companies` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_company` (`user_id`, `company_id`),
  CONSTRAINT `fk_uc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Override izin per user (tambahan di atas izin role)
CREATE TABLE `user_permissions` (
  `user_id` INT(11) NOT NULL,
  `permission_id` INT(11) NOT NULL,
  PRIMARY KEY (`user_id`, `permission_id`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_up_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. EMPLOYEES (scoped ke company + branch)
-- ---------------------------------------------------------------------
CREATE TABLE `employees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `branch_id` INT(11) DEFAULT NULL,
  `code` VARCHAR(15) NOT NULL COMMENT 'NIK karyawan',
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(200) DEFAULT NULL,
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `join_date` DATE NOT NULL,
  `resignation_date` DATE DEFAULT NULL,
  `job_title` VARCHAR(100) DEFAULT NULL,
  `job_position` VARCHAR(100) DEFAULT NULL,
  `job_grade` VARCHAR(50) DEFAULT NULL,
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `account_number` VARCHAR(50) DEFAULT NULL,
  `account_name` VARCHAR(100) DEFAULT NULL,
  `id_number` VARCHAR(30) DEFAULT NULL COMMENT 'No. KTP',
  `family_number` VARCHAR(30) DEFAULT NULL COMMENT 'No. KK',
  `tax_number` VARCHAR(30) DEFAULT NULL COMMENT 'NPWP',
  `birth_date` DATE DEFAULT NULL,
  `birth_place` VARCHAR(100) DEFAULT NULL,
  `gender` ENUM('L','P') DEFAULT NULL,
  `religion` VARCHAR(30) DEFAULT NULL,
  `marital_status` VARCHAR(30) DEFAULT NULL,
  `address` VARCHAR(500) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT NULL,
  `ktp_doc_path` VARCHAR(255) DEFAULT NULL,
  `sim_doc_path` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('aktif','resign') NOT NULL DEFAULT 'aktif',
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_company_code` (`company_id`, `code`),
  KEY `ix_employees_branch` (`branch_id`),
  KEY `ix_employees_status` (`status`),
  CONSTRAINT `fk_employees_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. AUDIT TRAIL + LOGIN THROTTLING
-- ---------------------------------------------------------------------
CREATE TABLE `audit_trails` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `username` VARCHAR(100) DEFAULT NULL,
  `company_id` INT(11) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `description` VARCHAR(1000) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_created` (`created_date`),
  KEY `ix_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_success` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_attempt_window` (`ip_address`, `username`, `attempted_at`),
  KEY `ix_attempt_cleanup` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. SEED: grup, perusahaan contoh, dan pengguna default
-- ---------------------------------------------------------------------
INSERT INTO `groups` (`id`, `code`, `name`, `note`) VALUES
(1, 'TM', 'Trans Mandiri', 'Grup Trans Mandiri'),
(2, 'LIZ', 'LIZ', 'LIZ Wisata Alam Indah');

-- logo & stamp = path Azure Blob (container `vsudotrans`, prefix {company_code}/company/...)
INSERT INTO `companies` (`id`, `group_id`, `code`, `name`, `country_code`, `phone_number`, `email`, `address`, `city`, `logo`, `stamp`) VALUES
(1, 1, 'MTM', 'Maura Trans Mandiri', '+62', '021-0000000', 'info@mauratransmandiri.com', 'Jl. Contoh No. 1', 'Tangerang', 'mtm/company/logo_mtm.png', 'mtm/company/stamp_mtm.png'),
(2, 1, 'NTM', 'Nugi Trans Mandiri', '+62', '021-0000000', 'info@nugitransmandiri.com', 'Jl. Contoh No. 2', 'Tangerang', 'ntm/company/logo_ntm.png', NULL),
(3, 1, 'GTM', 'Gibran Trans Mandiri', '+62', '021-0000000', 'info@gibrantransmandiri.com', 'Jl. Contoh No. 3', 'Tangerang', 'gtm/company/logo_gtm.png', NULL),
(4, 1, 'TTM', 'Trisula Trans Mandiri', '+62', '021-0000000', 'info@trisulatransmandiri.com', 'Jl. Contoh No. 4', 'Tangerang', 'ttm/company/logo_ttm.png', NULL),
(5, 2, 'LIZ', 'LIZ Wisata Alam Indah', '+62', '021-0000000', 'info@lizwisata.com', 'Jl. Contoh No. 5', 'Tangerang', 'liz/company/logo_liz.png', NULL);

INSERT INTO `branches` (`id`, `company_id`, `code`, `name`, `country_code`, `phone_number`) VALUES
(1, 1, 'PST', 'Kantor Pusat', '+62', '021-0000001'),
(2, 2, 'PST', 'Kantor Pusat', '+62', '021-0000002'),
(3, 3, 'PST', 'Kantor Pusat', '+62', '021-0000003'),
(4, 4, 'PST', 'Kantor Pusat', '+62', '021-0000004'),
(5, 5, 'PST', 'Kantor Pusat', '+62', '021-0000005');

-- Semua password default: P@ssw0rd  (hash bcrypt, password_verify() true)
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `phone_number`, `role_id`, `company_id`) VALUES
(1, 'superadmin', 'superadmin@vsudotrans.test', '$2y$12$ScOvxQDzeZH0EKULI1EQWOFjSG0M4VFNNtWOy0/KtdG2qWSCUosiK', 'Super Administrator', '081200000001', 1, NULL),
(2, 'admin',      'admin@vsudotrans.test',      '$2y$12$ScOvxQDzeZH0EKULI1EQWOFjSG0M4VFNNtWOy0/KtdG2qWSCUosiK', 'Administrator Perusahaan', '081200000002', 2, 1),
(3, 'hrd',        'hrd@vsudotrans.test',        '$2y$12$ScOvxQDzeZH0EKULI1EQWOFjSG0M4VFNNtWOy0/KtdG2qWSCUosiK', 'HRD', '081200000003', 3, 1),
(4, 'staff',      'staff@vsudotrans.test',      '$2y$12$ScOvxQDzeZH0EKULI1EQWOFjSG0M4VFNNtWOy0/KtdG2qWSCUosiK', 'Staff', '081200000004', 4, 1),
(5, 'viewer',     'viewer@vsudotrans.test',     '$2y$12$ScOvxQDzeZH0EKULI1EQWOFjSG0M4VFNNtWOy0/KtdG2qWSCUosiK', 'Viewer', '081200000005', 5, 1);

INSERT INTO `user_companies` (`user_id`, `company_id`) VALUES (2, 1), (3, 1), (4, 1), (5, 1);

INSERT INTO `employees` (`company_id`, `branch_id`, `code`, `name`, `email`, `phone_number`, `join_date`, `job_title`, `job_position`, `status`) VALUES
(1, 1, 'EMP-0001', 'Budi Santoso', 'budi@vsudotrans.test', '081200000011', '2024-01-15', 'Staff Operasional', 'Driver', 'aktif');


-- =====================================================================
-- MODUL BARU: Referensi, Vehicles, Travel, Rental, Booking, Invoice, Master HR
-- =====================================================================
-- =====================================================================
-- VSudoTrans: modul baru (Travel, Rental, Vehicles, Referensi, Booking)
-- Belum digabung ke database/schema.sql. Belum diuji di MySQL.
-- =====================================================================

-- =====================================================================
-- WAVE 1: tabel fix (17). Referensi umum, Travel, Rental, Vehicles.
-- Konvensi sama dengan database/schema.sql. Belum digabung ke master.
-- =====================================================================

-- ---------- REFERENSI UMUM (tanpa company_id) ----------
CREATE TABLE `banks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_banks_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `countries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_countries_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `provinces` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `country_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provinces_code` (`code`),
  KEY `ix_provinces_country` (`country_id`),
  CONSTRAINT `fk_provinces_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cities` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `country_id` INT(11) NOT NULL,
  `province_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cities_code` (`code`),
  KEY `ix_cities_province` (`province_id`),
  CONSTRAINT `fk_cities_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cities_province` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `districts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `country_id` INT(11) NOT NULL,
  `province_id` INT(11) NOT NULL,
  `city_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_districts_code` (`code`),
  KEY `ix_districts_city` (`city_id`),
  CONSTRAINT `fk_districts_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_districts_province` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_districts_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `villages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `country_id` INT(11) NOT NULL,
  `province_id` INT(11) NOT NULL,
  `city_id` INT(11) NOT NULL,
  `district_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `postal_code` VARCHAR(10) DEFAULT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_villages_code` (`code`),
  KEY `ix_villages_district` (`district_id`),
  CONSTRAINT `fk_villages_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_villages_province` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_villages_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_villages_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unit_measures` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `format` VARCHAR(100) DEFAULT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_measures_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- VEHICLES (dengan company_id) ----------
CREATE TABLE `brand_vehicles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_vehicles_code` (`company_id`, `code`),
  KEY `ix_brand_vehicles_company` (`company_id`),
  CONSTRAINT `fk_brand_vehicles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `category_vehicles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_vehicles_code` (`company_id`, `code`),
  KEY `ix_category_vehicles_company` (`company_id`),
  CONSTRAINT `fk_category_vehicles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `model_units` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `brand_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_model_units_code` (`company_id`, `code`),
  KEY `ix_model_units_company` (`company_id`),
  KEY `ix_model_units_brand` (`brand_id`),
  CONSTRAINT `fk_model_units_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_model_units_brand` FOREIGN KEY (`brand_id`) REFERENCES `brand_vehicles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `type_engines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_engines_code` (`company_id`, `code`),
  KEY `ix_type_engines_company` (`company_id`),
  CONSTRAINT `fk_type_engines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehicles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `brand_id` INT(11) NOT NULL,
  `model_unit_id` INT(11) NOT NULL,
  `type_engine_id` INT(11) NOT NULL,
  `category_vehicle_id` INT(11) NOT NULL,
  `frame_number` VARCHAR(50) NOT NULL,
  `machine_number` VARCHAR(50) NOT NULL,
  `seat` INT(11) NOT NULL DEFAULT 0,
  `production_year` INT(11) NOT NULL,
  `vehicle_color` VARCHAR(50) NOT NULL,
  `vehicle_number` VARCHAR(15) NOT NULL,
  `bpkb_number` VARCHAR(50) DEFAULT NULL,
  `tax_due` DATE DEFAULT NULL,
  `stnk_due` DATE DEFAULT NULL,
  `kir_due` DATE DEFAULT NULL,
  `ownership` VARCHAR(30) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `stnk_doc_path` VARCHAR(255) DEFAULT NULL,
  `kir_doc_path` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_number` (`company_id`, `vehicle_number`),
  UNIQUE KEY `uq_vehicles_frame` (`company_id`, `frame_number`),
  UNIQUE KEY `uq_vehicles_machine` (`company_id`, `machine_number`),
  KEY `ix_vehicles_company` (`company_id`),
  KEY `ix_vehicles_category` (`category_vehicle_id`),
  CONSTRAINT `fk_vehicles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicles_brand` FOREIGN KEY (`brand_id`) REFERENCES `brand_vehicles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicles_model` FOREIGN KEY (`model_unit_id`) REFERENCES `model_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicles_engine` FOREIGN KEY (`type_engine_id`) REFERENCES `type_engines` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicles_category` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- TRAVEL (dengan company_id) ----------
CREATE TABLE `rutes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `pickup_point_city_id` INT(11) NOT NULL,
  `delivery_point_city_id` INT(11) NOT NULL,
  `category_vehicle_id` INT(11) NOT NULL,
  `min_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `max_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `start_capacity_seat` INT(11) NOT NULL DEFAULT 0,
  `end_capacity_seat` INT(11) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rutes_code` (`company_id`, `code`),
  KEY `ix_rutes_company` (`company_id`),
  KEY `ix_rutes_pickup` (`pickup_point_city_id`),
  KEY `ix_rutes_delivery` (`delivery_point_city_id`),
  KEY `ix_rutes_category_vehicle` (`category_vehicle_id`),
  CONSTRAINT `fk_rutes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rutes_pickup_city` FOREIGN KEY (`pickup_point_city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rutes_delivery_city` FOREIGN KEY (`delivery_point_city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rutes_category_vehicle` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `start` TIME NOT NULL,
  `end` TIME NOT NULL,
  `start_break` TIME DEFAULT NULL,
  `end_break` TIME DEFAULT NULL,
  `duration` DECIMAL(10,2) DEFAULT NULL,
  `total_duration` DECIMAL(10,2) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_schedules_company` (`company_id`),
  CONSTRAINT `fk_schedules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passengers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `country_code` VARCHAR(10) NOT NULL DEFAULT '+62',
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passengers_code` (`company_id`, `code`),
  KEY `ix_passengers_company` (`company_id`),
  CONSTRAINT `fk_passengers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- TRAVEL (lanjutan) ----------
CREATE TABLE `trip_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_number` VARCHAR(40) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `vehicle_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `total_duration` DECIMAL(10,2) DEFAULT NULL,
  `pickup_point_province_id` INT(11) DEFAULT NULL,
  `pickup_point_city_id` INT(11) DEFAULT NULL,
  `pickup_point_district_id` INT(11) NOT NULL,
  `delivery_point_province_id` INT(11) DEFAULT NULL,
  `delivery_point_city_id` INT(11) DEFAULT NULL,
  `delivery_point_district_id` INT(11) NOT NULL,
  `line_number_pickup` INT(11) NOT NULL DEFAULT 0,
  `line_number_delivery` INT(11) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_schedules_doc` (`company_id`, `document_number`),
  KEY `ix_trip_schedules_company` (`company_id`),
  KEY `ix_trip_schedules_date` (`date`),
  KEY `ix_trip_schedules_employee` (`employee_id`),
  KEY `ix_trip_schedules_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_trip_schedules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_trip_schedules_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_trip_schedules_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `travel_ticket_bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `document_number` VARCHAR(40) NOT NULL,
  `date` DATE NOT NULL,
  `time` TIME NOT NULL,
  `pickup_address` VARCHAR(2000) DEFAULT NULL,
  `pickup_point_province_id` INT(11) DEFAULT NULL,
  `pickup_point_city_id` INT(11) DEFAULT NULL,
  `pickup_point_district_id` INT(11) NOT NULL,
  `delivery_address` VARCHAR(2000) DEFAULT NULL,
  `delivery_point_province_id` INT(11) DEFAULT NULL,
  `delivery_point_city_id` INT(11) DEFAULT NULL,
  `delivery_point_district_id` INT(11) NOT NULL,
  `total_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `total_operational_cost` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `total_payment` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `category_vehicle_id` INT(11) DEFAULT NULL,
  `vehicle_id` INT(11) DEFAULT NULL,
  `passenger_id` INT(11) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_travel_bookings_doc` (`company_id`, `document_number`),
  KEY `ix_travel_bookings_company` (`company_id`),
  KEY `ix_travel_bookings_date` (`date`),
  KEY `ix_travel_bookings_branch` (`branch_id`),
  KEY `ix_travel_bookings_passenger` (`passenger_id`),
  CONSTRAINT `fk_travel_bookings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_travel_bookings_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_travel_bookings_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_travel_bookings_category_vehicle` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travel_bookings_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- RENTAL (dengan company_id) ----------
CREATE TABLE `rental_car_bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `document_number` VARCHAR(40) NOT NULL,
  `date` DATE NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `trip` DOUBLE NOT NULL DEFAULT 0,
  `time` TIME NOT NULL,
  `pickup_address` VARCHAR(2000) DEFAULT NULL,
  `pickup_point_province_id` INT(11) DEFAULT NULL,
  `pickup_point_city_id` INT(11) DEFAULT NULL,
  `pickup_point_district_id` INT(11) NOT NULL,
  `delivery_address` VARCHAR(2000) DEFAULT NULL,
  `delivery_point_province_id` INT(11) DEFAULT NULL,
  `delivery_point_city_id` INT(11) DEFAULT NULL,
  `delivery_point_district_id` INT(11) NOT NULL,
  `total_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `bbm` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `total_operational_cost` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `total_payment` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `category_vehicle_id` INT(11) DEFAULT NULL,
  `vehicle_id` INT(11) DEFAULT NULL,
  `passenger_id` INT(11) NOT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rental_bookings_doc` (`company_id`, `document_number`),
  KEY `ix_rental_bookings_company` (`company_id`),
  KEY `ix_rental_bookings_date` (`date`),
  KEY `ix_rental_bookings_branch` (`branch_id`),
  KEY `ix_rental_bookings_passenger` (`passenger_id`),
  CONSTRAINT `fk_rental_bookings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rental_bookings_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rental_bookings_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rental_bookings_category_vehicle` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rental_bookings_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rental_car_regulation_employees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `category_vehicle_id` INT(11) NOT NULL,
  `trip` INT(11) NOT NULL DEFAULT 0,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `type` VARCHAR(40) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rental_regulation_company` (`company_id`),
  KEY `ix_rental_regulation_category_vehicle` (`category_vehicle_id`),
  CONSTRAINT `fk_rental_regulation_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rental_regulation_category_vehicle` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- WAVE 2: lapisan booking (status, penugasan, premi, biaya)
-- Menyertai travel_ticket_bookings & rental_car_bookings (lihat file revisi).
-- booking_type = 'travel' | 'rental'
-- =====================================================================

-- Daftar penumpang per booking (banyak penumpang; satu ditandai primary).
-- booking.passenger_id tetap dipertahankan sebagai penumpang utama (primary).
CREATE TABLE `booking_passengers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_type` VARCHAR(10) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `passenger_id` INT(11) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `line_no` INT(11) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bp_booking_passenger` (`booking_type`, `booking_id`, `passenger_id`),
  KEY `ix_bp_booking` (`booking_type`, `booking_id`),
  KEY `ix_bp_passenger` (`passenger_id`),
  KEY `ix_bp_company` (`company_id`),
  CONSTRAINT `fk_bp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bp_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booking_status_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_type` VARCHAR(10) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `from_status` VARCHAR(30) DEFAULT NULL,
  `to_status` VARCHAR(30) NOT NULL,
  `reason` VARCHAR(2000) DEFAULT NULL,
  `changed_by` VARCHAR(255) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bsh_booking` (`booking_type`, `booking_id`),
  KEY `ix_bsh_company` (`company_id`),
  CONSTRAINT `fk_bsh_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trip_assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_type` VARCHAR(10) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `vehicle_id` INT(11) DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` DATETIME DEFAULT NULL,
  `reason` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ta_booking` (`booking_type`, `booking_id`),
  KEY `ix_ta_employee` (`employee_id`),
  KEY `ix_ta_company` (`company_id`),
  CONSTRAINT `fk_ta_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ta_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ta_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `premi_configs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `category_vehicle_id` INT(11) NOT NULL,
  `cost_type` VARCHAR(20) NOT NULL,
  `premi_type` VARCHAR(10) NOT NULL DEFAULT 'nominal' COMMENT 'nominal|persen',
  `premi_value` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_premi_configs_key` (`company_id`, `category_vehicle_id`, `cost_type`),
  KEY `ix_premi_configs_company` (`company_id`),
  KEY `ix_premi_configs_category` (`category_vehicle_id`),
  CONSTRAINT `fk_premi_configs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_premi_configs_category` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booking_cost_lines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_type` VARCHAR(10) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `cost_type` VARCHAR(20) NOT NULL COMMENT 'a|b|c|bbm|payment|...',
  `description` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `payment_method_id` INT(11) DEFAULT NULL COMMENT 'metode bayar (untuk cost_type=payment)',
  `employee_id` INT(11) DEFAULT NULL COMMENT 'biaya dipetakan ke karyawan (driver/pengawas); NULL = tanpa premi',
  `premi_type` VARCHAR(10) NOT NULL DEFAULT 'nominal' COMMENT 'nominal|persen',
  `premi_value` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `premi_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bcl_booking` (`booking_type`, `booking_id`),
  KEY `ix_bcl_employee` (`employee_id`),
  KEY `ix_bcl_company` (`company_id`),
  KEY `ix_bcl_payment_method` (`payment_method_id`),
  CONSTRAINT `fk_bcl_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bcl_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------- NOTIFIKASI WHATSAPP ----------
-- Setelan gateway WA per perusahaan (satu baris per perusahaan).
CREATE TABLE `wa_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `api_url` VARCHAR(255) NOT NULL COMMENT 'endpoint gateway WA, mis. https://api.example.com/send',
  `api_token` VARCHAR(255) DEFAULT NULL,
  `sender` VARCHAR(50) DEFAULT NULL,
  `notify_phones` VARCHAR(1000) DEFAULT NULL COMMENT 'nomor tujuan, pisah koma; kosong = nomor penumpang',
  `template_travel` VARCHAR(2000) DEFAULT NULL,
  `template_rental` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log pengiriman; mendukung kirim langsung (wa_send) maupun antrean pull/webhook Hermes.
CREATE TABLE `wa_outbox` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_type` VARCHAR(10) NOT NULL DEFAULT 'travel',
  `booking_id` INT(11) NOT NULL DEFAULT 0,
  `event_id` VARCHAR(64) DEFAULT NULL,
  `event_type` VARCHAR(50) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `message` VARCHAR(2000) DEFAULT NULL,
  `payload` LONGTEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL COMMENT 'pending|sent|failed|skipped',
  `attempts` INT(11) NOT NULL DEFAULT 0,
  `next_try_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `response` VARCHAR(1000) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_waout_event_id` (`event_id`),
  KEY `ix_waout_company` (`company_id`),
  KEY `ix_waout_booking` (`booking_type`, `booking_id`),
  KEY `ix_waout_status_next` (`status`, `next_try_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Tanpa FOREIGN KEY: sengaja, lihat alasan di tabel booking_cost_lines (log historis
-- harus tetap ada walau booking/perusahaan dihapus).

-- ---------- PAYMENT GATEWAY QRIS DINAMIS & BOT HERMES ----------
CREATE TABLE `payment_channels` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `provider` VARCHAR(20) NOT NULL COMMENT 'gopay|shopeepay|klikqris',
  `api_key` TEXT DEFAULT NULL COMMENT 'Header JSON (GoPay), Bearer Token B: (ShopeePay), atau API Key (KlikQRIS)',
  `merchant_id` TEXT DEFAULT NULL COMMENT 'String QRIS Statis EMVCo (GoPay/ShopeePay) atau ID Merchant (KlikQRIS)',
  `config` TEXT DEFAULT NULL COMMENT 'JSON refresh_token, merchant_name, fee, dll.',
  `mode` VARCHAR(20) NOT NULL DEFAULT 'production',
  `webhook_token` VARCHAR(128) DEFAULT NULL,
  `instructions` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paychan_company_provider` (`company_id`, `provider`),
  KEY `ix_paychan_company` (`company_id`),
  CONSTRAINT `fk_paychan_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_gateway_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `channel_id` INT(11) NOT NULL,
  `order_id` VARCHAR(64) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `booking_type` VARCHAR(10) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL COMMENT 'nominal tagihan + fee sebelum kode unik',
  `total_amount` DECIMAL(18,2) NOT NULL COMMENT 'nominal tagihan + fee + kode unik',
  `status` ENUM('PENDING','PAID','EXPIRED','FAILED') NOT NULL DEFAULT 'PENDING',
  `qris_url` VARCHAR(2000) DEFAULT NULL,
  `direct_url` VARCHAR(2000) DEFAULT NULL,
  `signature` VARCHAR(191) DEFAULT NULL COMMENT 'id mutasi unik dari GoPay/ShopeePay',
  `gateway_type` VARCHAR(30) DEFAULT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'web' COMMENT 'web|whatsapp_bot',
  `payload` LONGTEXT DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME DEFAULT NULL,
  `cost_line_id` INT(11) DEFAULT NULL COMMENT 'id baris di booking_cost_lines setelah lunas',
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pgo_order_id` (`order_id`),
  KEY `ix_pgo_company_status` (`company_id`, `status`),
  KEY `ix_pgo_invoice_status` (`invoice_id`, `status`),
  KEY `ix_pgo_channel_status` (`channel_id`, `status`),
  KEY `ix_pgo_signature` (`channel_id`, `signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bot_request_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) DEFAULT NULL,
  `key_id` VARCHAR(64) DEFAULT NULL,
  `phone` VARCHAR(50) NOT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `http_status` INT(11) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_brl_phone_created` (`phone`, `created_at`),
  KEY `ix_brl_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- INVOICE & DOKUMEN ----------
CREATE TABLE `document_sequences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `doc_type` VARCHAR(30) NOT NULL,
  `last_no` INT(11) NOT NULL DEFAULT 0,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_sequences_key` (`company_id`, `doc_type`),
  CONSTRAINT `fk_document_sequences_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_methods` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) DEFAULT NULL COMMENT 'NULL = berlaku semua perusahaan',
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `account_name` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(50) DEFAULT NULL,
  `qr_blob_path` VARCHAR(255) DEFAULT NULL COMMENT 'path gambar QR di Azure Blob Storage',
  `instructions` VARCHAR(2000) DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `auto_verify` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_methods_code` (`company_id`, `code`),
  KEY `ix_payment_methods_company` (`company_id`),
  CONSTRAINT `fk_payment_methods_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- KAS & BANK (buku kas di luar booking: uang masuk/keluar manual)
-- ---------------------------------------------------------------------
-- Tanpa FOREIGN KEY (mengikuti booking_cost_lines): urutan tabel di schema ini tidak
-- menjamin induk sudah ada saat import. Tautan lintas perusahaan dijaga di aplikasi
-- (semua query di-scope company_id + validasi akun memakai perusahaan aktif).
CREATE TABLE `cash_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` VARCHAR(20) NOT NULL COMMENT 'kas|bank',
  `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'saldo awal sebelum transaksi',
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cashacc_company_code` (`company_id`, `code`),
  KEY `ix_cashacc_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `cash_account_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `direction` VARCHAR(3) NOT NULL COMMENT 'in|out',
  `amount` DECIMAL(18,2) NOT NULL,
  `category` VARCHAR(50) DEFAULT NULL,
  `description` VARCHAR(1000) DEFAULT NULL,
  `payment_method_id` INT(11) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cashtx_company_date` (`company_id`, `date`),
  KEY `ix_cashtx_account` (`cash_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `branch_id` INT(11) DEFAULT NULL,
  `booking_type` VARCHAR(10) NOT NULL COMMENT 'travel|rental',
  `booking_id` INT(11) NOT NULL,
  `invoice_no` VARCHAR(40) NOT NULL COMMENT 'nomor urut dari document_sequences',
  `document_number` VARCHAR(40) NOT NULL COMMENT 'nomor resi acak, dipakai link pelanggan',
  `status` VARCHAR(20) NOT NULL DEFAULT 'issued' COMMENT 'issued|cancelled',
  `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `payment_method_id` INT(11) DEFAULT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_no` (`company_id`, `invoice_no`),
  UNIQUE KEY `uq_invoices_document_number` (`document_number`),
  KEY `ix_invoices_booking` (`booking_type`, `booking_id`),
  KEY `ix_invoices_company` (`company_id`),
  KEY `ix_invoices_status` (`status`),
  CONSTRAINT `fk_invoices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoices_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_revisions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) NOT NULL,
  `revision_no` INT(11) NOT NULL DEFAULT 1,
  `snapshot_json` LONGTEXT NOT NULL COMMENT 'isi invoice sebelum perubahan',
  `reason` VARCHAR(2000) DEFAULT NULL,
  `changed_by` VARCHAR(255) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_invoice_revisions_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_revisions_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------- MASTER HR ----------
CREATE TABLE `job_grades` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  -- Gaji pokok & uang harian: satu-satunya sumber nilai payroll (modul Payroll).
  `basic_salary` DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'Gaji pokok per bulan',
  `daily_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'Uang harian per hari hadir',
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_grades_code` (`company_id`, `code`),
  KEY `ix_job_grades_company` (`company_id`),
  CONSTRAINT `fk_job_grades_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skema sebelumnya memakai rentang from/mid/to_amount; gaji pokok + uang harian
-- sekarang kolom utama (lihat job_grades di atas). Baris lama dipertahankan di kolom
-- netral ini supaya nilai rentang tidak hilang setelah konversi.
CREATE TABLE `job_grade_amounts` (
  `job_grade_id` INT(11) NOT NULL,
  `from_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `mid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `to_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`job_grade_id`),
  CONSTRAINT `fk_job_grade_amounts_grade` FOREIGN KEY (`job_grade_id`) REFERENCES `job_grades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_titles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) DEFAULT NULL COMMENT 'NULL = berlaku semua perusahaan',
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `level` INT(11) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_titles_code` (`company_id`, `code`),
  KEY `ix_job_titles_company` (`company_id`),
  CONSTRAINT `fk_job_titles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_positions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `job_title_id` INT(11) NOT NULL,
  `parent_id` INT(11) DEFAULT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `level` INT(11) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_positions_code` (`company_id`, `code`),
  KEY `ix_job_positions_company` (`company_id`),
  KEY `ix_job_positions_title` (`job_title_id`),
  KEY `ix_job_positions_parent` (`parent_id`),
  CONSTRAINT `fk_job_positions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_job_positions_title` FOREIGN KEY (`job_title_id`) REFERENCES `job_titles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_job_positions_parent` FOREIGN KEY (`parent_id`) REFERENCES `job_positions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organization_structures` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `parent_id` INT(11) DEFAULT NULL,
  `code` VARCHAR(15) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `level` INT(11) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organization_structures_code` (`company_id`, `code`),
  KEY `ix_organization_structures_company` (`company_id`),
  KEY `ix_organization_structures_parent` (`parent_id`),
  CONSTRAINT `fk_organization_structures_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_organization_structures_parent` FOREIGN KEY (`parent_id`) REFERENCES `organization_structures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employee_recognitions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `type` VARCHAR(30) NOT NULL,
  `index_no` INT(11) NOT NULL DEFAULT 0,
  `template` VARCHAR(255) DEFAULT NULL,
  `size` INT(11) DEFAULT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_employee_recognitions_employee` (`employee_id`),
  KEY `ix_employee_recognitions_company` (`company_id`),
  CONSTRAINT `fk_employee_recognitions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_recognitions_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- MODUL PAYROLL (gaji per periode bulanan)
-- ---------------------------------------------------------------------
CREATE TABLE `payroll_periods` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `period_year` SMALLINT(6) NOT NULL,
  `period_month` TINYINT(4) NOT NULL,
  `status` ENUM('draft','final','paid') NOT NULL DEFAULT 'draft',
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_periods_company_period` (`company_id`, `period_year`, `period_month`),
  CONSTRAINT `fk_payroll_periods_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payroll_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payroll_period_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `basic_salary` DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'salinan gaji pokok grade saat generate',
  `daily_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `days_present` INT(11) NOT NULL DEFAULT 0,
  `deductions` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `net_salary` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(2000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_payroll_items_period` (`payroll_period_id`),
  KEY `ix_payroll_items_employee` (`employee_id`),
  KEY `ix_payroll_items_company` (`company_id`),
  CONSTRAINT `fk_payroll_items_period` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_items_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payroll_items_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- MODUL ABSENSI (kehadiran harian karyawan)
-- ---------------------------------------------------------------------
-- Tanpa FOREIGN KEY: urutan tabel di schema ini tidak menjamin induk sudah ada saat import.
-- Tautan lintas perusahaan dijaga di aplikasi (query di-scope company_id + validasi karyawan
-- memakai perusahaan aktif). UNIQUE (employee_id, date) = satu karyawan satu baris per hari,
-- sekaligus mencegah dobel input (form memakai INSERT ... ON DUPLICATE KEY UPDATE).
-- Rekap (summary.php) dihitung on-the-fly; tidak ada tabel ringkasan tersimpan.
CREATE TABLE `employee_attendances` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `status` VARCHAR(20) NOT NULL COMMENT 'hadir|izin|sakit|cuti|alpa',
  `check_in` TIME DEFAULT NULL,
  `check_out` TIME DEFAULT NULL,
  `note` VARCHAR(1000) DEFAULT NULL,
  `created_user` VARCHAR(255) DEFAULT NULL,
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_user` VARCHAR(255) DEFAULT NULL,
  `modified_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_emp_date` (`employee_id`, `date`),
  KEY `ix_att_company_date` (`company_id`, `date`),
  KEY `ix_att_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- PERMISSION MODUL BARU
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('masters.view',   'Lihat Referensi umum',   'masters'),
('masters.manage', 'Kelola Referensi umum', 'masters'),
('hr.view',   'Lihat Master HR',   'hr'),
('hr.manage', 'Kelola Master HR', 'hr'),
('vehicles.view',   'Lihat Kendaraan',   'vehicles'),
('vehicles.manage', 'Kelola Kendaraan', 'vehicles'),
('travel.view',   'Lihat Travel & booking',   'travel'),
('travel.manage', 'Kelola Travel & booking', 'travel'),
('rental.view',   'Lihat Rental',   'rental'),
('rental.manage', 'Kelola Rental', 'rental'),
('booking.ai_input', 'Bantu isi form booking dengan AI', 'bookings'),
('invoices.view',   'Lihat Invoice & tiket',   'invoices'),
('invoices.manage', 'Kelola Invoice & tiket', 'invoices'),
('dispatch.view',   'Lihat Penjadwalan driver',   'dispatch'),
('dispatch.manage', 'Kelola Penjadwalan driver', 'dispatch'),
('finance.view',   'Lihat Keuangan',   'finance'),
('finance.manage', 'Kelola Keuangan', 'finance'),
('attendance.view',   'Lihat Absensi',   'attendance'),
('attendance.manage', 'Kelola Absensi', 'attendance'),
('payroll.view',   'Lihat Payroll',   'payroll'),
('payroll.manage', 'Kelola Payroll', 'payroll');

-- Super Admin: semua permission baru
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.id FROM `permissions` p
WHERE (p.module IN ('masters','hr','vehicles','travel','rental','invoices','dispatch','finance','attendance','payroll','bookings'))
  AND p.id NOT IN (SELECT permission_id FROM `role_permissions` WHERE role_id = 1);
-- Admin Perusahaan (2): semua permission baru
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, p.id FROM `permissions` p
WHERE (p.module IN ('masters','hr','vehicles','travel','rental','invoices','dispatch','finance','attendance','payroll','bookings'))
  AND p.id NOT IN (SELECT permission_id FROM `role_permissions` WHERE role_id = 2);
-- HRD (3): HR + absensi + payroll + operasi (view & manage)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, p.id FROM `permissions` p
WHERE p.name IN ('masters.view','hr.view','hr.manage','attendance.view','attendance.manage','payroll.view','payroll.manage','travel.view','rental.view','vehicles.view');
-- Staff (4) & Viewer (5): semua .view kecuali audit/user directory/payroll/finance
-- (finance & payroll mengandung nominal sensitif; users = direktori pengguna).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `permissions` p JOIN (SELECT 4 AS id UNION ALL SELECT 5) r
WHERE p.name LIKE '%.view'
  AND p.name NOT IN ('audit.view','users.view','payroll.view','finance.view');

-- Laporan Booking: halaman babar membaca angka operasional booking (bukan buku kas),
-- jadi modul-nya 'reports' dan ikut ".view" seperti modul lain → Staff & Viewer dapat.
INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('reports.view', 'Lihat Laporan Booking', 'reports');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `permissions` p JOIN (SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 4 UNION ALL SELECT 5) r
WHERE p.name = 'reports.view';

COMMIT;