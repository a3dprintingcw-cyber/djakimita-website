-- Staff backend (djakimita.com/admin): staff accounts, site settings and an activity log.

CREATE TABLE IF NOT EXISTS admin_users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(60) NOT NULL,
  name        VARCHAR(120) NOT NULL DEFAULT '',
  pass_hash   VARCHAR(255) NOT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL,
  last_login  DATETIME NULL,
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
  k  VARCHAR(60) PRIMARY KEY,
  v  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(60) NOT NULL,
  action      VARCHAR(60) NOT NULL,
  detail      VARCHAR(500) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL,
  KEY created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
