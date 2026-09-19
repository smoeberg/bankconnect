-- BankConnect: imported bank transactions and reconciliation state.
CREATE TABLE IF NOT EXISTS llx_bankconnect_transaction (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	fk_bank_account integer NOT NULL,
	hash varchar(64) NOT NULL UNIQUE,
	tx_date date NOT NULL,
	amount double NOT NULL,
	currency varchar(3) NOT NULL DEFAULT 'DKK',
	reference varchar(255),
	counterparty varchar(255),
	acct_svcr_ref varchar(255),
	is_reversal integer NOT NULL DEFAULT 0,
	requires_manual_review integer NOT NULL DEFAULT 0,
	cam_file varchar(255),
	state varchar(16) NOT NULL DEFAULT 'unmatched',
	created_at datetime DEFAULT NULL,
	KEY idx_bc_state (state),
	KEY idx_bc_account (fk_bank_account),
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

CREATE TABLE IF NOT EXISTS llx_bankconnect_audit (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	datetime_event datetime NOT NULL,
	fk_user integer NOT NULL,
	event_type varchar(32) NOT NULL,
	detail text,
	KEY idx_bc_audit_user (fk_user)
) ENGINE=innodb;
