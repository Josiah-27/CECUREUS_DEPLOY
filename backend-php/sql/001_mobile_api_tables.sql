-- CecureUs mobile API — helper tables.
-- Run once on the existing database (staging first). Safe to re-run.
--
-- These are NEW tables. Existing tables (employee_list, doctor_list, booking_appointment, …) are
-- not altered: the API reads/writes them in the same format the website uses.
-- They hold short-lived security state that does not belong in employee_list:
-- sign-in tokens, OTP requests, sign-ups in progress, rate-limit counters and booking idempotency.
-- No OTP code, password or token is stored in clear — only hashes.

CREATE TABLE IF NOT EXISTS `mobile_sessions` (
  `token_hash`   CHAR(64)     NOT NULL COMMENT 'SHA-256 of the bearer token',
  `emp_id`       INT          NOT NULL,
  `created_at`   DATETIME     NOT NULL COMMENT 'UTC',
  `expires_at`   DATETIME     NOT NULL COMMENT 'UTC',
  `last_used_at` DATETIME     NULL,
  `revoked_at`   DATETIME     NULL,
  `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`token_hash`),
  KEY `idx_mobile_sessions_emp` (`emp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mobile_otp_requests` (
  `request_id`      CHAR(32)         NOT NULL,
  `purpose`         VARCHAR(32)      NOT NULL COMMENT 'register_email | register_phone',
  `target`          VARCHAR(255)     NOT NULL COMMENT 'email or +91 mobile',
  `registration_id` CHAR(64)         NULL     COMMENT 'mobile_registrations.token_hash (phone step)',
  `meta`            VARCHAR(500)     NOT NULL DEFAULT '{}',
  `code_hash`       CHAR(64)         NOT NULL COMMENT 'HMAC-SHA256 of the code; the code itself is never stored',
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      DATETIME         NOT NULL COMMENT 'UTC',
  `expires_at`      DATETIME         NOT NULL COMMENT 'UTC',
  `consumed_at`     DATETIME         NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_mobile_otp_target` (`purpose`, `target`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mobile_registrations` (
  `token_hash`        CHAR(64)     NOT NULL COMMENT 'SHA-256 of the registrationToken',
  `email`             VARCHAR(255) NOT NULL,
  `user_type`         VARCHAR(16)  NOT NULL COMMENT 'corporate | individual',
  `company_name`      VARCHAR(250) NOT NULL DEFAULT '',
  `mobile`            VARCHAR(20)  NULL,
  `phone_verified_at` DATETIME     NULL,
  `created_at`        DATETIME     NOT NULL COMMENT 'UTC',
  `expires_at`        DATETIME     NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`token_hash`),
  KEY `idx_mobile_registrations_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mobile_rate_limits` (
  `bucket`       CHAR(64)     NOT NULL COMMENT 'SHA-256 of the limit name (no personal data in clear)',
  `window_start` DATETIME     NOT NULL COMMENT 'UTC',
  `hits`         INT UNSIGNED NOT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mobile_bookings` (
  `book_id`         INT          NOT NULL COMMENT 'booking_appointment.book_id',
  `emp_id`          INT          NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `sms_status`      VARCHAR(16)  NOT NULL DEFAULT 'queued' COMMENT 'sent | queued | failed | not_required',
  `sms_sent_at`     DATETIME     NULL,
  `created_at`      DATETIME     NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`book_id`),
  UNIQUE KEY `uq_mobile_bookings_idempotency` (`emp_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Housekeeping (optional, e.g. a daily cron):
-- DELETE FROM mobile_otp_requests  WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY;
-- DELETE FROM mobile_registrations WHERE expires_at < UTC_TIMESTAMP();
-- DELETE FROM mobile_rate_limits   WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY;
-- DELETE FROM mobile_sessions      WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY;
