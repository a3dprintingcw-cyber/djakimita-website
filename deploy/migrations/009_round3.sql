-- Round 3: partner notes, creator codes, contact messages
ALTER TABLE partner_extra ADD COLUMN IF NOT EXISTS note TEXT NULL;
ALTER TABLE discount_codes ADD COLUMN IF NOT EXISTS creator VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE discount_codes ADD COLUMN IF NOT EXISTS creator_email VARCHAR(190) NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS contact_messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  message     TEXT NOT NULL,
  ip          VARCHAR(64) NOT NULL DEFAULT '',
  created     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done        TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
