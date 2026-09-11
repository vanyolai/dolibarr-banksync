-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE llx_banksync_account(
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    provider VARCHAR(64) NOT NULL,
    source_account_key VARCHAR(191) NOT NULL,
    source_account_number VARCHAR(128) NOT NULL,
    conversion_account_number VARCHAR(128),
    source_account_label VARCHAR(255),
    currency VARCHAR(8) NOT NULL,
    fk_bank_account INTEGER,
    suggested_fk_bank_account INTEGER,
    mapping_status VARCHAR(32) DEFAULT 'unmapped' NOT NULL,
    mapping_method VARCHAR(64),
    date_creation DATETIME NOT NULL,
    fk_user_create INTEGER,
    fk_user_modif INTEGER,
    tms TIMESTAMP
) ENGINE=innodb;
