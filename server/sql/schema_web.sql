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
