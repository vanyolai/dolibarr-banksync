-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_transaction ADD UNIQUE INDEX uk_banksync_transaction_entry (entity, provider, account_number, external_entry_id);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_import (fk_import);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_external_tx (external_transaction_id);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_booking (entity, booking_date);
ALTER TABLE llx_banksync_transaction ADD INDEX idx_banksync_transaction_status (entity, status);
ALTER TABLE llx_banksync_transaction ADD CONSTRAINT fk_banksync_transaction_import FOREIGN KEY (fk_import) REFERENCES llx_banksync_import(rowid);
