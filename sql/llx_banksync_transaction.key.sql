-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_transaction ADD UNIQUE INDEX uk_banksync_transaction_entry (entity, provider, account_number, external_entry_id);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_import (fk_import);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_ext_transaction (external_transaction_id);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_booking_date (booking_date);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_status (status);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_source_account (fk_banksync_account);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_event_type (bank_event_type);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_bank_line (fk_bank);
