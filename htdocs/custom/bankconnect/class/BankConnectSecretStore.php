<?php
/**
 * Production secret boundary.
 *
 * Security-sensitive values are resolved from environment variables. Dolibarr
 * configuration may contain non-secret metadata, but secrets are never written
 * to configuration by this class.
 */
class BankConnectSecretStore
{
    private $conf;

    public function __construct($conf = null)
    {
        $this->conf = $conf;
    }

    public function get(string $name, bool $required = true): ?string
    {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return (string)$value;
        }

        if ($required) {
            throw new BankConnectException(
                $name.' is not configured. Provide it through the process environment; '
                .'secrets must not be stored in source code, logs or Dolibarr constants.'
            );
        }
        return null;
    }

    public function has(string $name): bool
    {
        return $this->get($name, false) !== null;
    }

    public function requireMinLength(string $name, int $minBytes): string
    {
        $value = $this->get($name);
        if (strlen($value) < $minBytes) {
            throw new BankConnectException($name.' must contain at least '.$minBytes.' bytes');
        }
        return $value;
    }
}
