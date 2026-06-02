<?php

namespace Genero\GravityFormsAltcha;

/**
 * Two lightweight, fully first-party spam layers on top of the proof-of-work,
 * controlled by the global toggles in {@see Settings}. Both act through
 * `gform_entry_is_spam`, so a flagged submission is *marked as spam* (stored,
 * hidden from the entry list, notifications suppressed) rather than rejected —
 * a real user is never blocked or shown an error, and a false positive is
 * recoverable from the entry "Spam" view. Each layer has its own global toggle
 * and, when on, applies to every Gravity Form — independent of whether ALTCHA
 * itself is enabled for the form.
 *
 *  1. Rate limiting — flags submissions once an IP exceeds a generous per-form
 *     per-minute threshold (default 2). Catches floods; no human submits one
 *     form that fast.
 *  2. Content heuristics — flags submissions whose free-text contains a
 *     definite-spam keyword, or accumulates enough weaker signals (link farms,
 *     injected markup, wrong-script text for a FI/SV site).
 */
class SpamFilter
{
    public static function register(): void
    {
        $instance = new self;
        add_filter('gform_entry_is_spam', [$instance, 'flag'], 10, 3);
    }

    /**
     * @param  bool  $isSpam
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $entry
     */
    public function flag($isSpam, $form, $entry): bool
    {
        if ($isSpam) {
            return true;
        }

        if (! is_array($form)) {
            return (bool) $isSpam;
        }

        if (Settings::rateLimitEnabled() && $this->exceedsRateLimit($form)) {
            return true;
        }

        if (Settings::contentFilterEnabled()
            && self::contentIsSpam($this->submittedText($form, $entry), self::keywords(), self::scoreThreshold())) {
            return true;
        }

        return (bool) $isSpam;
    }

    /**
     * Per-IP, per-form sliding-ish counter. Allows up to the limit within the
     * window and flags anything beyond it. An unresolvable IP is never
     * penalised.
     *
     * The IP is resolved independently of Gravity Forms (sites commonly blank
     * GF's stored IP for GDPR), and is only ever kept as a salted HMAC in a
     * short-lived transient — the raw IP is never stored or logged.
     *
     * @param  array<string, mixed>  $form
     */
    private function exceedsRateLimit(array $form): bool
    {
        $ip = $this->clientIp();
        if ($ip === null) {
            return false;
        }

        /**
         * Filters the per-IP, per-form submission allowance and the window it
         * applies over. Defaults to 3 submissions per hour — generous enough
         * for a legitimate retry, tight against floods. For "1 per minute"
         * instead, set max 1 and window MINUTE_IN_SECONDS.
         */
        $max = max(1, (int) apply_filters('genero/gravityforms_altcha/rate_limit_max', 3, $form));
        $window = max(1, (int) apply_filters('genero/gravityforms_altcha/rate_limit_window', HOUR_IN_SECONDS, $form));

        $key = 'gfaltcha_rl_'.($form['id'] ?? 0).'_'.self::hashIp($ip);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, $window);

        return $count >= $max;
    }

    private function clientIp(): ?string
    {
        /**
         * Ordered list of $_SERVER keys to read the client IP from. Defaults to
         * REMOTE_ADDR only — the actual TCP peer, which can't be spoofed. If the
         * site sits behind a CDN/proxy, PREPEND the single header that CDN sets,
         * e.g. 'HTTP_CF_CONNECTING_IP' (Cloudflare), 'HTTP_FASTLY_CLIENT_IP'
         * (Fastly), 'HTTP_TRUE_CLIENT_IP' (Akamai) or 'HTTP_X_REAL_IP' (nginx).
         * Never trust a forwarded header your CDN doesn't set: it can be spoofed
         * to evade the limit, or to push a real visitor's IP over it.
         *
         * @var array<int, string> $headers
         */
        $headers = (array) apply_filters('genero/gravityforms_altcha/client_ip_headers', ['REMOTE_ADDR']);

        return self::resolveIp($_SERVER, $headers);
    }

    /**
     * Pure IP resolver (unit-testable): first header that yields a valid IP
     * wins. Handles "client, proxy1, ..." lists by taking the first entry.
     *
     * @param  array<string, mixed>  $server
     * @param  array<int, string>  $headers
     */
    public static function resolveIp(array $server, array $headers): ?string
    {
        foreach ($headers as $header) {
            $value = $server[$header] ?? '';
            if (! is_string($value) || $value === '' || strlen($value) > 200) {
                continue;
            }

            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * GDPR-friendly, non-reversible key for an IP: a keyed HMAC salted with a
     * server secret, so the small IPv4 space can't be brute-forced back to the
     * raw address. Only ever lives in a 60s transient; the IP itself is never
     * stored.
     */
    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    /**
     * Concatenates the visitor-entered free-text fields for scoring.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $entry
     */
    private function submittedText(array $form, array $entry): string
    {
        $text = '';
        foreach ($form['fields'] ?? [] as $field) {
            if (in_array($field->type ?? '', ['text', 'textarea'], true)) {
                $text .= ' '.rgar($entry, (string) $field->id);
            }
        }

        return trim($text);
    }

    /**
     * Pure spam test (no WordPress/GF dependencies, so it is unit-testable).
     * A definite keyword flags on a single hit; otherwise weaker signals must
     * accumulate to the threshold — so a lone link or foreign word never trips
     * it.
     *
     * @param  array<int, string>  $keywords
     */
    public static function contentIsSpam(string $text, array $keywords, int $threshold): bool
    {
        if ($text === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && stripos($text, $keyword) !== false) {
                return true;
            }
        }

        $score = 0;
        if (preg_match_all('~https?://~i', $text) >= 3) {
            $score += 2; // link farm
        }
        if (preg_match('~\[url=|</?a\s~i', $text)) {
            $score += 2; // injected markup
        }
        if (preg_match('~[\p{Cyrillic}\p{Han}\p{Hangul}]~u', $text)) {
            $score += 2; // wrong script for a FI/SV site
        }

        return $score >= $threshold;
    }

    /**
     * @return array<int, string>
     */
    private static function keywords(): array
    {
        /**
         * Filters the list of definite-spam keywords. A single case-insensitive
         * substring match marks the submission as spam, so keep this list to
         * terms that never appear in a legitimate message.
         */
        return (array) apply_filters('genero/gravityforms_altcha/spam_keywords', ['viagra', 'casino']);
    }

    private static function scoreThreshold(): int
    {
        /**
         * Filters the score required for the weaker, additive heuristics to mark
         * a submission as spam. Each signal contributes 2, so the default of 3
         * requires at least two independent signals.
         */
        return (int) apply_filters('genero/gravityforms_altcha/spam_score_threshold', 3);
    }
}
