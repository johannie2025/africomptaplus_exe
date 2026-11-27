SET NAMES utf8mb4;
-- ───────── Bot WhatsApp (Evolution API) ─────────
CREATE TABLE IF NOT EXISTS bot_settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_accounts (
  license_id INT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  instance_name VARCHAR(60) NOT NULL UNIQUE,
  token CHAR(40) NOT NULL UNIQUE,
  wa_state VARCHAR(20) NOT NULL DEFAULT 'none',
  wa_phone VARCHAR(30) NOT NULL DEFAULT '',
  shop_name VARCHAR(120) NOT NULL DEFAULT '',
  currency VARCHAR(8) NOT NULL DEFAULT 'XAF',
  tax_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  tax_label VARCHAR(20) NOT NULL DEFAULT 'TVA',
  momo_info VARCHAR(300) NOT NULL DEFAULT '',
  prompt_extra TEXT NULL,
  allow_credit TINYINT(1) NOT NULL DEFAULT 0,
  bot_auto TINYINT(1) NOT NULL DEFAULT 1,
  source ENUM('app','web') NOT NULL DEFAULT 'app',
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_bot_lic FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_products (
  license_id INT UNSIGNED NOT NULL,
  local_id BIGINT NOT NULL,
  name VARCHAR(160) NOT NULL,
  barcode VARCHAR(64) NOT NULL DEFAULT '',
  category VARCHAR(80) NOT NULL DEFAULT '',
  price DECIMAL(14,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (license_id, local_id),
  INDEX idx_bp_name (license_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_sessions (
  license_id INT UNSIGNED NOT NULL,
  phone VARCHAR(30) NOT NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  cart MEDIUMTEXT NULL,
  payment VARCHAR(20) NOT NULL DEFAULT '',
  history MEDIUMTEXT NULL,
  msg_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (license_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_id INT UNSIGNED NOT NULL,
  reference VARCHAR(40) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  items MEDIUMTEXT NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment VARCHAR(20) NOT NULL,
  sync_state ENUM('pending','synced') NOT NULL DEFAULT 'pending',
  invoice_sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_bo_ref (license_id, reference),
  INDEX idx_bo_sync (license_id, sync_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_id INT UNSIGNED NULL,
  phone VARCHAR(30) NOT NULL DEFAULT '',
  direction ENUM('in','out','err') NOT NULL,
  body VARCHAR(600) NOT NULL DEFAULT '',
  model VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_bl_lic (license_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
