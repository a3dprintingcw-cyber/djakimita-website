-- Which reminder emails went out, so none is sent twice.
CREATE TABLE IF NOT EXISTS reminders_sent (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  kind    VARCHAR(20) NOT NULL,   -- verify3, verify10, exp30, exp7
  ref_id  INT NOT NULL,           -- sale_receits.id (verify) or clients_package.id (expiry)
  sent_at DATETIME NOT NULL,
  UNIQUE KEY once (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
