<?php
// Dolibarr-independent bootstrap for unit tests.
if (!function_exists('dol_syslog')) {
    function dol_syslog($msg, $level = 7): void
    {
        // no-op in tests
    }
}

// Unit tests use an ephemeral process-local secret; production secrets remain environment-only.
if (getenv('BANKCONNECT_KEY_ENCRYPTION_SECRET') === false) {
    putenv('BANKCONNECT_KEY_ENCRYPTION_SECRET='.bin2hex(random_bytes(32)));
}
