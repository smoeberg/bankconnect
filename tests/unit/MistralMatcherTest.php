<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MatchResult.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Candidate.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MistralMatcher.php';

function bcMakeTx(array $over = []): BankTransaction
{
    $tx = new BankTransaction();
    $tx->date = '2026-09-12';
    $tx->amount = -12450.00;
    $tx->currency = 'DKK';
    $tx->text = 'BETALING LEVERANDOR ABC 784512';
    $tx->reference = '784512';
    $tx->counterparty = 'ABC A/S';
    foreach ($over as $k => $v) {
        $tx->$k = $v;
    }
    return $tx;
}

function bcMakeCandidates(): array
{
    $a = new Candidate();
    $a->id = 1842; $a->type = 'supplier_invoice'; $a->ref = 'FA240891';
    $a->amount = 12450.00; $a->remaining = 12450.00;
    $a->date = '2026-09-10'; $a->thirdparty = 'ABC A/S';

    $b = new Candidate();
    $b->id = 1855; $b->type = 'supplier_invoice'; $b->ref = 'FA240902';
    $b->amount = 8200.00; $b->remaining = 8200.00;
    $b->date = '2026-09-08'; $b->thirdparty = 'ABC A/S';

    $c = new Candidate();
    $c->id = 1831; $c->type = 'supplier_invoice'; $c->ref = 'FA240875';
    $c->amount = 4250.00; $c->remaining = 4250.00;
    $c->date = '2026-09-05'; $c->thirdparty = 'ABC A/S';

    return [$a, $b, $c];
}

function bcMatcherConf(array $over = []): Conf
{
    $conf = new Conf();
    $conf->global = array_merge([
        'BANKCONNECT_AI_ENABLED'       => 1,
        'BANKCONNECT_MISTRAL_API_KEY'  => 'test-key',
        'BANKCONNECT_MISTRAL_MODEL'    => 'mistral-small-latest',
        'BANKCONNECT_MISTRAL_ENDPOINT' => 'https://api.mistral.ai/v1/chat/completions',
        'BANKCONNECT_AI_TEMPERATURE'   => 0.1,
        'BANKCONNECT_AI_TIMEOUT'       => 8,
        'BANKCONNECT_AI_MAX_TOKENS'    => 800,
        'BANKCONNECT_AI_RATE_LIMIT_FILE' => sys_get_temp_dir().'/bankconnect-test-rate-limit-'.bin2hex(random_bytes(8)).'.json',
    ], $over);
    return $conf;
}

function bcApiBody(array $content): string
{
    return json_encode([
        'choices' => [
            ['message' => ['content' => json_encode($content)]],
        ],
    ]);
}

class MistralMatcherTest extends TestCase
{
    /** 1. Korrekt JSON-parsing af gyldigt svar */
    public function testValidExactResponse(): void
    {
        $matcher = new MistralMatcher(bcMatcherConf());
        $matcher->setTransport(function () {
            return [
                'status' => 200,
                'body'   => bcApiBody([
                    'match_type' => 'exact',
                    'confidence' => 0.93,
                    'suggested'  => [['id' => 1842, 'type' => 'supplier_invoice', 'amount' => 12450.00]],
                    'reason'     => 'Reference og beløb matcher faktura FA240891',
                ]),
            ];
        });

        $result = $matcher->match(bcMakeTx(), bcMakeCandidates());

        $this->assertSame('exact', $result->matchType);
        $this->assertSame(0.93, $result->confidence);
        $this->assertSame(1842, $result->suggested[0]['id']);
        $this->assertSame('supplier_invoice', $result->suggested[0]['type']);
        $this->assertSame(12450.00, $result->suggested[0]['amount']);
        $this->assertSame('ai', $result->source);
        $this->assertNotSame('', $result->reason);
    }

    /** 2. Ugyldigt / tomt JSON -> none */
    public function testInvalidJsonReturnsNone(): void
    {
        $matcher = new MistralMatcher(bcMatcherConf());
        $matcher->setTransport(fn () => ['status' => 200, 'body' => bcApiBody(['garbage' => true])]);
        $r = $matcher->match(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('none', $r->matchType);
        $this->assertSame('Ugyldigt AI-svar', $r->reason);

        $matcher2 = new MistralMatcher(bcMatcherConf());
        $matcher2->setTransport(fn () => ['status' => 200, 'body' => '']);
        $r2 = $matcher2->match(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('none', $r2->matchType);
    }

    /** 3. Timeout / netværksfejl -> none, ingen exception */
    public function testTimeoutReturnsNone(): void
    {
        $matcher = new MistralMatcher(bcMatcherConf());
        $matcher->setTransport(function () {
            throw new BankConnectException('Curl error: Operation timed out');
        });
        $r = $matcher->match(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('none', $r->matchType);
        $this->assertStringContainsString('timeout', strtolower($r->reason));
    }

    /** 4. match_type og confidence valideres */
    public function testMatchTypeAndConfidenceValidation(): void
    {
        $m1 = new MistralMatcher(bcMatcherConf());
        $m1->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'maybe', 'confidence' => 0.5, 'suggested' => [],
        ])]);
        $this->assertSame('none', $m1->match(bcMakeTx(), bcMakeCandidates())->matchType);

        $m2 = new MistralMatcher(bcMatcherConf());
        $m2->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'exact', 'confidence' => 2.5,
            'suggested'  => [['id' => 1842, 'type' => 'supplier_invoice', 'amount' => 12450.00]],
        ])]);
        $r = $m2->match(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('exact', $r->matchType);
        $this->assertLessThanOrEqual(1.0, $r->confidence);
        $this->assertGreaterThanOrEqual(0.0, $r->confidence);

        $m3 = new MistralMatcher(bcMatcherConf());
        $m3->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'exact', 'suggested' => [],
        ])]);
        $this->assertSame('none', $m3->match(bcMakeTx(), bcMakeCandidates())->matchType);
    }

    /** 5. suggested indeholder kun gyldige id'er (strukturvalidering) */
    public function testSuggestedStructure(): void
    {
        $m = new MistralMatcher(bcMatcherConf());
        $m->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'multiple',
            'confidence' => 0.91,
            'suggested'  => [
                ['id' => 1855, 'type' => 'supplier_invoice', 'amount' => 8200.00],
                ['id' => 'bad', 'amount' => 1.0],
            ],
        ])]);
        $r = $m->match(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('none', $r->matchType);
        $this->assertSame('Ugyldigt AI-svar', $r->reason);

        $unknown = new MistralMatcher(bcMatcherConf());
        $unknown->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'exact',
            'confidence' => 0.9,
            'suggested'  => [['id' => 999999, 'type' => 'supplier_invoice', 'amount' => 1.0]],
        ])]);
        $this->assertSame('none', $unknown->match(bcMakeTx(), bcMakeCandidates())->matchType);
    }

    public function testRateLimitPersistsAcrossMatcherInstances(): void
    {
        $path = sys_get_temp_dir().'/bankconnect-rate-limit-'.bin2hex(random_bytes(8)).'.json';
        $conf = bcMatcherConf([
            'BANKCONNECT_AI_RATE_LIMIT_MAX' => 1,
            'BANKCONNECT_AI_RATE_LIMIT_WINDOW' => 60,
            'BANKCONNECT_AI_RATE_LIMIT_FILE' => $path,
        ]);

        try {
            $first = new MistralMatcher($conf);
            $first->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
                'match_type' => 'none', 'confidence' => 0.0, 'suggested' => [],
            ])]);
            $this->assertSame('none', $first->match(bcMakeTx(), bcMakeCandidates())->matchType);

            $called = false;
            $second = new MistralMatcher($conf);
            $second->setTransport(function () use (&$called) {
                $called = true;
                return ['status' => 200, 'body' => bcApiBody([
                    'match_type' => 'exact', 'confidence' => 1.0, 'suggested' => [],
                ])];
            });
            $this->assertSame('none', $second->match(bcMakeTx(), bcMakeCandidates())->matchType);
            $this->assertFalse($called);
        } finally {
            @unlink($path);
        }
    }

    /** 6. AI slået fra -> none, ingen transport-kald */
    public function testDisabledReturnsNoneWithoutCallingApi(): void
    {
        $matcher = new MistralMatcher(bcMatcherConf(['BANKCONNECT_AI_ENABLED' => 0]));
        $called = false;
        $matcher->setTransport(function () use (&$called) {
            $called = true;
            return ['status' => 200, 'body' => bcApiBody(['match_type' => 'exact', 'confidence' => 1.0, 'suggested' => []])];
        });
        $r = $matcher->match(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('none', $r->matchType);
        $this->assertSame('AI disabled', $r->reason);
        $this->assertFalse($called);
    }

    /** 6b. Ingen kandidater -> none, ingen API-kald */
    public function testNoCandidatesReturnsNone(): void
    {
        $matcher = new MistralMatcher(bcMatcherConf());
        $called = false;
        $matcher->setTransport(function () use (&$called) {
            $called = true;
            return ['status' => 200, 'body' => bcApiBody(['match_type' => 'none', 'confidence' => 0.0, 'suggested' => []])];
        });
        $r = $matcher->match(bcMakeTx(), []);
        $this->assertSame('none', $r->matchType);
        $this->assertFalse($called);
    }

    /** 7. testConnection */
    public function testConnectionSuccessAndFailure(): void
    {
        $ok = new MistralMatcher(bcMatcherConf());
        $ok->setTransport(fn () => ['status' => 200, 'body' => bcApiBody(['match_type' => 'none', 'confidence' => 0.0, 'suggested' => []])]);
        $res = $ok->testConnection();
        $this->assertTrue($res['success']);
        $this->assertGreaterThanOrEqual(0, $res['latency_ms']);

        $fail = new MistralMatcher(bcMatcherConf());
        $fail->setTransport(fn () => ['status' => 401, 'body' => '{"error":"unauthorized"}']);
        $res2 = $fail->testConnection();
        $this->assertFalse($res2['success']);
        $this->assertStringContainsString('401', $res2['message']);
    }

    /** 12. Batch: multiple-svar med to kandidater */
    public function testBatchMultiple(): void
    {
        $matcher = new MistralMatcher(bcMatcherConf());
        $matcher->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            ['index' => 1, 'match_type' => 'multiple', 'confidence' => 0.91,
             'suggested' => [
                 ['id' => 1855, 'type' => 'supplier_invoice', 'amount' => 8200.00],
                 ['id' => 1831, 'type' => 'supplier_invoice', 'amount' => 4250.00],
             ],
             'reason' => 'Summen matcher bankbeløbet'],
        ])]);

        $items = [['tx' => bcMakeTx(['amount' => -12450.00]), 'candidates' => bcMakeCandidates()]];
        $results = $matcher->matchBatch($items);

        $this->assertCount(1, $results);
        $this->assertSame('multiple', $results[0]->matchType);
        $this->assertCount(2, $results[0]->suggested);
        $this->assertSame(8200.00, $results[0]->suggested[0]['amount']);
    }

    /** GDPR: CPR-lignende tal fjernes fra prompt */
    public function testPromptSanitizesPersonalData(): void
    {
        $captured = null;
        $matcher = new MistralMatcher(bcMatcherConf());
        $matcher->setTransport(function ($method, $url, $opts) use (&$captured) {
            $captured = $opts['body'];
            return ['status' => 200, 'body' => bcApiBody(['match_type' => 'none', 'confidence' => 0.1, 'suggested' => []])];
        });

        $tx = bcMakeTx(['text' => 'BETALING CPR 010280-1234 KUNDE']);
        $matcher->match($tx, bcMakeCandidates());

        $this->assertStringNotContainsString('010280', (string) $captured);
        $this->assertStringContainsString('[FJERNET]', (string) $captured);
    }

    /** Logging: ingen følsomme data i loggen */
    public function testLogsContainNoSensitiveData(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new BankConnectLogger($stream);
        $matcher = new MistralMatcher(bcMatcherConf(), $logger);
        $matcher->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'exact', 'confidence' => 0.95,
            'suggested'  => [['id' => 1842, 'type' => 'supplier_invoice', 'amount' => 12450.00]],
        ])]);

        $matcher->match(bcMakeTx(), bcMakeCandidates());
        rewind($stream);
        $log = stream_get_contents($stream);

        $this->assertStringNotContainsString('BETALING LEVERANDOR', $log);
        $this->assertStringContainsString('latency_ms', $log);
    }
    /** Sikkerhed: loggeren redigerer hemmelige kontekstnøgler */
    public function testLoggerRedactsSecrets(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new BankConnectLogger($stream);
        $logger->info('ctx', ['api_key' => 'sk-supersecret', 'nested' => ['private_key' => 'PEM-DATA'], 'count' => 3]);
        rewind($stream);
        $log = stream_get_contents($stream);

        $this->assertStringNotContainsString('sk-supersecret', $log);
        $this->assertStringNotContainsString('PEM-DATA', $log);
        $this->assertStringContainsString('[REDACTED]', $log);
        $this->assertStringContainsString('"count":3', $log);
    }

    /** Sikkerhed: env-variabel har forrang frem for conf-nøglen */
    public function testEnvKeyOverridesConf(): void
    {
        $captured = null;
        $matcher = new MistralMatcher(bcMatcherConf(['BANKCONNECT_MISTRAL_API_KEY' => 'conf-key']));
        $matcher->setTransport(function (string $method, string $url, array $opts) use (&$captured) {
            foreach ($opts['headers'] ?? [] as $h) {
                if (stripos((string)$h, 'authorization') === 0) {
                    $captured = (string)$h;
                }
            }
            return ['status' => 200, 'body' => bcApiBody(['match_type' => 'none'])];
        });

        putenv('BANKCONNECT_MISTRAL_API_KEY=env-key');
        try {
            $matcher->match(bcMakeTx(), bcMakeCandidates());
        } finally {
            putenv('BANKCONNECT_MISTRAL_API_KEY');
        }

        $this->assertStringEndsWith('Bearer env-key', (string)$captured);
    }

    /** Sikkerhed: ingen API-nøgle i hverken env eller conf -> ingen forespørgsel */
    public function testMissingKeyNeverCallsApi(): void
    {
        $called = false;
        $conf = bcMatcherConf(['BANKCONNECT_AI_ENABLED' => 1, 'BANKCONNECT_MISTRAL_API_KEY' => '', 'BANKCONNECT_MISTRAL_ENDPOINT' => 'https://api.mistral.ai/v1/chat/completions']);
        $matcher = new MistralMatcher($conf);
        $matcher->setTransport(function () use (&$called) {
            $called = true;
            return ['status' => 200, 'body' => bcApiBody(['match_type' => 'none'])];
        });

        putenv('BANKCONNECT_MISTRAL_API_KEY');
        $r = $matcher->testConnection();

        $this->assertFalse($called);
        $this->assertFalse($r['success']);
        $this->assertStringContainsStringIgnoringCase('not configured', $r['message']);
    }
}
