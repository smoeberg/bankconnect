<?php

class MatchResult
{
    /** @var string exact|partial|multiple|none */
    public string $matchType = 'none';

    public float $confidence = 0.0;

    /** @var array<int,array{id:int|string,type:string,amount:float}> */
    public array $suggested = [];

    public string $reason = '';

    /** @var string rule|ai */
    public string $source = 'rule';

    /** Stable rule identifier for audit/evidence. */
    public string $ruleName = '';

    /**
     * Human-review band derived from confidence only. This is never an
     * approval decision and never grants posting authority.
     */
    public function confidenceBand(): string
    {
        if ($this->matchType === 'none' || $this->confidence <= 0.0) {
            return 'none';
        }
        if ($this->confidence >= 0.90) {
            return 'strong';
        }
        if ($this->confidence >= 0.60) {
            return 'review';
        }
        return 'weak';
    }

    public static function none(string $reason = '', string $source = 'rule'): self
    {
        $r = new self();
        $r->matchType = 'none';
        $r->confidence = 0.0;
        $r->reason = $reason;
        $r->source = $source;
        $r->ruleName = $source === 'ai' ? 'ai_fallback' : 'none';
        return $r;
    }
}
