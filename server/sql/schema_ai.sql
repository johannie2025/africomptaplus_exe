
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
