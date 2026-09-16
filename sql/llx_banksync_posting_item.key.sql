ALTER TABLE llx_banksync_posting_item ADD INDEX idx_banksync_posting_item_posting (fk_posting);
ALTER TABLE llx_banksync_posting_item ADD INDEX idx_banksync_posting_item_native (native_object_type, native_object_id);
