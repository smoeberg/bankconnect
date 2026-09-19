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
        $fk = (int) $data['fk_agreement'];
        $requestedActive = (int) ($data['is_active'] ?? 1) === 1;

        $pem = $this->db->escape($data['certificate_pem']);
        $keyEnc = $this->db->escape($data['private_key_enc']);
        $from = isset($data['valid_from']) && $data['valid_from'] !== null
            ? "'".$this->db->escape($data['valid_from'])."'" : 'NULL';
        $to = isset($data['valid_to']) && $data['valid_to'] !== null
            ? "'".$this->db->escape($data['valid_to'])."'" : 'NULL';

        // Always insert first. A failed insert must never destroy the currently
        // active certificate. Activation is then switched atomically.
        if (!$this->db->begin()) {
            throw new BankConnectException('saveCertificate could not start transaction: '.$this->db->lasterror());
        }

        try {
            $sql = "INSERT INTO llx_bankconnect_certificate"
                 . " (fk_agreement, certificate_pem, private_key_enc, valid_from, valid_to, is_active, date_creation)"
                 . " VALUES ($fk, '$pem', '$keyEnc', $from, $to, 0, NOW())";

            if (!$this->db->query($sql)) {
                throw new BankConnectException('saveCertificate insert failed: '.$this->db->lasterror());
            }

            $certificateId = (int) $this->db->last_insert_id('llx_bankconnect_certificate');

            if ($requestedActive) {
                $deactivateSql = "UPDATE llx_bankconnect_certificate"
                    . " SET is_active = 0"
                    . " WHERE fk_agreement = $fk AND is_active = 1";
                if (!$this->db->query($deactivateSql)) {
                    throw new BankConnectException('saveCertificate deactivation failed: '.$this->db->lasterror());
                }

                $activateSql = "UPDATE llx_bankconnect_certificate"
                    . " SET is_active = 1"
                    . " WHERE rowid = $certificateId AND fk_agreement = $fk";
                if (!$this->db->query($activateSql)) {
                    throw new BankConnectException('saveCertificate activation failed: '.$this->db->lasterror());
                }
            }

            if (!$this->db->commit()) {
                throw new BankConnectException('saveCertificate commit failed: '.$this->db->lasterror());
            }

            return $certificateId;
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof BankConnectException) {
                throw $e;
            }
            throw new BankConnectException('saveCertificate failed: '.$e->getMessage());
        }
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
