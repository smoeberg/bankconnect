<?php
/** Shared resolution of Dolibarr's configurable database table prefix. */
trait BankConnectDatabasePrefix
{
    private string $bankConnectDbPrefix = 'llx_';

    private function initializeDatabasePrefix(?string $prefix = null): void
    {
        $this->bankConnectDbPrefix = $prefix ?? (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_');
    }

    private function prefixQuery(string $sql)
    {
        return $this->db->query(str_replace('llx_', $this->bankConnectDbPrefix, $sql));
    }

    private function prefixLastInsertId(string $table): int
    {
        return (int)$this->db->last_insert_id(str_replace('llx_', $this->bankConnectDbPrefix, $table));
    }
}
