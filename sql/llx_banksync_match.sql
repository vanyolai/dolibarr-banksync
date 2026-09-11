-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE llx_banksync_match(
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_transaction INTEGER NOT NULL,
    target_type VARCHAR(64) NOT NULL,
    target_id INTEGER NOT NULL,
    allocated_amount DECIMAL(24,8) NOT NULL,
    confidence INTEGER DEFAULT 0 NOT NULL,
    match_method VARCHAR(64),
    status VARCHAR(32) DEFAULT 'suggested' NOT NULL,
    note TEXT,
    date_creation DATETIME NOT NULL,
    fk_user_create INTEGER,
    fk_user_modif INTEGER,
    tms TIMESTAMP
) ENGINE=innodb;
