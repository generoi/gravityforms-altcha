<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey\Functions;
use Genero\GravityFormsAltcha\Integration;
use Genero\GravityFormsAltcha\Plugin;

/**
 * Exercises Integration::validationMessage() — the form-level banner shown when
 * ALTCHA rejects a submission.
 *
 * Gravity Forms renders "There was a problem with your submission. Please review
 * the fields below." for any invalid form. An ALTCHA rejection highlights no
 * field, so that instruction points at nothing and sends people looking for a
 * broken field that doesn't exist. These tests pin the replacement — and, just
 * as importantly, that ordinary field errors keep GF's own wording.
 */
class ValidationMessageTest extends MockedTestCase
{
    /** GF's default banner, near enough for assertion purposes. */
    private const GF_DEFAULT = "<h2 class='gform_submission_error'>There was a problem with your submission. Please review the fields below.</h2>";

    protected function setUp(): void
    {
        parent::setUp();

        // Pass filters through to their default, except protection: the
        // GFAddOn stub reports no settings, so force it on explicitly.
        Functions\when('apply_filters')->alias(
            fn (string $hook, $value = null) => $hook === 'genero/gravityforms_altcha/should_protect'
                ? true
                : $value
        );
        Functions\when('plugin_dir_path')->returnArg(1);
        Functions\when('plugin_dir_url')->returnArg(1);
        Functions\when('get_option')->justReturn('0123456789abcdef0123456789abcdef');
        Functions\when('esc_html')->returnArg(1);
        Functions\when('__')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        Plugin::getInstance(dirname(__DIR__, 2).'/gravityforms-altcha.php');
    }

    /**
     * A form we never rejected must keep Gravity Forms' own banner — otherwise
     * a plain "email is required" error would tell the visitor to reload the
     * page, which is worse than useless.
     */
    public function test_leaves_the_banner_alone_for_forms_it_did_not_reject(): void
    {
        $integration = new Integration;

        $this->assertSame(
            self::GF_DEFAULT,
            $integration->validationMessage(self::GF_DEFAULT, ['id' => 45]),
        );
    }

    public function test_replaces_the_banner_for_a_rejected_form(): void
    {
        $integration = $this->rejecting(45);

        $message = $integration->validationMessage(self::GF_DEFAULT, ['id' => 45]);

        $this->assertStringNotContainsString('Please review the fields below', $message);
        $this->assertStringContainsString('could not be verified', $message);
    }

    /**
     * Keeps GF's wrapper classes so existing theme styling still applies to the
     * replacement.
     */
    public function test_replacement_keeps_gravity_forms_markup_hooks(): void
    {
        $message = $this->rejecting(45)->validationMessage(self::GF_DEFAULT, ['id' => 45]);

        $this->assertStringContainsString('gform_submission_error', $message);
        $this->assertStringContainsString('gform-icon--circle-error', $message);
    }

    /**
     * Two forms on one page: rejecting one must not rewrite the other's banner.
     */
    public function test_only_the_rejected_form_is_affected(): void
    {
        $integration = $this->rejecting(45);

        $this->assertSame(
            self::GF_DEFAULT,
            $integration->validationMessage(self::GF_DEFAULT, ['id' => 43]),
        );
        $this->assertStringContainsString(
            'could not be verified',
            $integration->validationMessage(self::GF_DEFAULT, ['id' => 45]),
        );
    }

    /**
     * Drives a real rejection through validate() so the test depends on the
     * actual reject path rather than a hand-set flag.
     */
    private function rejecting(int $formId): Integration
    {
        $integration = new Integration;

        // No `altcha` field posted at all -> REASON_MISSING -> rejected.
        unset($_POST[Integration::POST_FIELD]);

        $result = $integration->validate([
            'is_valid' => true,
            'form' => ['id' => $formId],
        ]);

        $this->assertFalse($result['is_valid'], 'expected validate() to reject the submission');

        return $integration;
    }
}
