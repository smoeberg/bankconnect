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

    public static function none(string $reason = '', string $source = 'rule'): self
    {
        $r = new self();
        $r->matchType = 'none';
        $r->confidence = 0.0;
        $r->reason = $reason;
        $r->source = $source;
        return $r;
    }
}
