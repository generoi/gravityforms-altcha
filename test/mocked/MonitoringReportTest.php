<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Genero\GravityFormsAltcha\Challenge;
use Genero\GravityFormsAltcha\Integration;
use Genero\GravityFormsAltcha\Plugin;
use ReflectionMethod;

/**
 * Exercises the report to gds-monitoring: only rejections that took a real,
 * signed challenge reach it, since those turn real people away.
 */
class MonitoringReportTest extends MockedTestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];

        Functions\when('apply_filters')->alias(
            fn (string $hook, $value = null) => $hook === 'genero/gravityforms_altcha/should_protect'
                ? true
                : $value
        );
        Functions\when('plugin_dir_path')->returnArg(1);
        Functions\when('plugin_dir_url')->returnArg(1);
        Functions\when('get_option')->justReturn(self::KEY);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('get_transient')->alias(fn ($k) => $this->store[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->store[$k] = $v;

            return true;
        });

        Plugin::getInstance(dirname(__DIR__, 2).'/gravityforms-altcha.php');
    }

    protected function tearDown(): void
    {
        unset($_POST[Integration::POST_FIELD]);
        parent::tearDown();
    }

    public function test_a_missing_proof_is_not_reported(): void
    {
        Actions\expectDone('gds/monitoring/report')->never();

        $this->validate(45);
    }

    public function test_a_proof_signed_with_another_key_is_reported(): void
    {
        $altcha = new Altcha('old-key');
        $foreign = (new Challenge('old-key', cost: 1000))->create();
        $solution = $altcha->solveChallenge(new SolveChallengeOptions(algorithm: new Pbkdf2, challenge: $foreign));
        $_POST[Integration::POST_FIELD] = (new Payload($foreign, $solution))->toBase64();

        Actions\expectDone('gds/monitoring/report')
            ->once()
            ->with('altcha', 'rejected form 45 (invalid_signature)', false);

        $this->validate(45);
    }

    public function test_an_expired_proof_never_accepted_is_reported(): void
    {
        $_POST[Integration::POST_FIELD] = $this->solvedPayload(expiresSeconds: -60);

        Actions\expectDone('gds/monitoring/report')
            ->once()
            ->with('altcha', \Mockery::pattern('/^rejected form 45 \(expired\): \d+ s past expiry$/'), false);

        $this->validate(45);
    }

    /**
     * A browser re-sending a submission that went through (an in-app browser
     * restoring a tab hours later): its entry exists, nobody was turned away.
     */
    public function test_an_expired_proof_accepted_before_is_not_reported(): void
    {
        $payload = $this->solvedPayload(expiresSeconds: -60);
        $accept = new ReflectionMethod(Integration::class, 'isReplay');
        $accept->setAccessible(true);
        $this->assertFalse($accept->invoke(new Integration, $payload), 'recorded as accepted');
        $_POST[Integration::POST_FIELD] = $payload;

        Actions\expectDone('gds/monitoring/report')->never();

        $this->validate(45);
    }

    private function solvedPayload(int $expiresSeconds): string
    {
        $challenge = (new Challenge(self::KEY, cost: 1000, expiresSeconds: $expiresSeconds))->create();
        $solution = (new Altcha(self::KEY))->solveChallenge(new SolveChallengeOptions(algorithm: new Pbkdf2, challenge: $challenge));

        return (new Payload($challenge, $solution))->toBase64();
    }

    private function validate(int $formId): void
    {
        $result = (new Integration)->validate(['is_valid' => true, 'form' => ['id' => $formId]]);

        $this->assertFalse($result['is_valid']);
    }
}
