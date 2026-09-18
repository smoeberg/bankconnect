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
}
