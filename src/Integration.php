<?php

namespace Genero\GravityFormsAltcha;

class Integration
{
    public const POST_FIELD = 'altcha';

    public const SCRIPT_HANDLE = 'gravityforms-altcha-widget';

    public static function register(): void
    {
        $instance = new self;
        add_filter('gform_submit_button', [$instance, 'injectWidget'], 10, 2);
        add_filter('gform_validation', [$instance, 'validate']);
        add_action('gform_enqueue_scripts', [$instance, 'enqueueScripts'], 10, 2);
    }

    /**
     * Renders the ALTCHA web component right above the submit button.
     * `auto=onload` kicks off the proof-of-work the moment the form is rendered
     * so it's almost always done by the time a human finishes typing.
     * `display=invisible` (widget v3) renders zero UI — only a hidden `altcha`
     * input remains in the DOM, which Gravity Forms posts back like any other
     * field. `hidefooter` is a defensive belt-and-braces in case the widget
     * ever falls back to a visible mode.
     *
     * @param  array<string, mixed>  $form
     */
    public function injectWidget(string $buttonInput, array $form): string
    {
        if (! $this->shouldProtect($form)) {
            return $buttonInput;
        }

        $formId = isset($form['id']) ? (int) $form['id'] : null;
        $endpoint = esc_url(ChallengeEndpoint::url($formId ?: null));
        $widget = sprintf(
            '<altcha-widget challenge="%s" auto="onload" display="invisible" hidefooter></altcha-widget>',
            $endpoint
        );

        return $widget.$buttonInput;
    }

    /**
     * Rejects the submission when the proof is missing, malformed, or doesn't
     * verify. We attach the error message to the form's `validation_summary`
     * so themes that style validation errors at the form level surface it; we
     * deliberately don't attach it to a per-field error since there's no
     * visible field to point at.
     *
     * @param  array{is_valid: bool, form: array<string, mixed>}  $result
     * @return array{is_valid: bool, form: array<string, mixed>}
     */
    public function validate(array $result): array
    {
        if (! $this->shouldProtect($result['form'])) {
            return $result;
        }

        $payload = isset($_POST[self::POST_FIELD]) && is_string($_POST[self::POST_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::POST_FIELD]))
            : '';

        if ($this->challenge()->verify($payload) && ! $this->isReplay($payload)) {
            return $result;
        }

        $result['is_valid'] = false;
        $result['form']['validation_summary_message'] = $this->errorMessage();
        $result['form']['failed_validation_page'] = $result['form']['page_count'] ?? 1;

        return $result;
    }

    /**
     * One-time-use enforcement. A signed challenge stays verifiable until it
     * expires, so without this a bot could solve the proof once and replay the
     * same payload across many submissions, paying the proof-of-work cost only
     * once. We remember each challenge's unique signature for the rest of its
     * lifetime and reject any payload we've already accepted.
     *
     * Only called after a successful verify(), so we never store fingerprints
     * for forged/garbage payloads. Returns false (don't block) when the payload
     * can't be fingerprinted — verify() already vouched for it.
     *
     * The check-then-set isn't atomic, so two truly simultaneous replays of the
     * same payload could both slip through; that single-extra-submission race is
     * an acceptable trade for not depending on an atomic cache backend.
     */
    private function isReplay(string $payload): bool
    {
        $fingerprint = $this->challenge()->fingerprint($payload);
        if ($fingerprint === null) {
            return false;
        }

        $key = 'gfaltcha_seen_'.substr(hash('sha256', $fingerprint['signature']), 0, 32);

        if (get_transient($key)) {
            return true;
        }

        // Scope the record to the challenge's remaining life; once it expires
        // the payload can't verify anyway. Fall back to an hour if unknown.
        $ttl = $fingerprint['expiresAt'] !== null
            ? max(MINUTE_IN_SECONDS, $fingerprint['expiresAt'] - time())
            : HOUR_IN_SECONDS;

        set_transient($key, 1, $ttl);

        return false;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public function enqueueScripts(array $form, bool $is_ajax): void
    {
        if (! $this->shouldProtect($form)) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            Plugin::getInstance()->url.'build/widget.js',
            [],
            Plugin::VERSION,
            ['strategy' => 'defer', 'in_footer' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function shouldProtect(array $form): bool
    {
        /**
         * Filters whether ALTCHA should protect this form. The default is
         * derived from the add-on settings — opt-in via the global "Enable for
         * all forms" toggle or the per-form toggle (see {@see Settings}). Pass
         * a hard-coded boolean here to override the saved settings, e.g. to
         * force protection on a specific form ID regardless of its toggle.
         */
        return (bool) apply_filters(
            'genero/gravityforms_altcha/should_protect',
            Settings::isEnabledForForm($form),
            $form,
        );
    }

    private function challenge(): Challenge
    {
        return new Challenge(Plugin::getInstance()->hmacKey());
    }

    private function errorMessage(): string
    {
        /**
         * Filters the validation message shown when a submission fails the
         * captcha. Keep it generic — telling bots exactly what failed is a
         * minor information leak.
         */
        return (string) apply_filters(
            'genero/gravityforms_altcha/error_message',
            __('Sorry, your submission could not be verified. Please reload the page and try again.', 'gravityforms-altcha'),
        );
    }
}
