-- Slows down password guessing on the website login.
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  k          CHAR(64) NOT NULL,          -- sha256 of "ip:..." or "email:..." (no plain data stored)
  created_at DATETIME NOT NULL,
  KEY k_time (k, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
