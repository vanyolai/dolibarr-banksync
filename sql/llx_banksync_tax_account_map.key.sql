ALTER TABLE llx_banksync_tax_account_map ADD UNIQUE INDEX uk_banksync_tax_account (entity, target_account_number);
ALTER TABLE llx_banksync_tax_account_map ADD INDEX idx_banksync_tax_charge_type (fk_charge_type);
