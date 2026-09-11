-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_import ADD UNIQUE INDEX uk_banksync_import_source (entity, provider, source_sha256);
ALTER TABLE llx_banksync_import ADD INDEX idx_banksync_import_account (entity, account_number);
ALTER TABLE llx_banksync_import ADD INDEX idx_banksync_import_period (period_start, period_end);
