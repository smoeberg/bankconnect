-- BankConnect: imported bank transactions and reconciliation state.
CREATE TABLE IF NOT EXISTS llx_bankconnect_transaction (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	fk_bank_account integer NOT NULL,
	hash varchar(64) NOT NULL UNIQUE,        -- sha256 of (date, amount, ref, counterparty)
	tx_date date NOT NULL,
	amount double NOT NULL,
	currency varchar(3) NOT NULL DEFAULT 'DKK',
	reference varchar(255),
	counterparty varchar(255),
	cam_file varchar(255),                    -- source camt/CSV file
	state varchar(16) NOT NULL DEFAULT 'unmatched',  -- unmatched|proposed|approved|rejected|posted
	created_at datetime DEFAULT NULL,
	KEY idx_bc_state (state),
	KEY idx_bc_account (fk_bank_account)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_match (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	fk_transaction bigint NOT NULL,
	match_type varchar(16) NOT NULL,          -- rule_reference|rule_amount|rule_date_window|ai
	rule_name varchar(64),
	fk_bankentry bigint,                      -- Dolibarr bank entry candidate
	score double,
	reason text,
	approved_by integer,
	approved_at datetime,
	KEY idx_bc_match_tx (fk_transaction),
	CONSTRAINT fk_bc_match_tx FOREIGN KEY (fk_transaction) REFERENCES llx_bankconnect_transaction(rowid)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_bankconnect_audit (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	datetime_event datetime NOT NULL,
	fk_user integer NOT NULL,
	event_type varchar(32) NOT NULL,          -- import|match_proposed|match_approved|match_rejected|posted
	detail text,
	KEY idx_bc_audit_user (fk_user)
) ENGINE=innodb;
