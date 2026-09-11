-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE llx_banksync_transaction(
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_import INTEGER NOT NULL,
    provider VARCHAR(64) NOT NULL,
    account_number VARCHAR(128) NOT NULL,
    external_transaction_id VARCHAR(191),
    external_entry_id VARCHAR(64) NOT NULL,
    value_date DATE NOT NULL,
    booking_date DATE NOT NULL,
    direction VARCHAR(16) NOT NULL,
    amount DECIMAL(24,8) NOT NULL,
    currency VARCHAR(8) NOT NULL,
    transaction_type VARCHAR(255),
    transaction_code VARCHAR(64),
    counterparty_name VARCHAR(255),
    counterparty_account VARCHAR(128),
    reference TEXT,
    source_line INTEGER DEFAULT 0,
    raw_data MEDIUMTEXT,
    status VARCHAR(32) DEFAULT 'new' NOT NULL,
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP
) ENGINE=innodb;
