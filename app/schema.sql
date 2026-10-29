-- Datenbankschema der Protokolldatenbank
-- Wird vom Installer (install/) automatisch eingespielt.
-- Kompatibel mit MySQL >= 5.7 und MariaDB >= 10.3

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(150) NOT NULL DEFAULT '',
  `role` VARCHAR(20) NOT NULL DEFAULT 'student',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `is_demo` TINYINT(1) NOT NULL DEFAULT 0,
  `verify_token` CHAR(64) NULL,
  `verify_expires` DATETIME NULL,
  `reset_token` CHAR(64) NULL,
  `reset_expires` DATETIME NULL,
  `admin_note` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `verified_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_verify` (`verify_token`),
  KEY `idx_users_reset` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exams` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `short_name` VARCHAR(30) NOT NULL DEFAULT '',
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `color` VARCHAR(7) NOT NULL DEFAULT '#0d6e78',
  `price_cents` INT NULL,
  `refund_cents` INT NULL,
  `max_examiners` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exams_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `examiners` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `name_key` VARCHAR(150) NOT NULL DEFAULT '',
  `subject` VARCHAR(100) NOT NULL DEFAULT '',
  `info` TEXT NULL,
  `admin_note` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `needs_review` TINYINT(1) NOT NULL DEFAULT 0,
  `is_demo` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_examiners_exam` (`exam_id`),
  KEY `idx_examiners_key` (`exam_id`, `name_key`),
  CONSTRAINT `fk_examiners_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `protocols` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examiner_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `submission_id` VARCHAR(32) NULL,
  `title` VARCHAR(200) NOT NULL,
  `exam_date` DATE NULL,
  `grade` VARCHAR(20) NULL,
  `content` MEDIUMTEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'published',
  `reject_reason` TEXT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'upload',
  `refund_purchase_id` INT UNSIGNED NULL,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  `published_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_protocols_examiner` (`examiner_id`, `status`),
  KEY `idx_protocols_user` (`user_id`),
  KEY `idx_protocols_submission` (`submission_id`),
  KEY `idx_protocols_status` (`status`),
  CONSTRAINT `fk_protocols_examiner` FOREIGN KEY (`examiner_id`) REFERENCES `examiners` (`id`),
  CONSTRAINT `fk_protocols_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `protocol_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `protocol_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(120) NOT NULL,
  `mime` VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `sha1` CHAR(40) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_files_protocol` (`protocol_id`),
  KEY `idx_files_sha1` (`sha1`),
  CONSTRAINT `fk_files_protocol` FOREIGN KEY (`protocol_id`) REFERENCES `protocols` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `exam_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `provider` VARCHAR(20) NOT NULL DEFAULT 'stripe',
  `amount_cents` INT NOT NULL DEFAULT 0,
  `currency` CHAR(3) NOT NULL DEFAULT 'eur',
  `stripe_session_id` VARCHAR(191) NULL,
  `stripe_payment_intent` VARCHAR(191) NULL,
  `refund_status` VARCHAR(20) NOT NULL DEFAULT 'none',
  `refund_cents` INT NOT NULL DEFAULT 0,
  `refund_id` VARCHAR(255) NULL,
  `refund_error` TEXT NULL,
  `refund_attempts` INT NOT NULL DEFAULT 0,
  `refund_protocol_id` INT UNSIGNED NULL,
  `admin_note` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `paid_at` DATETIME NULL,
  `refunded_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_user` (`user_id`, `status`),
  KEY `idx_purchases_exam` (`exam_id`),
  KEY `idx_purchases_session` (`stripe_session_id`),
  KEY `idx_purchases_intent` (`stripe_payment_intent`),
  KEY `idx_purchases_refund` (`refund_status`),
  CONSTRAINT `fk_purchases_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_purchases_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_examiners` (
  `purchase_id` INT UNSIGNED NOT NULL,
  `examiner_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`purchase_id`, `examiner_id`),
  KEY `idx_pe_examiner` (`examiner_id`),
  CONSTRAINT `fk_pe_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_examiner` FOREIGN KEY (`examiner_id`) REFERENCES `examiners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `name` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(191) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_text` MEDIUMTEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `error` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mail_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(50) NOT NULL,
  `details` TEXT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_action` (`action`),
  KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `download_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `protocol_id` INT UNSIGNED NULL,
  `file_id` INT UNSIGNED NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'file',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_download_user` (`user_id`),
  KEY `idx_download_protocol` (`protocol_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(191) NOT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_identifier` (`identifier`, `created_at`),
  KEY `idx_attempts_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_events` (
  `id` VARCHAR(191) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `processed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
