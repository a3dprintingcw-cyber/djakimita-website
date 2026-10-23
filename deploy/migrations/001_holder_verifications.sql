-- Born-in-Curaçao check, done once per person by Dj'aki mi ta! (not by the shops).
-- New table only: the existing tables used by the app and dashboard are not changed.
CREATE TABLE IF NOT EXISTS holder_verifications (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  client_id    INT NOT NULL,                    -- clients.id (the account that bought the card)
  order_id     INT NOT NULL DEFAULT 0,          -- sale_receits.id of the order it was sent from
  name         VARCHAR(120) NOT NULL,
  name_key     VARCHAR(120) NOT NULL,           -- lowercased name, for matching on renewals
  birthdate    DATE NOT NULL,
  status       TINYINT NOT NULL DEFAULT 0,      -- 0 waiting for review, 1 approved, 2 rejected
  face_file    VARCHAR(80) NULL,                -- selfie, shown to the shop on the green screen
  id_file      VARCHAR(80) NULL,                -- passport photo, DELETED as soon as it is reviewed
  note         VARCHAR(255) NULL,               -- reason when rejected
  submitted_at DATETIME NOT NULL,
  reviewed_at  DATETIME NULL,
  reviewed_by  VARCHAR(60) NULL,
  UNIQUE KEY person (client_id, name_key, birthdate),
  KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
