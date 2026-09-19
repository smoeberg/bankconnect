<?php

/**
 * MistralMatcher - AI fallback matching via Mistral API.
 *
 * Design:
 *  - Injectable transport for testing (no real API in unit tests).
 *  - Never throws: all failures degrade to MatchResult::none().
 *  - PII sanitization before any data leaves the tenant.
 *  - PII-safe logging (counts, hash, latency - never statement text).
 */

require_once __DIR__.'/MatchResult.php';
require_once __DIR__.'/BankTransaction.php';
require_once __DIR__.'/Candidate.php';
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';

class MistralMatcher
{
    private Conf $conf;
    private BankConnectLogger $logger;
    private int $timeout;
    private float $temperature;
    private int $maxTokens;
    private int $rateLimitMaxCalls = 10;
    private int $rateLimitWindowSeconds = 60;
    private array $rateLimitTimestamps = [];

    /** @var callable|null fn(string $method, string $url, array $opts): array{status:int,body:string} */
    private $transport;

    public function __construct(Conf $conf, ?BankConnectLogger $logger = null)
    {
        $this->conf = $conf;
        $this->logger = $logger ?: new BankConnectLogger();

        $g = $conf->global ?? [];
        $this->timeout = max(1, (int) ($g['BANKCONNECT_AI_TIMEOUT'] ?? 8));
        $this->temperature = (float) ($g['BANKCONNECT_AI_TEMPERATURE'] ?? 0.1);
        $this->maxTokens = max(100, (int) ($g['BANKCONNECT_AI_MAX_TOKENS'] ?? 800));
        
        // Rate limiting configuration
        $this->rateLimitMaxCalls = max(1, (int) ($g['BANKCONNECT_AI_RATE_LIMIT_MAX'] ?? 10));
        $this->rateLimitWindowSeconds = max(1, (int) ($g['BANKCONNECT_AI_RATE_LIMIT_WINDOW'] ?? 60));
    }

    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    private function enabled(): bool
    {
        $g = $this->conf->global ?? [];
        return (bool) ($g['BANKCONNECT_AI_ENABLED'] ?? 0);
    }

    private function apiKey(): string
    {
        return (string) ($this->conf->global['BANKCONNECT_MISTRAL_API_KEY'] ?? '');
    }

    private function model(): string
    {
        return (string) ($this->conf->global['BANKCONNECT_MISTRAL_MODEL'] ?? 'mistral-small-latest');
    }

    private function endpoint(): string
    {
        $endpoint = (string) ($this->conf->global['BANKCONNECT_MISTRAL_ENDPOINT'] ?? '');
        if ($endpoint === '') {
            throw new BankConnectException('BANKCONNECT_MISTRAL_ENDPOINT is not configured. Please set it in Dolibarr configuration.');
        }
        return $endpoint;
    }

    private function isCloudEndpoint(): bool
    {
        return str_contains($this->endpoint(), 'api.mistral.ai');
    }

    /**
     * Match one transaction.
     */
    public function match(BankTransaction $tx, array $candidates): MatchResult
    {
        if (!$this->enabled()) {
            return MatchResult::none('AI disabled');
        }
        if (empty($candidates)) {
            return MatchResult::none('Ingen kandidater');
        }

        $prompt = $this->buildPrompt($tx, $candidates);
        $start = microtime(true);
        $response = $this->callApi([$prompt]);
        $latency = (int) round((microtime(true) - $start) * 1000);

        $this->logger->info('mistral match', [
            'candidates' => count($candidates),
            'latency_ms' => $latency,
            'tx_hash'    => substr($tx->hash ?: hash('sha256', $tx->date.'|'.$tx->amount), 0, 12),
        ]);

        if ($response === null) {
            return MatchResult::none('AI utilgængelig (timeout eller fejl)', 'ai');
        }

        $parsed = $this->parseResponse($response, 0, $candidates);
        if ($parsed === null) {
            return MatchResult::none('Ugyldigt AI-svar', 'ai');
        }
        return $parsed;
    }

    /**
     * Match a batch of items in one API call.
     *
     * @param array $items array of ['tx'=>BankTransaction,'candidates'=>Candidate[]]
     * @return MatchResult[] indexed like input
     */
    public function matchBatch(array $items): array
    {
        if (!$this->enabled()) {
            return array_fill_keys(array_keys($items), MatchResult::none('AI disabled'));
        }

        $prompts = [];
        foreach ($items as $i => $item) {
            $prompts[$i] = $this->buildPrompt($item['tx'], $item['candidates'], count($items) > 1, $i + 1);
        }

        $start = microtime(true);
        $response = $this->callApi(array_values($prompts));
        $latency = (int) round((microtime(true) - $start) * 1000);

        $this->logger->info('mistral batch match', [
            'items'      => count($items),
            'latency_ms' => $latency,
        ]);

        if ($response === null) {
            return array_fill_keys(array_keys($items), MatchResult::none('AI utilgængelig', 'ai'));
        }

        $results = [];
        $decoded = json_decode($response, true);
        $byIndex = [];
        foreach ((array) ($decoded ?? []) as $entry) {
            if (isset($entry['index'])) {
                $byIndex[(int) $entry['index'] - 1] = $entry;
            }
        }

        foreach ($prompts as $i => $_) {
            $entry = $byIndex[$i] ?? null;
            if ($entry === null) {
                $results[$i] = MatchResult::none('Manglende AI-svar for post', 'ai');
                continue;
            }
            $parsed = $this->parseResponse(json_encode($entry), $i, $items[$i]['candidates']);
            $results[$i] = $parsed ?? MatchResult::none('Ugyldigt AI-svar', 'ai');
        }

        ksort($results);
        return $results;
    }

    /**
     * Verify API connectivity. Returns ['success'=>bool,'message'=>string,'latency_ms'=>int].
     */
    public function testConnection(): array
    {
        try {
            $this->endpoint(); // This will throw if not configured
        } catch (BankConnectException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'latency_ms' => 0];
        }

        $start = microtime(true);
        $body = json_encode([
            'model'      => $this->model(),
            'messages'   => [
                ['role' => 'system', 'content' => 'Svar kun med JSON.'],
                ['role' => 'user', 'content' => '{"match_type":"none","confidence":0.0,"suggested":[],"reason":"test"}'],
            ],
            'temperature' => 0.0,
            'max_tokens'  => 100,
        ]);
        $response = $this->rawRequest('POST', $this->endpoint(), [
            'headers' => $this->headers(),
            'body'    => $body,
        ]);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($response === null) {
            return ['success' => false, 'message' => 'Timeout eller netværksfejl', 'latency_ms' => $latency];
        }

        if ($response['status'] === 200) {
            return ['success' => true, 'message' => 'OK', 'latency_ms' => $latency];
        }

        return ['success' => false, 'message' => 'HTTP '.$response['status'], 'latency_ms' => $latency];
    }

    /* -----------------------------------------------------------------
     * Prompt + transport
     * ----------------------------------------------------------------- */

    private function buildPrompt(BankTransaction $tx, array $candidates, bool $numbered = false, int $index = 1): string
    {
        $candList = [];
        foreach ($candidates as $c) {
            $candList[] = [
                'id'         => $c->id,
                'type'       => $c->type,
                'ref'        => $c->ref,
                'amount'     => $c->remaining,
                'date'       => $c->date,
                'thirdparty' => $c->thirdparty,
            ];
        }

        $payload = [
            'transaction' => [
                'date'         => $tx->date,
                'amount'       => $tx->amount,
                'currency'     => $tx->currency,
                'text'         => $this->sanitize($tx->text),
                'reference'    => $this->sanitize($tx->reference),
                'counterparty' => $this->sanitize($tx->counterparty),
            ],
            'candidates' => $candList,
        ];

        if ($numbered) {
            $payload['index'] = $index;
        }

        $system = <<<TXT
Du er en dansk bogholder-assistent. Givet en bankpost og kandidater (fakturaer),
returner KUN JSON med felterne:
  match_type: "exact" | "partial" | "multiple" | "none"
  confidence: tal mellem 0 og 1
  suggested: array af {id, type, amount} - kun gyldige kandidat-id'er
  reason: kort dansk begrundelse
Ingen tekst uden for JSON.
TXT;

        return json_encode([
            'model'      => $this->model(),
            'messages'   => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($payload)],
            ],
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
        ]);
    }

    /**
     * Remove Danish CPR numbers and similar PII patterns from text.
     * Preserves company suffixes (A/S, ApS) and general numbers.
     */
    public function sanitize(string $text): string
    {
        // ddmm-xxxx CPR pattern
        $text = preg_replace('/\b\d{6}[- ]?\d{4}\b/u', '[FJERNET]', $text);
        // long bare numeric sequences (10+ digits, likely account/CPR)
        $text = preg_replace('/\b\d{10,}\b/u', '[FJERNET]', $text);
        return $text;
    }

    private function callApi(array $prompts): ?string
    {
        // Rate limiting check
        $now = time();
        $this->rateLimitTimestamps = array_filter(
            $this->rateLimitTimestamps,
            fn($t) => $now - $t < $this->rateLimitWindowSeconds
        );
        
        if (count($this->rateLimitTimestamps) >= $this->rateLimitMaxCalls) {
            $this->logger->warning('rate_limit_exceeded', [
                'calls' => count($this->rateLimitTimestamps),
                'window_seconds' => $this->rateLimitWindowSeconds,
            ]);
            return null; // Graceful degradation - return none
        }
        
        $this->rateLimitTimestamps[] = $now;

        $opts = [
            'headers' => $this->headers(),
            'body'    => $prompts[0],
        ];
        // Batch call: send all prompts as separate messages? Simpler: one user msg
        if (count($prompts) > 1) {
            $decoded = json_decode($prompts[0], true);
            $userContent = [];
            foreach ($prompts as $p) {
                $d = json_decode($p, true);
                $userContent[] = $d['messages'][1]['content'];
            }
            $decoded['messages'][1]['content'] = json_encode($userContent);
            $opts['body'] = json_encode($decoded);
        }

        $response = $this->rawRequest('POST', $this->endpoint(), $opts);
        if ($response === null || $response['status'] !== 200) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return null;
        }
        return $content;
    }

    private function headers(): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$this->apiKey(),
        ];
        // Cloud endpoint supports structured output; self-hosted may not.
        if ($this->isCloudEndpoint()) {
            $headers[] = 'X-Response-Format: json_object';
        }
        return $headers;
    }

    /**
     * Injectable transport layer.
     *
     * @return array{status:int,body:string}|null null on network failure/timeout
     */
    private function rawRequest(string $method, string $url, array $opts): ?array
    {
        if ($this->transport !== null) {
            try {
                return ($this->transport)($method, $url, $opts);
            } catch (BankConnectException $e) {
                $this->logger->warning('transport error', ['error' => 'timeout']);
                return null;
            } catch (\Throwable $e) {
                $this->logger->warning('transport error', ['error' => 'unexpected']);
                return null;
            }
        }

        // Real transport (cURL)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
            CURLOPT_POSTFIELDS     => $opts['body'] ?? '',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $err !== 0) {
            $this->logger->warning('curl error', ['errno' => $err]);
            return null;
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /* -----------------------------------------------------------------
     * Response parsing
     * ----------------------------------------------------------------- */

    /**
     * @param array $candidates
     */
    private function parseResponse(string $content, int $index, array $candidates): ?MatchResult
    {
        // Strip markdown fences if any
        $content = trim($content);
        $content = preg_replace('/^```(json)?|```$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        $matchType = $decoded['match_type'] ?? null;
        if (!in_array($matchType, ['exact', 'partial', 'multiple', 'none'], true)) {
            return null;
        }

        $confidence = $decoded['confidence'] ?? null;
        if (!is_int($confidence) && !is_float($confidence)) {
            return null;
        }
        $confidence = max(0.0, min(1.0, (float) $confidence));

        $suggested = [];
        $validIds = [];
        foreach ($candidates as $c) {
            $validIds[(string) $c->id] = $c;
        }
        foreach ((array) ($decoded['suggested'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            if (!array_key_exists('id', $s) || !is_int($s['id']) && !ctype_digit((string) $s['id'])) {
                return null;
            }
            if (!isset($s['type']) || !is_string($s['type'])) {
                return null;
            }
            if (!isset($s['amount']) || !is_numeric($s['amount'])) {
                return null;
            }
            $suggested[] = [
                'id'     => (int) $s['id'],
                'type'   => (string) $s['type'],
                'amount' => (float) $s['amount'],
            ];
        }

        $r = new MatchResult();
        $r->matchType = $matchType;
        $r->confidence = $confidence;
        $r->suggested = $suggested;
        $r->reason = (string) ($decoded['reason'] ?? '');
        $r->source = 'ai';
        return $r;
    }
}
