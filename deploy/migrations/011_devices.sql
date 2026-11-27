-- One account, one phone: every phone logged in to the app (customers)
CREATE TABLE IF NOT EXISTS client_devices (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  label      VARCHAR(80) NOT NULL DEFAULT 'Phone',
  created_at DATETIME NOT NULL,
  last_seen  DATETIME NOT NULL,
  KEY client (client_id),
  UNIQUE KEY tok (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
