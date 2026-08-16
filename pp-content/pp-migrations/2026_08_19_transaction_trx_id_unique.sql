-- Migration: 2026_08_19_transaction_trx_id_unique.sql
-- Transaction unique completed transaction ID index

ALTER TABLE `{PREFIX}transaction`
  ADD COLUMN IF NOT EXISTS `trx_id_unique` varchar(70) GENERATED ALWAYS AS (
    CASE WHEN `status` = 'completed' AND `trx_id` != '' AND `trx_id` != '--' THEN `trx_id` ELSE NULL END
  ) STORED;
