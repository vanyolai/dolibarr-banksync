-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_match ADD UNIQUE INDEX uk_banksync_match_target (fk_transaction, target_type, target_id);
ALTER TABLE llx_banksync_match ADD INDEX idx_banksync_match_transaction (fk_transaction);
ALTER TABLE llx_banksync_match ADD INDEX idx_banksync_match_target (target_type, target_id);
