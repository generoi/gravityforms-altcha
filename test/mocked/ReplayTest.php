<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey\Functions;
use Genero\GravityFormsAltcha\Integration;
use Genero\GravityFormsAltcha\Plugin;
use ReflectionMethod;

/**
 * Exercises Integration::isReplay() — one-time-use dedup over transients, and
 * the don't-block-when-unparseable guard.
 */
class ReplayTest extends MockedTestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];

        // hmacKey(): the filter returns null (returnArg), so get_option supplies it.
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('plugin_dir_path')->returnArg(1);
        Functions\when('plugin_dir_url')->returnArg(1);
        Functions\when('get_option')->justReturn('0123456789abcdef0123456789abcdef');
        Functions\when('get_transient')->alias(fn ($k) => $this->store[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->store[$k] = $v;

            return true;
        });

        // Integration::isReplay() builds a Challenge via Plugin::getInstance().
        Plugin::getInstance(dirname(__DIR__, 2).'/gravityforms-altcha.php');
    }

    private function isReplay(string $payload): bool
    {
        $method = new ReflectionMethod(Integration::class, 'isReplay');
        $method->setAccessible(true);

        return $method->invoke(new Integration, $payload);
    }

    private function payloadWithSignature(string $signature): string
    {
        return base64_encode(json_encode([
            'challenge' => [
                'signature' => $signature,
                'parameters' => ['expiresAt' => time() + 600],
            ],
            'solution' => ['counter' => 1, 'derivedKey' => 'ab'],
        ]));
    }

    public function test_first_use_passes_then_replay_is_blocked(): void
    {
        $payload = $this->payloadWithSignature('aaaa1111');

        $this->assertFalse($this->isReplay($payload), 'first submission is not a replay');
        $this->assertTrue($this->isReplay($payload), 'second submission of the same payload is a replay');
    }

    public function test_distinct_challenges_do_not_collide(): void
    {
        $this->assertFalse($this->isReplay($this->payloadWithSignature('sig-a')));
        $this->assertFalse($this->isReplay($this->payloadWithSignature('sig-b')));
    }

    public function test_unparseable_payload_is_not_treated_as_replay(): void
    {
        $this->assertFalse($this->isReplay('not-base64-or-json'));
    }
}
