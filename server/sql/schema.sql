SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key VARCHAR(32) NOT NULL UNIQUE,
  plan ENUM('basic','pro','lifetime') NOT NULL DEFAULT 'basic',
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  customer_phone VARCHAR(40) NOT NULL DEFAULT '',
  customer_email VARCHAR(120) NOT NULL DEFAULT '',
  shop_name VARCHAR(120) NOT NULL DEFAULT '',
  max_devices TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  expires_at DATETIME NULL DEFAULT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_id INT UNSIGNED NOT NULL,
  device_id VARCHAR(128) NOT NULL,
  device_name VARCHAR(120) NOT NULL DEFAULT '',
  app_version VARCHAR(30) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  first_activated_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE KEY uq_lic_dev (license_id, device_id),
  CONSTRAINT fk_act_lic FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key VARCHAR(64) NOT NULL DEFAULT '',
  device_id VARCHAR(128) NOT NULL DEFAULT '',
  action VARCHAR(20) NOT NULL,
  result VARCHAR(20) NOT NULL,
  message VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_key (license_key),
  INDEX idx_ip_time (ip, created_at),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────── IA : fournisseurs, modèles, routage avec cooldown ─────────
CREATE TABLE IF NOT EXISTS ai_providers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  preset VARCHAR(30) NOT NULL DEFAULT 'custom',
  kind ENUM('openai','gemini','anthropic') NOT NULL DEFAULT 'openai',
  base_url VARCHAR(255) NOT NULL,
  api_key_enc TEXT NOT NULL,
  free_tier TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  models_fetched_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED NOT NULL,
  model_id VARCHAR(190) NOT NULL,
  label VARCHAR(190) NOT NULL DEFAULT '',
  is_free TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  priority SMALLINT NOT NULL DEFAULT 100,
  cooldown_until DATETIME NULL,
  fail_count SMALLINT NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NOT NULL DEFAULT '',
  last_used_at DATETIME NULL,
  ok_count INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_prov_model (provider_id, model_id),
  INDEX idx_pick (enabled, priority, last_used_at),
  CONSTRAINT fk_model_prov FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_settings (
  skey VARCHAR(60) PRIMARY KEY,
  svalue VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_settings (skey, svalue) VALUES
  ('prefer','free_first'), ('cooldown_base','60'), ('cooldown_max','3600'), ('max_attempts','5'), ('timeout','30');
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
SET NAMES utf8mb4;
-- ───────── Panel web / PWA : un espace par licence ─────────
CREATE TABLE IF NOT EXISTS w_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_id INT UNSIGNED NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  lang VARCHAR(5) NOT NULL DEFAULT 'auto',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  CONSTRAINT fk_wu_lic FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_settings (
  license_id INT UNSIGNED NOT NULL, skey VARCHAR(40) NOT NULL, svalue TEXT NOT NULL,
  PRIMARY KEY (license_id, skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL, barcode VARCHAR(64) NOT NULL DEFAULT '', category VARCHAR(80) NOT NULL DEFAULT '',
  buy_price DECIMAL(14,2) NOT NULL DEFAULT 0, sell_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0, min_stock INT NOT NULL DEFAULT 5, created_at DATETIME NOT NULL,
  INDEX idx_wp (license_id, name), INDEX idx_wp_bc (license_id, barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '',
  address VARCHAR(200) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
  INDEX idx_wc (license_id, name), INDEX idx_wc_ph (license_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL,
  reference VARCHAR(40) NOT NULL, customer_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT '', customer_phone VARCHAR(30) NOT NULL DEFAULT '',
  subtotal DECIMAL(14,2) NOT NULL, tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0, total DECIMAL(14,2) NOT NULL,
  paid DECIMAL(14,2) NOT NULL DEFAULT 0, payment VARCHAR(20) NOT NULL, due_date DATE NULL,
  source ENUM('web','bot') NOT NULL DEFAULT 'web', created_at DATETIME NOT NULL,
  UNIQUE KEY uq_ws_ref (license_id, reference), INDEX idx_ws_date (license_id, created_at), INDEX idx_ws_cust (license_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_sale_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sale_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL, qty INT NOT NULL, unit_price DECIMAL(14,2) NOT NULL, buy_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  INDEX idx_wsi (sale_id), CONSTRAINT fk_wsi FOREIGN KEY (sale_id) REFERENCES w_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, sale_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL, method VARCHAR(20) NOT NULL DEFAULT 'cash', created_at DATETIME NOT NULL,
  INDEX idx_wpay (license_id, sale_id), CONSTRAINT fk_wpay FOREIGN KEY (sale_id) REFERENCES w_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS w_expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT '', description VARCHAR(200) NOT NULL DEFAULT '',
  amount DECIMAL(14,2) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_we (license_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET NAMES utf8mb4;
-- ───────── Panel web : fournisseurs, achats, devis/proforma, comptabilité ─────────
CREATE TABLE IF NOT EXISTS w_suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '',
  address VARCHAR(200) NOT NULL DEFAULT '', notes VARCHAR(300) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
  INDEX idx_wsup (license_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, reference VARCHAR(40) NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL, supplier_name VARCHAR(120) NOT NULL, total DECIMAL(14,2) NOT NULL,
  initial_paid DECIMAL(14,2) NOT NULL DEFAULT 0, paid DECIMAL(14,2) NOT NULL DEFAULT 0, payment VARCHAR(20) NOT NULL DEFAULT 'cash',
  notes VARCHAR(300) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
  UNIQUE KEY uq_wpu (license_id, reference), INDEX idx_wpu_s (license_id, supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_purchase_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL, qty INT NOT NULL, unit_cost DECIMAL(14,2) NOT NULL,
  INDEX idx_wpi (purchase_id), CONSTRAINT fk_wpi FOREIGN KEY (purchase_id) REFERENCES w_purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_supplier_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, purchase_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL, method VARCHAR(20) NOT NULL DEFAULT 'cash', created_at DATETIME NOT NULL,
  INDEX idx_wsp (license_id, purchase_id), CONSTRAINT fk_wsp FOREIGN KEY (purchase_id) REFERENCES w_purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, doc_type ENUM('quote','proforma') NOT NULL,
  reference VARCHAR(40) NOT NULL, customer_id BIGINT UNSIGNED NULL, customer_name VARCHAR(120) NOT NULL DEFAULT '', customer_phone VARCHAR(30) NOT NULL DEFAULT '',
  subtotal DECIMAL(14,2) NOT NULL, tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0, total DECIMAL(14,2) NOT NULL,
  valid_until DATE NULL, notes VARCHAR(300) NOT NULL DEFAULT '', status ENUM('open','converted') NOT NULL DEFAULT 'open',
  sale_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
  UNIQUE KEY uq_wd (license_id, reference), INDEX idx_wd (license_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_doc_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL, qty INT NOT NULL, unit_price DECIMAL(14,2) NOT NULL,
  INDEX idx_wdi (doc_id), CONSTRAINT fk_wdi FOREIGN KEY (doc_id) REFERENCES w_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_acct_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, entry_date DATE NOT NULL,
  journal VARCHAR(4) NOT NULL DEFAULT 'OD', label VARCHAR(200) NOT NULL, debit_account VARCHAR(10) NOT NULL,
  credit_account VARCHAR(10) NOT NULL, amount DECIMAL(14,2) NOT NULL, created_at DATETIME NOT NULL,
  INDEX idx_wae (license_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_acct_accounts (
  license_id INT UNSIGNED NOT NULL, code VARCHAR(10) NOT NULL, label VARCHAR(160) NOT NULL, PRIMARY KEY (license_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET NAMES utf8mb4;
-- ───────── Inventaire physique (comptages) ─────────
CREATE TABLE IF NOT EXISTS w_inventories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, reference VARCHAR(40) NOT NULL,
  inv_date DATE NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, uid VARCHAR(40) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_winv_ref (license_id, reference), UNIQUE KEY uq_winv_uid (license_id, uid), INDEX idx_winv_upd (license_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS w_inventory_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, inventory_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL, theoretical INT NOT NULL, counted INT NOT NULL, cmp DECIMAL(14,4) NOT NULL DEFAULT 0,
  INDEX idx_wii (inventory_id), CONSTRAINT fk_wii FOREIGN KEY (inventory_id) REFERENCES w_inventories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
