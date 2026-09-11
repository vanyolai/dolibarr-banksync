-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_account ADD UNIQUE INDEX uk_banksync_account_source (entity, provider, source_account_key, currency);
ALTER TABLE llx_banksync_account ADD INDEX idx_banksync_account_bank (fk_bank_account);
ALTER TABLE llx_banksync_account ADD INDEX idx_banksync_account_suggested_bank (suggested_fk_bank_account);
