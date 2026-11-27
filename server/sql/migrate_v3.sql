-- À exécuter UNE fois sur une base existante (ignorer l'erreur « Duplicate column » si déjà fait).
ALTER TABLE bot_accounts ADD COLUMN source ENUM('app','web') NOT NULL DEFAULT 'app';
