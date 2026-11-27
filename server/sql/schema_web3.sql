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

CREATE TABLE IF NOT EXISTS w_stock_moves (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL, name VARCHAR(160) NOT NULL DEFAULT '',
  kind VARCHAR(10) NOT NULL, qty INT NOT NULL, unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0, move_date DATETIME NOT NULL, reason VARCHAR(60) NOT NULL DEFAULT '', note VARCHAR(200) NOT NULL DEFAULT '',
  uid VARCHAR(40) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wsm_uid (license_id, uid), INDEX idx_wsm_p (license_id, product_id), INDEX idx_wsm_upd (license_id, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
