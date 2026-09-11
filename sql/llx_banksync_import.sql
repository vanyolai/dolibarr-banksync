-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE llx_banksync_import(
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    provider VARCHAR(64) NOT NULL,
    source_filename VARCHAR(255) NOT NULL,
    source_sha256 VARCHAR(64) NOT NULL,
    account_number VARCHAR(128) NOT NULL,
    conversion_account_number VARCHAR(128),
    currency VARCHAR(8) NOT NULL,
    period_start DATE,
    period_end DATE,
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP,
    fk_user_create INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    transaction_count INTEGER DEFAULT 0 NOT NULL,
    imported_count INTEGER DEFAULT 0 NOT NULL,
    skipped_count INTEGER DEFAULT 0 NOT NULL
) ENGINE=innodb;
