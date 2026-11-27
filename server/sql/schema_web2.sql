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
