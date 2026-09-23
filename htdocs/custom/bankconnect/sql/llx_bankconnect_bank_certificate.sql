-- BankConnect: bank certificates for encryption (one per datacenter: BANKDATA, NBS, BEC)
CREATE TABLE IF NOT EXISTS llx_bankconnect_bank_certificate (
	rowid bigint AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL DEFAULT 1,
	datacenter varchar(16) NOT NULL,
	environment varchar(16) NOT NULL DEFAULT 'test',
	certificate_pem text NOT NULL,
	fingerprint_sha256 varchar(64) NOT NULL,
	valid_from datetime DEFAULT NULL,
	valid_to datetime DEFAULT NULL,
	date_creation datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	date_updated datetime DEFAULT NULL,
	KEY idx_bc_bankcert_datacenter (datacenter),
	KEY idx_bc_bankcert_env (environment),
	UNIQUE KEY uk_bc_bankcert_datacenter_env (entity, datacenter, environment)
) ENGINE=innodb;
