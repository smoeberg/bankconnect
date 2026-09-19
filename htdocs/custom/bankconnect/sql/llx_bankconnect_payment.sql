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
    date_creation               DATETIME,
    tms                         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat               INTEGER,
    fk_user_modif               INTEGER
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
    INDEX idx_bc_cert_agreement (fk_agreement)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_batch (
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_agreement            INTEGER NOT NULL,
    end_to_end_message_id   VARCHAR(35) NOT NULL,
    correlation_id          VARCHAR(64),
    msg_id                  VARCHAR(35),
    status                  VARCHAR(20) DEFAULT 'sent',
    pain001_xml             MEDIUMTEXT,
    response_code           VARCHAR(20),
    message                 TEXT,
    control_sum             DOUBLE(24,8),
    nb_of_txs               INTEGER,
    date_sent               DATETIME,
    date_status             DATETIME,
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc_batch_e2e (entity, end_to_end_message_id),
    INDEX idx_bc_batch_agreement (fk_agreement)
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
    status              VARCHAR(20) DEFAULT 'sent',
    pain002_status      VARCHAR(10),
    status_reason       VARCHAR(255),
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bc_batchline_batch (fk_batch)
) ENGINE=innodb;
