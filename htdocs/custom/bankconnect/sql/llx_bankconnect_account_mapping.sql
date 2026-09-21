-- BankConnect <-> Dolibarr bank account mapping.
-- One active BankConnect agreement is mapped to one Dolibarr bank account
-- per entity. The same bank account may be used by multiple agreements only
-- when explicitly allowed by the unique agreement-side mapping rule.
CREATE TABLE IF NOT EXISTS llx_bankconnect_account_mapping (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity              INTEGER NOT NULL DEFAULT 1,
    fk_agreement        INTEGER NOT NULL,
    fk_bank_account     INTEGER NOT NULL,
    date_creation       DATETIME NOT NULL,
    fk_user_creat       INTEGER,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc_mapping_agreement (entity, fk_agreement),
    UNIQUE KEY uk_bc_mapping_bank_account (entity, fk_bank_account),
    INDEX idx_bc_mapping_agreement (fk_agreement),
    INDEX idx_bc_mapping_bank_account (fk_bank_account)
) ENGINE=innodb;
