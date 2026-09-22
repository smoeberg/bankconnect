-- BankConnect: imported bank transactions and reconciliation state.
CREATE TABLE IF NOT EXISTS llx_bankconnect_transaction (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	fk_bank_account integer NOT NULL,
	hash varchar(64) NOT NULL UNIQUE,
	statement_id varchar(255) NOT NULL DEFAULT '',
	transaction_id varchar(255) NOT NULL DEFAULT '',
	tx_date date NOT NULL,
	amount double NOT NULL,
	currency varchar(3) NOT NULL DEFAULT 'DKK',
	reference varchar(255),
	counterparty varchar(255),
	acct_svcr_ref varchar(255),
	is_reversal integer NOT NULL DEFAULT 0,
	requires_manual_review integer NOT NULL DEFAULT 0,
	fk_bankentry bigint DEFAULT NULL,
	bank_entry_state varchar(16) NOT NULL DEFAULT 'pending',
	bank_entry_error varchar(255) DEFAULT NULL,
	cam_file varchar(255),
	state varchar(16) NOT NULL DEFAULT 'unmatched',
	created_at datetime DEFAULT NULL,
	KEY idx_bc_state (state),
	KEY idx_bc_account (fk_bank_account),
	KEY idx_bc_bankentry_state (bank_entry_state),
	UNIQUE KEY uk_bc_transaction_bankentry (fk_bankentry),
	UNIQUE KEY uk_bc_statement_transaction (fk_bank_account, statement_id, transaction_id),
	KEY idx_bc_manual_review (requires_manual_review)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_match (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	fk_transaction bigint NOT NULL,
	match_type varchar(16) NOT NULL,
	rule_name varchar(64),
	fk_bankentry bigint,
	score double,
	reason text,
	approved_by integer,
	approved_at datetime,
	KEY idx_bc_match_tx (fk_transaction),
	UNIQUE KEY uk_bc_match_bankentry (fk_bankentry),
	CONSTRAINT fk_bc_match_tx FOREIGN KEY (fk_transaction) REFERENCES llx_bankconnect_transaction(rowid)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_match_candidate (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	fk_match bigint NOT NULL,
	candidate_id varchar(128) NOT NULL,
	candidate_type varchar(32) NOT NULL,
	candidate_ref varchar(255) NULL,
	amount double NOT NULL DEFAULT 0,
	selected integer NOT NULL DEFAULT 0,
	created_at datetime DEFAULT NULL,
	KEY idx_bc_match_candidate_match (fk_match),
	KEY idx_bc_match_candidate_selected (fk_match, selected),
	UNIQUE KEY uk_bc_match_candidate (fk_match, candidate_id, candidate_type),
	CONSTRAINT fk_bc_match_candidate_match FOREIGN KEY (fk_match) REFERENCES llx_bankconnect_match(rowid)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_audit (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	datetime_event datetime NOT NULL,
	fk_user integer NOT NULL,
	event_type varchar(32) NOT NULL,
	detail text,
	KEY idx_bc_audit_user (fk_user)
) ENGINE=innodb;
