<?php
/**
 * Append-only audit boundary for security and financial lifecycle events.
 *
 * Detail is deliberately metadata-only. Payloads, secrets and customer text
 * are not accepted.
 */
class BankConnectAudit
{
    public const EVENTS = [
        'agreement_created',
        'certificate_imported',
        'certificate_rotated',
        'certificate_deleted',
        'statement_imported',
        'transaction_matched',
        'match_rejected',
        'match_force_approved',
        'payment_created',
        'payment_submitted',
        'payment_status_changed',
        'payment_unknown',
        'payment_manually_resolved',
        'payment_posted',
        'configuration_changed',
        'response_signature_invalid',
        'certificate_health_warning',
        'certificate_health_critical',
        'bankconnect_failure',
        'camt_import_failed',
    ];

    private $db;

    public function __construct($db) { $this->db = $db; }

    public function record(string $event, int $userId = 0, array $metadata = []): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new BankConnectException('Unsupported BankConnect audit event: '.$event);
        }

        $safe = [];
        foreach ($metadata as $key => $value) {
            if ($this->isSensitiveKey((string)$key)) continue;
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        $detail = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($detail === false || strlen($detail) > 2000) {
            throw new BankConnectException('Invalid or oversized audit metadata');
        }

        $sql = "INSERT INTO llx_bankconnect_audit (datetime_event, fk_user, event_type, detail)
                VALUES (NOW(), ".(int)$userId.", '".$this->db->escape($event)."',
                '".$this->db->escape($detail)."')";
        if (!$this->db->query($sql)) {
            throw new BankConnectException('BankConnect audit write failed: '.$this->db->lasterror());
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (['password','secret','token','key','private','certificate','payload','authorization','text','name','reference'] as $needle) {
            if (str_contains($key, $needle)) return true;
        }
        return false;
    }
}
