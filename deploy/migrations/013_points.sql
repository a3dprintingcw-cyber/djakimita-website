-- Points: one ledger row per earned or spent amount; (kind, ref) makes sure nothing counts twice
CREATE TABLE IF NOT EXISTS points_ledger (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  client_id    INT NOT NULL,
  points       INT NOT NULL,
  kind         VARCHAR(20) NOT NULL,     -- visit, visit_none, purchase, purchase_back, invite, invited, invite_none, invite_back, invited_back, birthday, redeem, refund, adjust
  ref          VARCHAR(64) NOT NULL,
  label        VARCHAR(150) NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL,
  available_at DATETIME NOT NULL,        -- invite points wait for the refund window
  UNIQUE KEY once (kind, ref),
  KEY client (client_id, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rewards customers can get with points (staff decide the cost)
CREATE TABLE IF NOT EXISTS rewards (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(120) NOT NULL,
  description VARCHAR(400) NOT NULL DEFAULT '',
  partner_id  INT NULL,                  -- NULL: from Dj'aki mi ta! itself
  points      INT NOT NULL,
  country     TINYINT NOT NULL DEFAULT 1,
  icon        VARCHAR(40) NOT NULL DEFAULT 'gift',
  per_month   INT NOT NULL DEFAULT 1,    -- max per customer per 30 days (0 = no limit)
  stock       INT NULL,                  -- NULL: no limit
  active      TINYINT(1) NOT NULL DEFAULT 1,
  sort        INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_claims (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  reward_id    INT NOT NULL,
  client_id    INT NOT NULL,
  code         CHAR(8) NOT NULL,
  points       INT NOT NULL,
  created_at   DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  used_at      DATETIME NULL,
  used_where   VARCHAR(120) NULL,
  cancelled_at DATETIME NULL,
  UNIQUE KEY code (code),
  KEY client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invite a friend: which order used whose invite code
CREATE TABLE IF NOT EXISTS sale_invites (
  sale_id    INT PRIMARY KEY,
  inviter_id INT NOT NULL,
  KEY inviter (inviter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
