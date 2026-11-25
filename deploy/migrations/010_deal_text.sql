-- Deals that are not a % (for example "Buy 1, get 1 free"), one text per partner
ALTER TABLE partner_extra ADD COLUMN IF NOT EXISTS deal_text VARCHAR(160) NOT NULL DEFAULT '';
