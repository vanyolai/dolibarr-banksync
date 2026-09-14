-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE llx_banksync_posting (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_transaction INTEGER NOT NULL,
    posting_kind VARCHAR(64) NOT NULL,
    native_object_type VARCHAR(64),
    native_object_id INTEGER,
    fk_bank INTEGER,
    status VARCHAR(32) DEFAULT 'processing' NOT NULL,
    error_message TEXT,
    date_creation DATETIME NOT NULL,
    date_posted DATETIME,
    fk_user_create INTEGER,
    fk_user_post INTEGER,
    tms TIMESTAMP
) ENGINE=innodb;
