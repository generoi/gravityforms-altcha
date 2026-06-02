<?php

namespace Genero\GravityFormsAltcha;

/**
 * Optional real-time email validation for Gravity Forms email fields, via the
 * Bouncer API (https://usebouncer.com). When enabled, submissions whose email
 * is undeliverable or disposable are rejected at the field with a corrective
 * message — unlike the spam layers, this *blocks* because the right outcome is
 * to help the visitor fix a bad address (otherwise you could never reply).
 *
 * Safety:
 *  - Off by default; controlled by a global toggle in {@see Settings}.
 *  - Fails open — any uncertainty (risky/unknown), a missing API key, or an API
 *    error never blocks the submission. Only a definitive "undeliverable" or
 *    "disposable" verdict does.
 *  - Caches definitive verdicts for a day, keyed by a hash of the email.
 *
 * Privacy: enabling this sends the submitted email address to Bouncer, a
 * third-party processor. Ensure your privacy policy / DPA covers it.
 *
 * Requires the BOUNCER_API_KEY environment variable (or the
 * `genero/gravityforms_altcha/bouncer_api_key` filter).
 */
class EmailValidator
{
    private const CACHE_PREFIX = 'gfaltcha_email_';

    private const CACHE_TTL = DAY_IN_SECONDS;

    private const HTTP_TIMEOUT = 10;

    public static function register(): void
    {
        add_filter('gform_field_validation', [new self, 'validateField'], 10, 4);
    }

    /**
     * @param  array{is_valid?: bool, message?: string}  $result
     * @param  mixed  $value
     * @param  array<string, mixed>  $form
     * @param  object  $field
     * @return array{is_valid: bool, message?: string}
     */
    public function validateField($result, $value, $form, $field)
    {
        if (! Settings::emailValidationEnabled()) {
            return $result;
        }

        if (! is_object($field) || ($field->type ?? '') !== 'email') {
            return $result;
        }

        if (! ($result['is_valid'] ?? true)) {
            return $result; // already invalid (e.g. required/format) — leave it
        }

        $email = is_array($value) ? ($value[0] ?? '') : (string) $value;
        if ($email === '') {
            return $result;
        }

        /**
         * Filters whether to validate this field/form. Return false to skip.
         */
        if (! apply_filters('genero/gravityforms_altcha/email_should_validate', true, $field, $form)) {
            return $result;
        }

        if (! self::validate($email)['block']) {
            return $result;
        }

        /**
         * Filters the message shown when an email is rejected.
         */
        $message = apply_filters(
            'genero/gravityforms_altcha/email_error_message',
            __('Please enter a valid, reachable email address.', 'gravityforms-altcha'),
            $field,
            $form,
        );

        return ['is_valid' => false, 'message' => $message];
    }

    /**
     * @return array{status: string, disposable: bool, role: bool, reason: ?string, block: bool}
     */
    public static function validate(string $email): array
    {
        $email = strtolower(trim($email));

        if ($email === '' || ! is_email($email)) {
            return self::result('undeliverable', reason: 'invalid_syntax');
        }

        $cacheKey = self::CACHE_PREFIX.hash('sha256', $email);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = self::bouncer($email);

        if (self::isDefinitive($result)) {
            set_transient($cacheKey, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Whether a result reflects a real provider verdict (vs. a transient infra
     * failure we shouldn't cache for a day).
     *
     * @param  array{reason?: ?string}  $result
     */
    public static function isDefinitive(array $result): bool
    {
        $reason = (string) ($result['reason'] ?? '');

        if (str_starts_with($reason, 'http_')) {
            return false;
        }

        return ! in_array($reason, ['missing_api_key', 'malformed_response'], true);
    }

    /**
     * Normalised verdict. Only a definitive undeliverable/disposable blocks.
     *
     * @return array{status: string, disposable: bool, role: bool, reason: ?string, block: bool}
     */
    public static function result(string $status, bool $disposable = false, bool $role = false, ?string $reason = null): array
    {
        $block = $disposable || $status === 'undeliverable';

        return compact('status', 'disposable', 'role', 'reason', 'block');
    }

    /**
     * Bouncer real-time single email verify.
     *
     * @see https://docs.usebouncer.com/api-reference/real-time/verify-email
     *
     * @return array{status: string, disposable: bool, role: bool, reason: ?string, block: bool}
     */
    private static function bouncer(string $email): array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return self::result('unknown', reason: 'missing_api_key');
        }

        $response = wp_remote_get(
            'https://api.usebouncer.com/v1.1/email/verify?'.http_build_query(['email' => $email, 'timeout' => 8]),
            [
                'timeout' => self::HTTP_TIMEOUT,
                'headers' => ['x-api-key' => $apiKey],
            ],
        );

        if (is_wp_error($response)) {
            return self::result('unknown', reason: 'http_error:'.$response->get_error_code());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return self::result('unknown', reason: 'http_status:'.$code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body) || ! isset($body['status'])) {
            return self::result('unknown', reason: 'malformed_response');
        }

        $status = in_array($body['status'], ['deliverable', 'undeliverable', 'risky', 'unknown'], true)
            ? $body['status']
            : 'unknown';

        $disposable = ($body['account']['disposable'] ?? 'no') === 'yes'
            || ($body['domain']['disposable'] ?? 'no') === 'yes';

        $role = ($body['account']['role'] ?? 'no') === 'yes';

        return self::result($status, $disposable, $role, $body['reason'] ?? null);
    }

    private static function apiKey(): string
    {
        /**
         * Filters the Bouncer API key. Defaults to the BOUNCER_API_KEY env var.
         */
        $key = (string) apply_filters('genero/gravityforms_altcha/bouncer_api_key', '');
        if ($key !== '') {
            return $key;
        }

        $env = getenv('BOUNCER_API_KEY');
        if ($env === false || $env === '') {
            $env = $_ENV['BOUNCER_API_KEY'] ?? $_SERVER['BOUNCER_API_KEY'] ?? '';
        }

        return (string) $env;
    }
}
