<?php
/**
 * PaymentStateMachine – explicit BankConnect payment lifecycle contract.
 *
 * This class is deliberately free of persistence and transport concerns.
 * It defines which local state transitions are legal; recovery of UNKNOWN
 * and reconciliation are handled by later payment-recovery/status slices.
 */
class PaymentStateMachine
{
    public const DRAFT = 'draft';
    public const VALIDATED = 'validated';
    public const PREPARED = 'prepared';
    public const SUBMITTING = 'submitting';
    public const UNKNOWN = 'unknown';
    public const SUBMITTED = 'submitted';
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const PARTIAL = 'partial';
    public const REJECTED = 'rejected';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::DRAFT => [self::VALIDATED],
        self::VALIDATED => [self::PREPARED],
        self::PREPARED => [self::SUBMITTING],
        self::SUBMITTING => [self::SUBMITTED, self::UNKNOWN],
        self::SUBMITTED => [self::PENDING, self::ACCEPTED, self::PARTIAL, self::REJECTED],
        self::PENDING => [self::ACCEPTED, self::PARTIAL, self::REJECTED],
        self::PARTIAL => [self::PENDING, self::ACCEPTED, self::REJECTED],
        self::UNKNOWN => [],
        self::ACCEPTED => [],
        self::REJECTED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidArgumentException(
                "Invalid payment state transition: {$from} -> {$to}"
            );
        }
    }

    /** @return list<string> */
    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [self::ACCEPTED, self::REJECTED], true);
    }

    public static function isSubmissionState(string $state): bool
    {
        return in_array($state, [self::SUBMITTING, self::UNKNOWN, self::SUBMITTED], true);
    }
}
