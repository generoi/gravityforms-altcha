<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey\Functions;
use Genero\GravityFormsAltcha\EmailValidator;

/**
 * Exercises EmailValidator::validate() — the Bouncer HTTP → verdict mapping,
 * caching, and every fail-open path — with the WP HTTP + transient + filter
 * functions stubbed.
 */
class EmailValidatorBouncerTest extends MockedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Provide an API key via the $_SERVER fallback (getenv is empty in CI).
        $_SERVER['BOUNCER_API_KEY'] = 'test-key';

        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_email')->alias(fn ($email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['BOUNCER_API_KEY']);
        parent::tearDown();
    }

    private function stubBouncer(array $body, int $code = 200): void
    {
        Functions\when('wp_remote_get')->justReturn(['stub' => true]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($body));
    }

    public function test_maps_a_deliverable_verdict_and_caches_it(): void
    {
        $this->stubBouncer(['status' => 'deliverable', 'account' => ['role' => 'no'], 'reason' => 'accepted_email']);
        Functions\expect('set_transient')->once(); // definitive → cached

        $verdict = EmailValidator::validate('real.person@gmail.com');

        $this->assertSame('deliverable', $verdict['status']);
        $this->assertFalse($verdict['disposable']);
        $this->assertFalse($verdict['role']);
    }

    public function test_maps_disposable_and_role_flags(): void
    {
        $this->stubBouncer([
            'status' => 'deliverable',
            'account' => ['disposable' => 'no', 'role' => 'yes'],
            'domain' => ['disposable' => 'yes'],
        ]);
        Functions\expect('set_transient')->once();

        $verdict = EmailValidator::validate('info@mailinator.com');

        $this->assertTrue($verdict['disposable']);
        $this->assertTrue($verdict['role']);
    }

    public function test_maps_an_undeliverable_verdict(): void
    {
        $this->stubBouncer(['status' => 'undeliverable', 'reason' => 'rejected_email']);
        Functions\expect('set_transient')->once();

        $this->assertSame('undeliverable', EmailValidator::validate('nope@example.com')['status']);
    }

    public function test_http_error_fails_open_and_is_not_cached(): void
    {
        $wpError = new class
        {
            public function get_error_code(): string
            {
                return 'http_request_failed';
            }
        };
        Functions\when('wp_remote_get')->justReturn($wpError);
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\expect('set_transient')->never(); // transient failure → don't cache

        $verdict = EmailValidator::validate('real@example.com');

        $this->assertSame('unknown', $verdict['status']);
        $this->assertStringStartsWith('http_error', (string) $verdict['reason']);
    }

    public function test_non_200_fails_open(): void
    {
        $this->stubBouncer([], 500);
        Functions\expect('set_transient')->never();

        $this->assertSame('unknown', EmailValidator::validate('real@example.com')['status']);
    }

    public function test_malformed_response_fails_open(): void
    {
        Functions\when('wp_remote_get')->justReturn(['stub' => true]);
        Functions\when('wp_remote_retrieve_body')->justReturn('not-json');
        Functions\expect('set_transient')->never();

        $verdict = EmailValidator::validate('real@example.com');

        $this->assertSame('unknown', $verdict['status']);
        $this->assertSame('malformed_response', $verdict['reason']);
    }

    public function test_missing_api_key_never_calls_the_api(): void
    {
        unset($_SERVER['BOUNCER_API_KEY']);
        // If wp_remote_get were called it would error (not stubbed to expect args);
        // assert it's never hit.
        Functions\expect('wp_remote_get')->never();
        Functions\expect('set_transient')->never();

        $verdict = EmailValidator::validate('real@example.com');

        $this->assertSame('unknown', $verdict['status']);
        $this->assertSame('missing_api_key', $verdict['reason']);
    }

    public function test_invalid_syntax_is_undeliverable_without_calling_the_api(): void
    {
        Functions\expect('wp_remote_get')->never();

        $verdict = EmailValidator::validate('not-an-email');

        $this->assertSame('undeliverable', $verdict['status']);
        $this->assertSame('invalid_syntax', $verdict['reason']);
    }
}
