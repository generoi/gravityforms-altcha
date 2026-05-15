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

        $endpoint = esc_url(ChallengeEndpoint::url());
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

        if ($this->challenge()->verify($payload)) {
            return $result;
        }

        $result['is_valid'] = false;
        $result['form']['validation_summary_message'] = $this->errorMessage();
        $result['form']['failed_validation_page'] = $result['form']['page_count'] ?? 1;

        return $result;
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
         * Filters whether ALTCHA should protect this form. Default `true` — the
         * plugin is opt-out, since the whole point is silent universal coverage.
         * Sites that want per-form scoping can flip the default to `false` and
         * enable on specific form IDs via this filter.
         */
        return (bool) apply_filters('genero/gravityforms_altcha/should_protect', true, $form);
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
