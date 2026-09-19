<?php
/**
 * Persistence for BankConnect agreements and certificates.
 */

require_once __DIR__.'/BankConnectException.php';

class AgreementStore
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param array{
     *   entity?:int, label?:string, bank_connect_id:string,
     *   main_registration_number?:string, datacenter?:string,
     *   endpoint?:string, status?:string, fk_user_creat?:int
     * } $data
     */
    public function createAgreement(array $data): int
    {
        $entity = (int) ($data['entity'] ?? 1);
        $label = $this->db->escape($data['label'] ?? '');
        $bcId = $this->db->escape($data['bank_connect_id']);
        $reg = $this->db->escape($data['main_registration_number'] ?? '');
        $dc = $this->db->escape($data['datacenter'] ?? '');
        $ep = $this->db->escape($data['endpoint'] ?? '');
        $status = $this->db->escape($data['status'] ?? 'draft');
        $uid = (int) ($data['fk_user_creat'] ?? 0);

        $sql = "INSERT INTO llx_bankconnect_agreement"
             . " (entity, label, bank_connect_id, main_registration_number, datacenter, endpoint, status, date_creation, fk_user_creat)"
             . " VALUES ($entity, '$label', '$bcId', '$reg', '$dc', '$ep', '$status', NOW(), $uid)";

        if (!$this->db->query($sql)) {
            throw new BankConnectException('createAgreement failed: '.$this->db->lasterror());
        }
        return (int) $this->db->last_insert_id('llx_bankconnect_agreement');
    }

    public function updateAgreementStatus(int $agreementId, string $status, ?string $dateActivation = null): void
    {
        $sets = ["status = '".$this->db->escape($status)."'"];
        if ($dateActivation !== null) {
            $sets[] = "date_activation = '".$this->db->escape($dateActivation)."'";
        }
        $sql = "UPDATE llx_bankconnect_agreement SET ".implode(', ', $sets)
             . " WHERE rowid = ".(int) $agreementId;
        if (!$this->db->query($sql)) {
            throw new BankConnectException('updateAgreementStatus failed: '.$this->db->lasterror());
        }
    }

    /**
     * @param array{
     *   fk_agreement:int, certificate_pem:string, private_key_enc:string,
     *   valid_from?:?string, valid_to?:?string, is_active?:int
     * } $data
     */
    public function saveCertificate(array $data): int
    {
        // Deactivate previous active certs for this agreement
        $fk = (int) $data['fk_agreement'];
        $this->db->query(
            "UPDATE llx_bankconnect_certificate SET is_active = 0 WHERE fk_agreement = $fk AND is_active = 1"
        );

        $pem = $this->db->escape($data['certificate_pem']);
        $keyEnc = $this->db->escape($data['private_key_enc']);
        $from = isset($data['valid_from']) && $data['valid_from'] !== null
            ? "'".$this->db->escape($data['valid_from'])."'" : 'NULL';
        $to = isset($data['valid_to']) && $data['valid_to'] !== null
            ? "'".$this->db->escape($data['valid_to'])."'" : 'NULL';
        $active = (int) ($data['is_active'] ?? 1);

        $sql = "INSERT INTO llx_bankconnect_certificate"
             . " (fk_agreement, certificate_pem, private_key_enc, valid_from, valid_to, is_active, date_creation)"
             . " VALUES ($fk, '$pem', '$keyEnc', $from, $to, $active, NOW())";

        if (!$this->db->query($sql)) {
            throw new BankConnectException('saveCertificate failed: '.$this->db->lasterror());
        }
        return (int) $this->db->last_insert_id('llx_bankconnect_certificate');
    }

    public function getAgreement(int $id): ?array
    {
        $sql = "SELECT * FROM llx_bankconnect_agreement WHERE rowid = ".(int) $id;
        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (array) $obj : null;
    }

    public function getActiveCertificate(int $agreementId): ?array
    {
        $sql = "SELECT * FROM llx_bankconnect_certificate"
             . " WHERE fk_agreement = ".(int) $agreementId." AND is_active = 1"
             . " ORDER BY rowid DESC LIMIT 1";
        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (array) $obj : null;
    }

    /** @return list<array> */
    public function listAgreements(int $entity = 1): array
    {
        $sql = "SELECT * FROM llx_bankconnect_agreement WHERE entity = ".(int) $entity." ORDER BY rowid DESC";
        $res = $this->db->query($sql);
        $out = [];
        while ($res && ($obj = $this->db->fetch_object($res))) {
            $out[] = (array) $obj;
        }
        return $out;
    }
}
