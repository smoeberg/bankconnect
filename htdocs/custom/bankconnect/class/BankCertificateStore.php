<?php
/**
 * BankCertificateStore - Persistence and management of BankConnect bank certificates.
 *
 * Bank certificates are used for encrypting requests to BankConnect.
 * Each datacenter (BANKDATA, NBS, BEC) has its own certificate.
 * Certificates can be fetched automatically via getBankCertificate or manually configured.
 */

require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectDatabasePrefix.php';

class BankCertificateStore
{
    use BankConnectDatabasePrefix;

    private $db;
    private int $entity;

    public function __construct($db, ?int $entity = null, ?string $prefix = null)
    {
        $this->db = $db;
        $this->entity = max(1, $entity ?? (int)($db->entity ?? 1));
        $this->initializeDatabasePrefix($prefix);
    }

    /**
     * Save or update a bank certificate for a specific datacenter.
     *
     * @param array{
     *   datacenter:string, certificate_pem:string, valid_from?:?string, valid_to?:?string,
     *   fingerprint_sha256?:string, environment?:string
     * } $data
     */
    public function saveBankCertificate(array $data): int
    {
        $datacenter = $this->db->escape(strtoupper($data['datacenter'] ?? 'BANKDATA'));
        $pem = $this->db->escape($data['certificate_pem']);
        $environment = $this->db->escape($data['environment'] ?? 'test');
        $fingerprint = isset($data['fingerprint_sha256']) 
            ? "'".$this->db->escape($data['fingerprint_sha256'])."'" : 'NULL';
        $from = isset($data['valid_from']) && $data['valid_from'] !== null
            ? "'".$this->db->escape($data['valid_from'])."'" : 'NULL';
        $to = isset($data['valid_to']) && $data['valid_to'] !== null
            ? "'".$this->db->escape($data['valid_to'])."'" : 'NULL';

        // Check if certificate already exists for this datacenter+environment
        $existing = $this->getBankCertificate($datacenter, $environment);
        
        if ($existing !== null) {
            // Update existing
            $sql = "UPDATE llx_bankconnect_bank_certificate SET"
                 . " certificate_pem = '$pem',"
                 . " fingerprint_sha256 = $fingerprint,"
                 . " valid_from = $from,"
                 . " valid_to = $to,"
                 . " date_updated = NOW()"
                 . " WHERE datacenter = '$datacenter' AND environment = '$environment' AND entity = ".$this->entity;
            
            if (!$this->prefixQuery($sql)) {
                throw new BankConnectException('Update bank certificate failed: '.$this->db->lasterror());
            }
            return (int)$existing['rowid'];
        }

        // Insert new
        $sql = "INSERT INTO llx_bankconnect_bank_certificate"
             . " (entity, datacenter, environment, certificate_pem, fingerprint_sha256, valid_from, valid_to, date_creation)"
             . " VALUES ("
             . $this->entity.", '$datacenter', '$environment', '$pem', $fingerprint, $from, $to, NOW())";

        if (!$this->prefixQuery($sql)) {
            throw new BankConnectException('Save bank certificate failed: '.$this->db->lasterror());
        }
        return (int) $this->prefixLastInsertId('llx_bankconnect_bank_certificate');
    }

    /**
     * Get a bank certificate by datacenter and environment.
     *
     * @return ?array{rowid:int, entity:int, datacenter:string, environment:string, certificate_pem:string, ...}
     */
    public function getBankCertificate(string $datacenter, string $environment = 'test'): ?array
    {
        $datacenter = $this->db->escape(strtoupper($datacenter));
        $environment = $this->db->escape($environment);
        
        $sql = "SELECT * FROM llx_bankconnect_bank_certificate"
             . " WHERE datacenter = '$datacenter' AND environment = '$environment' AND entity = ".$this->entity
             . " ORDER BY rowid DESC LIMIT 1";
        
        $res = $this->prefixQuery($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (array) $obj : null;
    }

    /**
     * Get active bank certificate for a datacenter (prefers production, falls back to test).
     *
     * @return ?array{rowid:int, entity:int, datacenter:string, environment:string, certificate_pem:string, ...}
     */
    public function getActiveBankCertificate(string $datacenter): ?array
    {
        $datacenter = $this->db->escape(strtoupper($datacenter));
        
        // Try production first
        $cert = $this->getBankCertificate($datacenter, 'production');
        if ($cert !== null) {
            return $cert;
        }
        
        // Fall back to test
        return $this->getBankCertificate($datacenter, 'test');
    }

    /**
     * List all bank certificates.
     *
     * @return list<array>
     */
    public function listBankCertificates(int $entity = null): array
    {
        $entity = $entity ?? $this->entity;
        $sql = "SELECT * FROM llx_bankconnect_bank_certificate WHERE entity = ".(int)$entity." ORDER BY datacenter, environment, rowid DESC";
        $res = $this->prefixQuery($sql);
        $out = [];
        while ($res && ($obj = $this->db->fetch_object($res))) {
            $out[] = (array) $obj;
        }
        return $out;
    }

    /**
     * Delete a bank certificate.
     */
    public function deleteBankCertificate(int $id): void
    {
        $sql = "DELETE FROM llx_bankconnect_bank_certificate WHERE rowid = ".(int)$id;
        if (!$this->prefixQuery($sql)) {
            throw new BankConnectException('Delete bank certificate failed: '.$this->db->lasterror());
        }
    }

    /**
     * Validate and extract certificate metadata.
     *
     * @return array{fingerprint_sha256:string, valid_from:?string, valid_to:?string}
     */
    public function validateCertificatePem(string $certificatePem): array
    {
        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new BankConnectException('Invalid X.509 certificate');
        }
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw new BankConnectException('Unable to parse X.509 certificate');
        }
        
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if ($fingerprint === false) {
            throw new BankConnectException('Unable to compute certificate fingerprint');
        }
        
        $validFrom = isset($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', $parsed['validFrom_time_t']) : null;
        $validTo = isset($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', $parsed['validTo_time_t']) : null;
        
        return [
            'fingerprint_sha256' => $fingerprint,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }

    /**
     * Check if certificate is currently valid.
     */
    public function isCertificateValid(string $certificatePem, ?int $now = null): bool
    {
        $meta = $this->validateCertificatePem($certificatePem);
        $now = $now ?? time();
        
        if ($meta['valid_from'] === null || $meta['valid_to'] === null) {
            return false;
        }
        
        $from = strtotime($meta['valid_from']);
        $to = strtotime($meta['valid_to']);
        
        return $from !== false && $to !== false && $from <= $now && $now <= $to;
    }
}
