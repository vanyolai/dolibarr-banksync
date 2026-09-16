ALTER TABLE llx_banksync_vat_account_map ADD UNIQUE INDEX uk_banksync_vat_account (entity, target_account_number);
