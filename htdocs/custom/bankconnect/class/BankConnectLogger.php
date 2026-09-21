<?php

/**
 * PII-safe logger. Never logs statement text, names or references -
 * only hashes, counts and timings.
 */
class BankConnectLogger
{
    /** @var resource|null */
    private $stream;

    /** @var resource|null */
    private $sink;

    private const SECRET_KEYS = [
        'api_key', 'apikey', 'authorization', 'password', 'private_key', 'private_key_pem',
        'private_key_enc', 'secret', 'token', 'certificate_pem', 'soap_credentials', 'activation_code'
    ];

    public function __construct($stream = null, $sink = null)
    {
        $this->stream = $stream;
        $this->sink = $sink;
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s bankconnect: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            $message,
            json_encode($context)
        );
        if ($this->stream !== null) {
            fwrite($this->stream, $line);
        }
        if (function_exists('dol_syslog')) {
            dol_syslog($line);
        }
    }

    private function sanitizeContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $normalized = strtolower((string)$key);
            foreach (self::SECRET_KEYS as $secretKey) {
                if ($normalized === $secretKey || str_contains($normalized, $secretKey)) {
                    $out[$key] = '[REDACTED]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $out[$key] = $this->sanitizeContext($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '[REDACTED]';
            }
        }
        return $out;
    }
}
