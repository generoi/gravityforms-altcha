<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests;

use Genero\GravityFormsAltcha\EmailValidator;
use PHPUnit\Framework\TestCase;

class EmailValidatorTest extends TestCase
{
    private const ALL = ['undeliverable' => true, 'risky' => true, 'disposable' => true];

    private const NONE = ['undeliverable' => false, 'risky' => false, 'disposable' => false];

    public function test_undeliverable_blocks_only_when_that_mode_is_on(): void
    {
        $verdict = EmailValidator::result('undeliverable');
        $this->assertTrue(EmailValidator::shouldBlock($verdict, ['undeliverable' => true]));
        $this->assertFalse(EmailValidator::shouldBlock($verdict, self::NONE));
    }

    public function test_risky_blocks_only_when_that_mode_is_on(): void
    {
        $verdict = EmailValidator::result('risky');
        $this->assertTrue(EmailValidator::shouldBlock($verdict, ['risky' => true]));
        // Risky is off by default → real-ish addresses get through.
        $this->assertFalse(EmailValidator::shouldBlock($verdict, ['undeliverable' => true, 'disposable' => true]));
    }

    public function test_disposable_blocks_independent_of_status(): void
    {
        $verdict = EmailValidator::result('deliverable', disposable: true);
        $this->assertTrue(EmailValidator::shouldBlock($verdict, ['disposable' => true]));
        $this->assertFalse(EmailValidator::shouldBlock($verdict, ['undeliverable' => true, 'risky' => true]));
    }

    public function test_deliverable_never_blocks(): void
    {
        $this->assertFalse(EmailValidator::shouldBlock(EmailValidator::result('deliverable'), self::ALL));
    }

    public function test_unknown_never_blocks(): void
    {
        // Fail open on uncertainty even with every mode enabled.
        $this->assertFalse(EmailValidator::shouldBlock(EmailValidator::result('unknown'), self::ALL));
    }

    public function test_no_modes_enabled_never_blocks(): void
    {
        $this->assertFalse(EmailValidator::shouldBlock(EmailValidator::result('undeliverable', disposable: true), self::NONE));
    }

    public function test_infra_failures_are_not_definitive(): void
    {
        $this->assertFalse(EmailValidator::isDefinitive(EmailValidator::result('unknown', reason: 'missing_api_key')));
        $this->assertFalse(EmailValidator::isDefinitive(EmailValidator::result('unknown', reason: 'http_error:timeout')));
        $this->assertFalse(EmailValidator::isDefinitive(EmailValidator::result('unknown', reason: 'http_status:500')));
        $this->assertFalse(EmailValidator::isDefinitive(EmailValidator::result('unknown', reason: 'malformed_response')));
    }

    public function test_real_verdicts_are_definitive(): void
    {
        $this->assertTrue(EmailValidator::isDefinitive(EmailValidator::result('deliverable')));
        $this->assertTrue(EmailValidator::isDefinitive(EmailValidator::result('undeliverable', reason: 'rejected_email')));
    }
}
