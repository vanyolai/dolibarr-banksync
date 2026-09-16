CREATE TABLE llx_banksync_posting_item (
  rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
  entity INTEGER DEFAULT 1 NOT NULL,
  fk_posting INTEGER NOT NULL,
  target_type VARCHAR(64),
  target_id INTEGER,
  native_object_type VARCHAR(64) NOT NULL,
  native_object_id INTEGER NOT NULL,
  fk_bank INTEGER,
  amount DECIMAL(24,8) NOT NULL,
  date_creation DATETIME NOT NULL
) ENGINE=innodb;
