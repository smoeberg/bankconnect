-- BankConnect payment / certificate tables
-- Run after llx_bankconnect_transaction.sql

CREATE TABLE IF NOT EXISTS llx_bankconnect_agreement (
    rowid                       INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                      INTEGER DEFAULT 1 NOT NULL,
    label                       VARCHAR(128),
    bank_connect_id             VARCHAR(35) NOT NULL,
    main_registration_number    VARCHAR(20),
    datacenter                  VARCHAR(20),
    endpoint                    VARCHAR(255),
    status                      VARCHAR(20) DEFAULT 'draft',
    date_activation             DATETIME,
    last_connection_test        DATETIME,
    last_connection_status      VARCHAR(16),
    last_connection_error       VARCHAR(255),
    date_creation               DATETIME,
    tms                         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat               INTEGER,
    fk_user_modif               INTEGER,
    UNIQUE KEY uk_bc_agreement_identity (entity, bank_connect_id)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_certificate (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_agreement        INTEGER NOT NULL,
    certificate_pem     TEXT NOT NULL,
    private_key_enc     TEXT NOT NULL,
    valid_from          DATETIME,
    valid_to            DATETIME,
    is_active           TINYINT DEFAULT 1,
    date_creation       DATETIME,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bc_cert_agreement (fk_agreement),
    INDEX idx_bc_cert_active (fk_agreement, is_active)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_batch (
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_agreement            INTEGER NOT NULL,
    end_to_end_message_id   VARCHAR(35) NOT NULL,
    correlation_id          VARCHAR(64),
    msg_id                  VARCHAR(35),
    status                  VARCHAR(20) DEFAULT 'draft',
    pain001_xml             MEDIUMTEXT,
    response_code           VARCHAR(20),
    message                 TEXT,
    control_sum             DOUBLE(24,8),
    nb_of_txs               INTEGER,
    date_sent               DATETIME,
    date_status             DATETIME,
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc_batch_e2e (entity, end_to_end_message_id),
    INDEX idx_bc_batch_agreement (fk_agreement),
    INDEX idx_bc_batch_status (entity, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_batch_line (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_batch            INTEGER NOT NULL,
    end_to_end_id       VARCHAR(35),
    amount              DOUBLE(24,8),
    currency            VARCHAR(3) DEFAULT 'DKK',
    fk_facture_fourn    INTEGER,
    fk_facture          INTEGER,
    fk_paiement         INTEGER,
    status              VARCHAR(20) DEFAULT 'draft',
    pain002_status      VARCHAR(10),
    status_reason       VARCHAR(255),
    requires_manual_review TINYINT NOT NULL DEFAULT 0,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc_batchline_e2e (fk_batch, end_to_end_id),
    INDEX idx_bc_batchline_batch (fk_batch),
    INDEX idx_bc_batchline_status (fk_batch, status)
) ENGINE=innodb;

-- Sync status columns (task: show last sync, next run, imported count, errors)
ALTER TABLE llx_bankconnect_agreement
    ADD COLUMN last_sync_at datetime NULL AFTER status,
    ADD COLUMN last_sync_summary varchar(255) NULL AFTER last_sync_at,
    ADD COLUMN last_sync_error text NULL AFTER last_sync_summary;

-- Import cursor (task 23: date/cursor management + controlled re-fetch).
-- NULL = fetch everything available; otherwise fetch only data changed after
-- this timestamp. Only advanced after a fully successful agreement run.
ALTER TABLE llx_bankconnect_agreement
    ADD COLUMN import_cursor datetime NULL AFTER last_sync_error;
