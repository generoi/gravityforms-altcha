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

/**
 * Exercises the report to gds-monitoring: only rejections that took a real,
 * signed challenge reach it, since those turn real people away.
 */
class MonitoringReportTest extends MockedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('apply_filters')->alias(
            fn (string $hook, $value = null) => $hook === 'genero/gravityforms_altcha/should_protect'
                ? true
                : $value
        );
        Functions\when('plugin_dir_path')->returnArg(1);
        Functions\when('plugin_dir_url')->returnArg(1);
        Functions\when('get_option')->justReturn('0123456789abcdef0123456789abcdef');
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

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

    private function validate(int $formId): void
    {
        $result = (new Integration)->validate(['is_valid' => true, 'form' => ['id' => $formId]]);

        $this->assertFalse($result['is_valid']);
    }
}
