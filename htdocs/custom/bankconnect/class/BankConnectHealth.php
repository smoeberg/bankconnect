<?php
/**
 * Read-only production health checks. No secret values are returned.
 */
require_once __DIR__.'/BankConnectEndpointPolicy.php';
require_once __DIR__.'/BankConnectDatabasePrefix.php';

class BankConnectHealth
{
    use BankConnectDatabasePrefix;

    private $db;
    private $conf;

    public function __construct($db = null, $conf = null, ?string $prefix = null)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->initializeDatabasePrefix($prefix);
    }

    /** @return array{status:string,checks:array<string,array>} */
    public function check(): array
    {
        $checks = [];

        $checks['endpoint'] = $this->endpointCheck();
        $checks['encryption_secret'] = $this->secretCheck();
        $checks['certificates'] = $this->certificateCheck();
        $checks['unknown_payments'] = $this->unknownPaymentCheck();

        $critical = false;
        $warning = false;
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'critical') $critical = true;
            if (($check['status'] ?? '') === 'warning') $warning = true;
        }

        return [
            'status' => $critical ? 'critical' : ($warning ? 'warning' : 'healthy'),
            'checks' => $checks,
        ];
    }

    private function endpointCheck(): array
    {
        $g = is_object($this->conf) ? ($this->conf->global ?? []) : [];
        $endpoint = (string)($g['BANKCONNECT_ENDPOINT'] ?? 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService');
        $environment = (string)($g['BANKCONNECT_ENVIRONMENT'] ?? 'test');
        try {
            BankConnectEndpointPolicy::validateBankConnect($endpoint, $environment);
            return ['status'=>'healthy','message'=>'BankConnect endpoint configuration is valid'];
        } catch (Throwable $e) {
            return ['status'=>'critical','message'=>'BankConnect endpoint configuration is invalid'];
        }
    }

    private function secretCheck(): array
    {
        $store = new BankConnectSecretStore($this->conf);
        if (!$store->has('BANKCONNECT_KEY_ENCRYPTION_SECRET')) {
            return ['status'=>'critical','message'=>'Encryption secret is not configured'];
        }
        try {
            $store->requireMinLength('BANKCONNECT_KEY_ENCRYPTION_SECRET', 32);
            return ['status'=>'healthy','message'=>'Encryption secret is configured'];
        } catch (Throwable $e) {
            return ['status'=>'critical','message'=>'Encryption secret is too weak'];
        }
    }

    private function certificateCheck(): array
    {
        if ($this->db === null) return ['status'=>'warning','message'=>'Certificate check unavailable without database'];
        $sql = 'SELECT valid_to FROM llx_bankconnect_certificate WHERE is_active = 1 ORDER BY valid_to ASC';
        $res = $this->prefixQuery($sql);
        if (!$res) return ['status'=>'warning','message'=>'Certificate health query failed'];
        $now = time(); $warning = false; $found = false;
        while ($o = $this->db->fetch_object($res)) {
            $found = true;
            $to = strtotime((string)$o->valid_to);
            if ($to === false || $to <= $now) return ['status'=>'critical','message'=>'An active certificate is expired or invalid'];
            if ($to <= $now + 30*86400) $warning = true;
        }
        if (!$found) return ['status'=>'critical','message'=>'No active BankConnect certificate'];
        return ['status'=>$warning?'warning':'healthy','message'=>$warning?'A certificate expires within 30 days':'Certificates are valid'];
    }

    private function unknownPaymentCheck(): array
    {
        if ($this->db === null) return ['status'=>'warning','message'=>'Payment check unavailable without database'];
        $res = $this->prefixQuery("SELECT COUNT(*) AS c FROM llx_bankconnect_batch WHERE status = 'unknown'");
        if (!$res) return ['status'=>'warning','message'=>'Payment health query failed'];
        $count = (int)$this->db->fetch_object($res)->c;
        return $count > 0
            ? ['status'=>'critical','message'=>'There are '.$count.' payments requiring UNKNOWN resolution','count'=>$count]
            : ['status'=>'healthy','message'=>'No UNKNOWN payments','count'=>0];
    }
}
