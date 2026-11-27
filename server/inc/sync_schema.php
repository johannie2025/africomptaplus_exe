<?php
require_once __DIR__ . '/stock.php';
/** Tables d'inventaire physique (créées à la demande, sans import SQL). */
function inv_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS w_inventories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, reference VARCHAR(40) NOT NULL, inv_date DATE NOT NULL,
        note VARCHAR(300) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, uid VARCHAR(40) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_winv_ref (license_id, reference), UNIQUE KEY uq_winv_uid (license_id, uid), INDEX idx_winv_upd (license_id, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS w_inventory_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, inventory_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL, name VARCHAR(160) NOT NULL,
        theoretical INT NOT NULL, counted INT NOT NULL, cmp DECIMAL(14,4) NOT NULL DEFAULT 0, INDEX idx_wii (inventory_id),
        CONSTRAINT fk_wii FOREIGN KEY (inventory_id) REFERENCES w_inventories(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    stk_migrate_tables();
}
/** Migration automatique (une fois) : colonnes uid / updated_at sur les tables w_* + table des suppressions. */
function sync_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    inv_migrate();
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS w_tombstones (license_id INT UNSIGNED NOT NULL, entity VARCHAR(20) NOT NULL, uid VARCHAR(40) NOT NULL, deleted_at DATETIME NOT NULL,
        PRIMARY KEY (license_id, entity, uid), INDEX idx_wt (license_id, deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tables = ['w_products' => 1, 'w_customers' => 1, 'w_suppliers' => 1, 'w_expenses' => 1, 'w_sales' => 1, 'w_purchases' => 1, 'w_payments' => 0, 'w_supplier_payments' => 0, 'w_documents' => 1, 'w_acct_entries' => 1];
    $pdo->exec("CREATE TABLE IF NOT EXISTS w_sync_log (license_id INT UNSIGNED NOT NULL, req_id VARCHAR(40) NOT NULL, response LONGTEXT NOT NULL, created_at DATETIME NOT NULL,
        PRIMARY KEY (license_id, req_id), INDEX idx_wsl (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS w_sync_conflicts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, tbl VARCHAR(30) NOT NULL, uid VARCHAR(40) NOT NULL,
        label VARCHAR(160) NOT NULL, field VARCHAR(30) NOT NULL, device_value VARCHAR(300) NOT NULL, web_value VARCHAR(300) NOT NULL, created_at DATETIME NOT NULL, resolved TINYINT NOT NULL DEFAULT 0,
        INDEX idx_wsc (license_id, resolved)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='w_suppliers' AND COLUMN_NAME='archived'"); $c->execute();
    if (!(int)$c->fetchColumn()) $pdo->exec("ALTER TABLE w_suppliers ADD COLUMN archived TINYINT NOT NULL DEFAULT 0");
    $c = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='w_acct_accounts' AND COLUMN_NAME='updated_at'"); $c->execute();
    if (!(int)$c->fetchColumn()) $pdo->exec("ALTER TABLE w_acct_accounts ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    foreach ($tables as $t => $upd) {
        $has = function (string $c) use ($pdo, $t) { $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $s->execute([$t, $c]); return (int)$s->fetchColumn() > 0; };
        if (!$has('uid')) { $pdo->exec("ALTER TABLE $t ADD COLUMN uid VARCHAR(40) NULL"); $pdo->exec("ALTER TABLE $t ADD UNIQUE KEY uq_{$t}_uid (license_id, uid)"); }
        if ($upd && !$has('updated_at')) $pdo->exec("ALTER TABLE $t ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ADD INDEX idx_{$t}_upd (license_id, updated_at)");
    }
}
/** Appelé par le panel web à la suppression d'un produit/client/fournisseur (no-op si la synchro n'a jamais été activée). */
function sync_tomb_web(string $entity, string $table, int $id): void {
    try {
        $s = db()->prepare("SELECT uid FROM $table WHERE id=? AND license_id=?"); $s->execute([$id, lid()]); $uid = $s->fetchColumn();
        if (!$uid) $uid = 'w' . $id;
        db()->prepare('INSERT INTO w_tombstones (license_id,entity,uid,deleted_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE deleted_at=NOW()')->execute([lid(), $entity, $uid]);
    } catch (Throwable $e) { /* table absente : rien à faire */ }
}
