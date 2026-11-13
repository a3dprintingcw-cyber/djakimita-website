-- Card lengths (10 days to 1 year) with their own price per card type.
-- Prices agreed in November 2026. USD follows the guilder price (XCG / 1.79).
CREATE TABLE IF NOT EXISTS card_lengths (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,             -- website_product_prices.product_id (1 kids, 2 teen, 3 adult, 4 family, 5 senior)
  code        VARCHAR(8) NOT NULL,      -- 10d, 1m, 3m, 6m, 12m
  days        INT NULL,                 -- set for day passes
  months      INT NULL,                 -- set for month cards
  eur         DECIMAL(10,2) NOT NULL,
  usd         DECIMAL(10,2) NOT NULL,
  xcg         DECIMAL(10,2) NOT NULL,   -- shown in the app for Curaçao
  sort        INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY one_code (product_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which length was bought, per order line (kept apart so the old site's tables stay untouched).
CREATE TABLE IF NOT EXISTS sale_lengths (
  sale_id    INT PRIMARY KEY,             -- sale_receits.id
  length_id  INT NOT NULL                 -- card_lengths.id
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE: migrations run on every deploy, so later price changes in the database are kept.
INSERT IGNORE INTO card_lengths (product_id, code, days, months, eur, usd, xcg, sort) VALUES
  -- Kids
  (1, '10d', 10, NULL,  19,  22,  39, 1),
  (1, '1m',  NULL, 1,   25,  27,  49, 2),
  (1, '3m',  NULL, 3,   39,  44,  79, 3),
  (1, '6m',  NULL, 6,   49,  55,  99, 4),
  (1, '12m', NULL, 12,  59,  66, 119, 5),
  -- Teen
  (2, '10d', 10, NULL,  29,  33,  59, 1),
  (2, '1m',  NULL, 1,   39,  44,  79, 2),
  (2, '3m',  NULL, 3,   55,  61, 109, 3),
  (2, '6m',  NULL, 6,   69,  78, 139, 4),
  (2, '12m', NULL, 12,  89, 100, 179, 5),
  -- Adult
  (3, '10d', 10, NULL,  49,  55,  99, 1),
  (3, '1m',  NULL, 1,   65,  75, 135, 2),
  (3, '3m',  NULL, 3,   95, 111, 199, 3),
  (3, '6m',  NULL, 6,  119, 139, 249, 4),
  (3, '12m', NULL, 12, 150, 167, 299, 5),
  -- Family (up to 5 people)
  (4, '10d', 10, NULL, 119, 134, 239, 1),
  (4, '1m',  NULL, 1,  159, 178, 319, 2),
  (4, '3m',  NULL, 3,  239, 268, 479, 3),
  (4, '6m',  NULL, 6,  299, 335, 599, 4),
  (4, '12m', NULL, 12, 375, 418, 749, 5),
  -- Senior (60+)
  (5, '10d', 10, NULL,  25,  27,  49, 1),
  (5, '1m',  NULL, 1,   35,  39,  69, 2),
  (5, '3m',  NULL, 3,   49,  55,  99, 3),
  (5, '6m',  NULL, 6,   59,  66, 119, 4),
  (5, '12m', NULL, 12,  75,  83, 149, 5);
