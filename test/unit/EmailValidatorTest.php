<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests;

use Genero\GravityFormsAltcha\EmailValidator;
use PHPUnit\Framework\TestCase;

class EmailValidatorTest extends TestCase
{
    public function test_undeliverable_blocks(): void
    {
        $this->assertTrue(EmailValidator::result('undeliverable')['block']);
    }

    public function test_disposable_blocks_even_when_deliverable(): void
    {
        $this->assertTrue(EmailValidator::result('deliverable', disposable: true)['block']);
    }

    public function test_deliverable_does_not_block(): void
    {
        $this->assertFalse(EmailValidator::result('deliverable')['block']);
    }

    public function test_uncertain_verdicts_never_block(): void
    {
        // Fail open: risky/unknown must let the submission through.
        $this->assertFalse(EmailValidator::result('risky')['block']);
        $this->assertFalse(EmailValidator::result('unknown')['block']);
    }

    public function test_infra_failures_are_not_definitive(): void
    {
        // These should NOT be cached as a verdict.
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
