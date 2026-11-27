-- Buy points with money, and buy extra visits at a partner with points
CREATE TABLE IF NOT EXISTS points_orders (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,
  points     INT NOT NULL,
  amount     DECIMAL(10,2) NOT NULL,
  currency   VARCHAR(3) NOT NULL,
  token      VARCHAR(120) NOT NULL DEFAULT '',
  status     TINYINT NOT NULL DEFAULT 0,   -- 0 waiting, 1 paid
  created_at DATETIME NOT NULL,
  paid_at    DATETIME NULL,
  KEY token (token),
  KEY client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS extra_scans (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,
  account_id INT NOT NULL,                 -- clients_package.id
  company_id INT NOT NULL,
  scans      INT NOT NULL,
  points     INT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY acc_company (account_id, company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
