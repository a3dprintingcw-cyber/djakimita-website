-- Discount codes, partner videos, spin the wheel. New tables only.

CREATE TABLE IF NOT EXISTS discount_codes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  percent     DECIMAL(5,2) NOT NULL,
  valid_until DATE NULL,
  max_uses    INT NULL,
  used        INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_discounts (
  sale_id     INT PRIMARY KEY,
  code_id     INT NOT NULL,
  code        VARCHAR(40) NOT NULL,
  percent     DECIMAL(5,2) NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  counted     TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_extra (
  company_id  INT PRIMARY KEY,
  video_url   VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wheel_prizes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  label       VARCHAR(120) NOT NULL,
  chance      INT NOT NULL DEFAULT 10,
  is_prize    TINYINT(1) NOT NULL DEFAULT 1,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  sort        INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wheel_spins (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  client_id   INT NOT NULL,
  prize_id    INT NOT NULL,
  label       VARCHAR(120) NOT NULL,
  is_prize    TINYINT(1) NOT NULL DEFAULT 0,
  given       TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
