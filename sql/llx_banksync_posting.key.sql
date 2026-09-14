-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_posting ADD UNIQUE INDEX uk_banksync_posting_transaction (entity, fk_transaction);
ALTER TABLE llx_banksync_posting ADD INDEX idx_banksync_posting_native (native_object_type, native_object_id);
ALTER TABLE llx_banksync_posting ADD INDEX idx_banksync_posting_bank (fk_bank);
