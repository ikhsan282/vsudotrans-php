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
DROP TABLE IF EXISTS `audit_trails`, `login_attempts`, `user_permissions`, `user_companies`,
  `employees`, `users`, `roles`, `permissions`, `branches`, `companies`, `groups`;
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
-- Staff: view semua modul kecuali audit
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` WHERE `name` LIKE '%.view' AND `name` <> 'audit.view';
-- Viewer: view saja
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 5, `id` FROM `permissions` WHERE `name` LIKE '%.view' AND `name` <> 'audit.view';

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
(1, 'VST', 'VSudoTech Group', 'Holding grup perusahaan');

INSERT INTO `companies` (`id`, `group_id`, `code`, `name`, `country_code`, `phone_number`, `email`, `address`, `city`) VALUES
(1, 1, 'VST-TRANS', 'VSudo Trans', '+62', '021-0000000', 'info@vsudotrans.test', 'Jl. Contoh No. 1', 'Jakarta');

INSERT INTO `branches` (`id`, `company_id`, `code`, `name`, `country_code`, `phone_number`) VALUES
(1, 1, 'PST', 'Kantor Pusat', '+62', '021-0000001');

-- Semua password default: P@ssw0rd
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `phone_number`, `role_id`, `company_id`) VALUES
(1, 'superadmin', 'superadmin@vsudotrans.test', '$2y$12$YPSdYJ4iq7ubYl4xcXPfPeT/g2oZfEwH42nfnzmgQUMBsIYy53uVK', 'Super Administrator', '081200000001', 1, NULL),
(2, 'admin',      'admin@vsudotrans.test',      '$2y$12$YPSdYJ4iq7ubYl4xcXPfPeT/g2oZfEwH42nfnzmgQUMBsIYy53uVK', 'Administrator Perusahaan', '081200000002', 2, 1),
(3, 'hrd',        'hrd@vsudotrans.test',        '$2y$12$YPSdYJ4iq7ubYl4xcXPfPeT/g2oZfEwH42nfnzmgQUMBsIYy53uVK', 'HRD', '081200000003', 3, 1),
(4, 'staff',      'staff@vsudotrans.test',      '$2y$12$YPSdYJ4iq7ubYl4xcXPfPeT/g2oZfEwH42nfnzmgQUMBsIYy53uVK', 'Staff', '081200000004', 4, 1),
(5, 'viewer',     'viewer@vsudotrans.test',     '$2y$12$YPSdYJ4iq7ubYl4xcXPfPeT/g2oZfEwH42nfnzmgQUMBsIYy53uVK', 'Viewer', '081200000005', 5, 1);

INSERT INTO `user_companies` (`user_id`, `company_id`) VALUES (2, 1), (3, 1), (4, 1), (5, 1);

INSERT INTO `employees` (`company_id`, `branch_id`, `code`, `name`, `email`, `phone_number`, `join_date`, `job_title`, `job_position`, `status`) VALUES
(1, 1, 'EMP-0001', 'Budi Santoso', 'budi@vsudotrans.test', '081200000011', '2024-01-15', 'Staff Operasional', 'Driver', 'aktif');

COMMIT;