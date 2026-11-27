-- Phone changes: each phone keeps its id after logout; 2 changes to a new phone per 12 months
ALTER TABLE client_devices
  ADD COLUMN uid VARCHAR(64) NULL AFTER client_id,
  ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN retired_at DATETIME NULL,
  MODIFY token_hash CHAR(64) NULL,
  ADD KEY client_uid (client_id, uid);
CREATE TABLE IF NOT EXISTS client_device_changes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,
  label      VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY client_time (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
