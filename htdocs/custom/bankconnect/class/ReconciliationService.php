<?php

/**
 * ReconciliationService - persistence/audit boundary for reconciliation proposals.
 *
 * The engine only decides what evidence supports a match. This service persists
 * that proposal and records an audit trail. It never approves or posts a match.
 */
require_once __DIR__.'/ReconciliationEngine.php';
require_once __DIR__.'/BankConnectStore.php';

class ReconciliationService
{
    private ReconciliationEngine $engine;
    private BankConnectStore $store;

    public function __construct(ReconciliationEngine $engine, BankConnectStore $store)
    {
        $this->engine = $engine;
        $this->store = $store;
    }

    /**
     * Evaluate and persist one proposal.
     *
     * @param int $transactionId Existing BankConnect transaction rowid.
     * @param int $userId Actor responsible for running reconciliation (0 for system).
     * @param BankTransaction $transaction Imported bank transaction.
     * @param Candidate[] $candidates Deterministic candidate set.
     * @return MatchResult Proposal only; approval/posting remains a separate action.
     */
    public function propose(
        int $transactionId,
        int $userId,
        BankTransaction $transaction,
        array $candidates
    ): MatchResult {
        $result = $this->engine->reconcile($transaction, $candidates);

        $audit = [
            'tx' => $transactionId,
            'match_type' => $result->matchType,
            'source' => $result->source,
            'rule' => $result->ruleName,
            'confidence' => $result->confidence,
            'confidence_band' => $result->confidenceBand(),
            'suggested' => array_map(
                static fn (array $s): array => [
                    'id' => (string) ($s['id'] ?? ''),
                    'type' => (string) ($s['type'] ?? ''),
                ],
                $result->suggested
            ),
        ];

        if ($result->matchType === 'none') {
            $this->store->audit($userId, 'match_none', $this->encodeAudit($audit));
            return $result;
        }

        $this->store->saveMatch(
            $transactionId,
            $result->matchType,
            $result->ruleName !== '' ? $result->ruleName : null,
            null,
            $result->confidence,
            $result->reason
        );
        $this->store->audit($userId, 'match_proposed', $this->encodeAudit($audit));

        return $result;
    }

    /**
     * Evaluate many transactions without granting any approval/posting authority.
     *
     * @param array<int,array{transaction_id:int,user_id:int,transaction:BankTransaction,candidates:array}> $items
     * @return MatchResult[] indexed like $items
     */
    public function proposeBatch(array $items): array
    {
        $results = [];
        foreach ($items as $index => $item) {
            $results[$index] = $this->propose(
                (int) $item['transaction_id'],
                (int) $item['user_id'],
                $item['transaction'],
                $item['candidates']
            );
        }
        return $results;
    }

    private function encodeAudit(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
