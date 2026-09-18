<?php
// Dolibarr-independent bootstrap for unit tests.
if (!function_exists('dol_syslog')) {
    function dol_syslog($msg, $level = 7): void
    {
        // no-op in tests
    }
}
