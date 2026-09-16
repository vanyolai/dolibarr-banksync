CREATE TABLE llx_banksync_vat_account_map (
  rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
  entity INTEGER DEFAULT 1 NOT NULL,
  target_account_number VARCHAR(128) NOT NULL,
  label VARCHAR(255),
  active INTEGER DEFAULT 1 NOT NULL,
  date_creation DATETIME NOT NULL,
  fk_user_create INTEGER,
  fk_user_modif INTEGER,
  tms TIMESTAMP
) ENGINE=innodb;
