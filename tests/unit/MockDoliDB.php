<?php
/* Minimal in-memory stand-in for Dolibarr's DoliDB, covering the SQL surface
 * BankConnect uses: SELECT/INSERT/UPDATE with simple WHERE, escape, lasterror,
 * last_insert_id. Intended ONLY for unit tests. */

class MockDoliDB
{
	/** @var array<string, array<int, array>> */
	public $tables = [];
	private $nextId = [];
	private $lastError = '';
	private $transactionSnapshot = null;
	private $failQueryContaining = null;
	private $lastRowCount = 0;
	private $raceInsertTransaction = null;

	public function escape($s)
	{
		return addslashes((string)$s);
	}

	public function lasterror()
	{
		return $this->lastError;
	}

	public function failNext(string $msg): void
	{
		$this->lastError = $msg;
	}

	public function failNextQueryContaining(string $needle, string $msg): void
	{
		$this->failQueryContaining = [$needle, $msg];
	}

	public function begin(): void
	{
		$this->transactionSnapshot = [
			'tables' => $this->tables,
			'nextId' => $this->nextId,
		];
	}

	public function commit(): void
	{
		$this->transactionSnapshot = null;
	}

	public function rollback(): void
	{
		if ($this->transactionSnapshot !== null) {
			$this->tables = $this->transactionSnapshot['tables'];
			$this->nextId = $this->transactionSnapshot['nextId'];
			$this->transactionSnapshot = null;
		}
	}

	public function query($sql)
	{
		$s = trim($sql);
		if ($this->failQueryContaining !== null && strpos($s, $this->failQueryContaining[0]) !== false) {
			$this->lastError = $this->failQueryContaining[1];
			$this->failQueryContaining = null;
			return false;
		}
		if (preg_match('/^SELECT\s+ROW_COUNT\(\)\s+AS\s+(\w+)$/i', $s, $m)) {
			return [[$m[1] => $this->lastRowCount]];
		}
		if (preg_match('/^SELECT\s+ROW_COUNT\(\)\s+AS\s+(\w+)$/i', $s, $m)) {
			return [[$m[1] => $this->lastRowCount]];
		}
		if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+(\w+)(.*)$/is', $s, $m)) {
			return $this->select($m[2], $m[1], $m[3]);
		}
		if (preg_match('/^INSERT INTO\s+(\w+)\s*\(([^)]*)\)\s*VALUES\s*\((.*?)\)\s*ON DUPLICATE KEY UPDATE\s+.+$/is', $s, $m)) {
			if ($this->raceInsertTransaction !== null) {
				$this->seedTransactionWithHash(...$this->raceInsertTransaction);
				$this->raceInsertTransaction = null;
			}
			$hash = null;
			$values = $this->splitValues($m[3]);
			$cols = array_map('trim', explode(',', $m[2]));
			foreach ($cols as $i => $col) if ($col === 'hash') $hash = trim($values[$i] ?? '', " '");
			foreach ($this->tables[$m[1]] ?? [] as $row) {
				if ($hash !== null && ($row['hash'] ?? null) === $hash) { $this->lastRowCount = 0; return true; }
			}
			$this->lastRowCount = 1;
			return $this->insert($m[1], $m[2], $m[3]);
		}
		if (preg_match('/^INSERT INTO\s+(\w+)\s*\(([^)]*)\)\s*VALUES\s*\((.*)\)$/is', $s, $m)) {
			$this->lastRowCount = 1;
			return $this->insert($m[1], $m[2], $m[3]);
		}
		if (preg_match('/^UPDATE\s+(\w+)\s+SET\s+(.*?)\s+WHERE\s+(.*)$/is', $s, $m)) {
			return $this->update($m[1], $m[2], $m[3]);
		}
		if (preg_match('/^DELETE FROM\s+(\w+)\s*WHERE\s+(.*)$/is', $s, $m)) {
			return $this->deleteRows($m[1], $m[3]);
		}
		$this->lastError = 'MockDoliDB: unsupported SQL: '.$s;
		return false;
	}

	public function fetch_object(&$res)
	{
		if (!is_array($res)) return false;
		$row = array_shift($res);
		return $row === null ? false : (object)$row;
	}

	public function last_insert_id($table)
	{
		return $this->nextId[$table] ?? 0;
	}

	// ------------------------------------------------------------ internals

	private function rows(string $table): array
	{
		return $this->tables[$table] ?? [];
	}

	private function select(string $table, string $cols, string $rest)
	{
		$rows = $this->rows($table);
		// Split WHERE / ORDER BY / LIMIT with string ops (regex with nested
		// quantifiers catastrophically backtracks on LIKE patterns).
		$where = null;
		$wpos = stripos($rest, 'WHERE ');
		if ($wpos !== false) {
			$after = substr($rest, $wpos + 6);
			$cut = strlen($after);
			foreach (['ORDER BY ', 'LIMIT ', 'FOR UPDATE'] as $kw) {
				$p = stripos($after, $kw);
				if ($p !== false && $p < $cut) $cut = $p;
			}
			$where = trim(substr($after, 0, $cut));
		}
		if ($where !== null && $where !== '') {
			$rows = array_values(array_filter($rows, function ($r) use ($where) {
				return $this->evalWhere($r, $where);
			}));
		}
		if (preg_match('/ORDER BY\s+(\w+)\s+(ASC|DESC)/i', $rest, $om)) {
			$key = $om[1]; $dir = strtoupper($om[2]) === 'DESC' ? -1 : 1;
			usort($rows, function ($a, $b) use ($key, $dir) {
				return ($a[$key] <=> $b[$key]) * $dir;
			});
		}
		if (preg_match('/LIMIT\s+(\d+)/i', $rest, $lm)) {
			$rows = array_slice($rows, 0, (int)$lm[1]);
		}
		$colList = array_map('trim', explode(',', str_replace('SELECT', '', $cols)));
		// COUNT(*) AS alias support (single-column aggregates)
		if (count($colList) === 1 && preg_match('/^COUNT\(\*\)\s+AS\s+(\w+)$/i', $colList[0], $cm)) {
			return [[ $cm[1] => count($rows) ]];
		}
		$keepAll = in_array('*', $colList, true);
		$out = [];
		foreach ($rows as $r) {
			if ($keepAll) { $out[] = $r; continue; }
			$sel = [];
			foreach ($colList as $c) {
				$c = trim($c);
				if (preg_match('/^(\w+(\.\w+)?)\s+AS\s+(\w+)$/i', $c, $am)) {
					$c = $am[3];
				} else {
					// strip table prefix: m.rowid -> rowid
					$c = preg_replace('/^\w+\./', '', $c);
				}
				$sel[$c] = $r[$c] ?? null;
			}
			$out[] = $sel;
		}
		return $out;
	}

	private function evalWhere(array $row, string $where): bool
	{
		// split on AND (top-level, parentheses-free assumptions)
		$parts = preg_split('/\s+AND\s+/i', $where);
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p === '') continue;
			if (preg_match('/^\s*\d+\s*$/', $where)) {
			return ($row['rowid'] ?? null) === (int)$where;
		}
		if (preg_match('/^(\w+)\s+IS NULL$/i', $p, $m)) {
				if (($row[$m[1]] ?? null) !== null) return false;
				continue;
			}
			if (preg_match('/^(\w+)\s+IS NOT NULL$/i', $p, $m)) {
				if (($row[$m[1]] ?? null) === null) return false;
				continue;
			}
			if (!preg_match('/^(\w+\.)?(\w+)\s*(=|<|>|<=|>=|LIKE|IN)\s*(.+)$/is', $p, $m)) return false;
			$field = $m[2]; $op = strtoupper($m[3]); $val = trim($m[4]);
			if (preg_match('/^IN \((.*)\)$/i', $val, $im)) {
				$vals = array_map(fn($v) => trim($v, " '"), explode(',', $im[1]));
				$cur = $row[$field] ?? null;
				if (!in_array((string)$cur, $vals, true)) return false;
				continue;
			}
			$val = trim($val, " '\")");
			if ($val === 'NOW()') continue; // ignore in WHERE
			$cur = $row[$field] ?? null;
			switch ($op) {
				case '=': if (!((string)$cur === $val || (is_numeric($cur) && is_numeric($val) && (float)$cur === (float)$val))) return false; break;
				case '<': if (!((float)$cur < (float)$val)) return false; break;
				case '>': if (!((float)$cur > (float)$val)) return false; break;
				case '<=': if (!((float)$cur <= (float)$val)) return false; break;
				case '>=': if (!((float)$cur >= (float)$val)) return false; break;
				case 'LIKE':
					$pat = str_replace(['%', '_'], ['.*', '.'], $val);
					if (!preg_match('/^'.$pat.'$/s', (string)$cur)) return false;
					break;
			}
		}
		return true;
	}

	private function insert(string $table, string $cols, string $vals)
	{
		$colList = array_map('trim', explode(',', $cols));
		$valList = $this->splitValues($vals);
		$row = [];
		foreach ($colList as $i => $c) {
			$v = trim($valList[$i] ?? 'NULL');
			if ($v === 'NULL') { $row[$c] = null; continue; }
			if (strtoupper($v) === 'NOW()') { $row[$c] = date('Y-m-d H:i:s'); continue; }
			if (is_numeric($v)) { $row[$c] = str_contains($v, '.') ? (float)$v : (int)$v; continue; }
			$row[$c] = trim($v, "'");
		}
		$id = ($this->nextId[$table] ?? 0) + 1;
		$this->nextId[$table] = $id;
		$row['rowid'] = $id;
		$this->tables[$table][] = $row;
		return true;
	}

	private function splitValues(string $vals): array
	{
		$out = []; $cur = ''; $q = false;
		for ($i = 0; $i < strlen($vals); $i++) {
			$ch = $vals[$i];
			if ($ch === "'" ) { $q = !$q; }
			if ($ch === ',' && !$q) { $out[] = $cur; $cur = ''; continue; }
			$cur .= $ch;
		}
		$out[] = $cur;
		return $out;
	}

	private function update(string $table, string $set, string $where)
	{
		$sets = array_filter(array_map('trim', explode(',', $set)));
		$changed = 0;
		foreach ($this->tables[$table] ?? [] as $k => $row) {
			if ($this->evalWhere($row, $where)) {
				foreach ($sets as $s) {
					if (!preg_match('/^(\w+)\s*=\s*(.+)$/s', $s, $m)) continue;
					$v = trim($m[2]);
					if (strtoupper($v) === 'NOW()') $v = "'".date('Y-m-d H:i:s')."'";
					if ($v === 'NULL') $this->tables[$table][$k][$m[1]] = null;
					elseif (is_numeric($v)) $this->tables[$table][$k][$m[1]] = str_contains($v, '.') ? (float)$v : (int)$v;
					else $this->tables[$table][$k][$m[1]] = trim($v, "'");
				}
				$changed++;
			}
		}
		return $changed > 0 || count($this->rows($table)) === 0;
	}

	private function deleteRows(string $table, string $where)
	{
		$before = count($this->rows($table));
		$this->tables[$table] = array_values(array_filter($this->rows($table), fn($r) => !$this->evalWhere($r, $where)));
		return count($this->tables[$table]) < $before;
	}

	// ------------------------------------------------------------ test helpers

	public function simulateConcurrentTransactionInsert(string $hash, string $date, float $amount, string $ref, string $counterparty, int $account = 1): void
	{
		$this->raceInsertTransaction = [$hash, $date, $amount, $ref, $counterparty, $account];
	}

	public function seedTransactionWithHash(string $hash, string $date, float $amount, string $ref, string $counterparty, int $account = 1): int
	{
		$this->insert('llx_bankconnect_transaction', 'fk_bank_account, hash, tx_date, amount, currency, reference, counterparty, cam_file, state, created_at',
			"$account, '".addslashes($hash)."', '$date', $amount, 'DKK', '".addslashes($ref)."', '".addslashes($counterparty)."', 'race.xml', 'unmatched', NOW()");
		return $this->nextId['llx_bankconnect_transaction'];
	}

	public function seedTransaction(string $date, float $amount, string $ref, string $counterparty, int $account = 1): int
	{
		$this->insert('llx_bankconnect_transaction', 'fk_bank_account, hash, tx_date, amount, currency, reference, counterparty, cam_file, state, created_at',
			"$account, 'hash".md5($ref.$amount)."', '$date', $amount, 'DKK', '".addslashes($ref)."', '".addslashes($counterparty)."', 'test.xml', 'unmatched', NOW()");
		return $this->nextId['llx_bankconnect_transaction'];
	}

	public function seedMatch(int $txRowid, string $type, ?string $ruleName, float $score): int
	{
		$rule = $ruleName === null ? 'NULL' : "'".addslashes($ruleName)."'";
		$this->insert('llx_bankconnect_match', 'fk_transaction, match_type, rule_name, score, reason', "$txRowid, '$type', $rule, $score, 'seeded'");
		$this->update('llx_bankconnect_transaction', "state = 'proposed'", (int)$txRowid);
		return $this->nextId['llx_bankconnect_match'];
	}

	public function setTransactionState(int $txRowid, string $state): void
	{
		foreach ($this->tables['llx_bankconnect_transaction'] ?? [] as $k => $r) {
			if ($r['rowid'] === $txRowid) $this->tables['llx_bankconnect_transaction'][$k]['state'] = $state;
		}
	}

	public function txState(int $txRowid): string
	{
		foreach ($this->tables['llx_bankconnect_transaction'] ?? [] as $r) {
			if ($r['rowid'] === $txRowid) return $r['state'];
		}
		return '';
	}

	public function countRows(string $table): int
	{
		return count($this->rows($table));
	}

	public function findFirst(string $table, string $field, $value): ?array
	{
		foreach ($this->rows($table) as $r) {
			if (($r[$field] ?? null) == $value) return $r;
		}
		return null;
	}

	public function table(string $table): array
	{
		return $this->rows($table);
	}
}
