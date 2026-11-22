-- Partner visits: staff go to each partner and check the QR code, the staff knowledge and so on.
-- Also a role per staff account: 'admin' sees everything, 'inspector' only sees partner visits.

ALTER TABLE admin_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER name;

CREATE TABLE IF NOT EXISTS partner_visits (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  company_id  INT NOT NULL,
  planned_on  DATE NULL,
  status      TINYINT(1) NOT NULL DEFAULT 0,
  questions   TEXT NOT NULL,
  notes       TEXT NOT NULL,
  done_at     DATETIME NULL,
  done_by     VARCHAR(60) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL,
  created_by  VARCHAR(60) NOT NULL DEFAULT '',
  updated_at  DATETIME NOT NULL,
  KEY company (company_id),
  KEY planned (planned_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_visit_photos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  visit_id    INT NOT NULL,
  data        MEDIUMBLOB NOT NULL,
  type        VARCHAR(30) NOT NULL DEFAULT 'image/jpeg',
  created_at  DATETIME NOT NULL,
  KEY visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
