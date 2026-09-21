<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/PaymentStateMachine.php';

final class PaymentStateMachineTest extends TestCase
{
    public function testHappyPathTransitionsAreExplicit(): void
    {
        $this->assertTrue(PaymentStateMachine::canTransition('draft', 'validated'));
        $this->assertTrue(PaymentStateMachine::canTransition('validated', 'prepared'));
        $this->assertTrue(PaymentStateMachine::canTransition('prepared', 'submitting'));
        $this->assertTrue(PaymentStateMachine::canTransition('submitting', 'submitted'));
        $this->assertTrue(PaymentStateMachine::canTransition('submitted', 'pending'));
        $this->assertTrue(PaymentStateMachine::canTransition('pending', 'accepted'));
    }

    public function testInvalidTransitionsFailClosed(): void
    {
        $this->assertFalse(PaymentStateMachine::canTransition('draft', 'submitted'));
        $this->assertFalse(PaymentStateMachine::canTransition('unknown', 'submitting'));
        $this->assertFalse(PaymentStateMachine::canTransition('accepted', 'submitting'));

        $this->expectException(InvalidArgumentException::class);
        PaymentStateMachine::assertTransition('accepted', 'submitting');
    }

    public function testUnknownAndTerminalStatesCannotBeResubmitted(): void
    {
        $this->assertSame([], PaymentStateMachine::allowedTransitions('unknown'));
        $this->assertSame([], PaymentStateMachine::allowedTransitions('accepted'));
        $this->assertSame([], PaymentStateMachine::allowedTransitions('rejected'));
        $this->assertTrue(PaymentStateMachine::isTerminal('accepted'));
        $this->assertTrue(PaymentStateMachine::isTerminal('rejected'));
    }
}
