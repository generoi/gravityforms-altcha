<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey\Functions;
use Genero\GravityFormsAltcha\SpamFilter;
use ReflectionMethod;

/**
 * Exercises SpamFilter::exceedsRateLimit() — the per-IP counter over transients
 * and the unknown-IP guard — with transients/filters/wp_salt stubbed.
 */
class RateLimitTest extends MockedTestCase
{
    /** @var array<string, mixed> in-memory transient store */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];

        Functions\when('apply_filters')->returnArg(2); // defaults: max 3, window HOUR, ['REMOTE_ADDR']
        Functions\when('wp_salt')->justReturn('test-salt');
        Functions\when('get_transient')->alias(fn ($k) => $this->store[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->store[$k] = $v;

            return true;
        });
    }

    private function exceeds(array $form): bool
    {
        $method = new ReflectionMethod(SpamFilter::class, 'exceedsRateLimit');
        $method->setAccessible(true);

        return $method->invoke(new SpamFilter, $form);
    }

    public function test_allows_three_per_hour_then_flags(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $form = ['id' => 42];

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->exceeds($form);
        }

        $this->assertSame([false, false, false, true, true], $results);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_separate_ips_have_separate_counters(): void
    {
        $form = ['id' => 42];

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->exceeds($form);
        $this->exceeds($form);
        $this->exceeds($form);

        // A different IP is unaffected by the first IP's count.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $this->assertFalse($this->exceeds($form));

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_unknown_ip_is_never_penalised(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $form = ['id' => 42];

        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($this->exceeds($form));
        }
    }
}
