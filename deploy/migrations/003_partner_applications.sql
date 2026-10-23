-- Partner applications from /become-a-partner.php (also emailed to contact@djakimita.com).
CREATE TABLE IF NOT EXISTS partner_applications (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  company      VARCHAR(200) NOT NULL,
  reg_number   VARCHAR(120) NOT NULL,
  contact_name VARCHAR(200) NOT NULL,
  email        VARCHAR(200) NOT NULL,
  phone        VARCHAR(80)  NULL,
  website      VARCHAR(300) NULL,
  category     VARCHAR(120) NULL,
  offer        TEXT NOT NULL,
  ip_hash      CHAR(64) NOT NULL,
  status       TINYINT NOT NULL DEFAULT 0,   -- 0 new, 1 handled
  created_at   DATETIME NOT NULL,
  KEY created (created_at),
  KEY ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
