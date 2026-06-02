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

        if (Settings::contentFilterEnabled()) {
            $text = $this->extractText($form, $entry);
            if (self::contentIsSpam($text['body'], $text['identity'], self::keywords(), self::scoreThreshold())) {
                return true;
            }
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
     * Gathers the visitor-entered free text for scoring. Returns the full body
     * plus, separately, the identity (name) text — a URL there is a far stronger
     * signal than one in a message, so the scorer weighs it more.
     *
     * Walks composite fields (name/address store values in sub-inputs), which a
     * naive `rgar($entry, $field->id)` would miss entirely.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $entry
     * @return array{body: string, identity: string}
     */
    private function extractText(array $form, array $entry): array
    {
        $bodyTypes = ['text', 'textarea', 'name', 'address', 'website', 'post_title', 'post_content', 'post_excerpt'];

        $body = '';
        $identity = '';

        foreach ($form['fields'] ?? [] as $field) {
            $type = $field->type ?? '';
            $value = $this->fieldValue($field, $entry);
            if ($value === '') {
                continue;
            }

            if (in_array($type, $bodyTypes, true)) {
                $body .= ' '.$value;
            }

            if ($type === 'name') {
                $identity .= ' '.$value;
            }
        }

        return ['body' => trim($body), 'identity' => trim($identity)];
    }

    /**
     * Reads a field's submitted value, joining sub-inputs for composite fields
     * (name, address) so their text is actually seen.
     *
     * @param  object  $field
     * @param  array<string, mixed>  $entry
     */
    private function fieldValue($field, array $entry): string
    {
        if (! empty($field->inputs) && is_array($field->inputs)) {
            $parts = [];
            foreach ($field->inputs as $input) {
                $parts[] = (string) rgar($entry, (string) ($input['id'] ?? ''));
            }

            return trim(implode(' ', array_filter($parts)));
        }

        return (string) rgar($entry, (string) ($field->id ?? ''));
    }

    /**
     * Pure spam test (no WordPress/GF dependencies, so it is unit-testable).
     * A definite keyword flags on a single hit; otherwise weaker signals must
     * accumulate to the threshold — so a lone link or foreign word never trips
     * it, keeping false positives near zero.
     *
     * @param  string  $body  All visitor free text.
     * @param  string  $identity  Just the name field(s); a URL here is damning.
     * @param  array<int, string>  $keywords
     */
    public static function contentIsSpam(string $body, string $identity, array $keywords, int $threshold): bool
    {
        $identity = self::normalize($identity);
        $body = self::normalize($body);
        $combined = trim($identity.' '.$body);

        if ($combined === '') {
            return false;
        }

        // Definite-spam keywords — matched on word boundaries (unicode-aware) so
        // a keyword can't trip on a substring of an innocent word.
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && self::containsWord($combined, $keyword)) {
                return true;
            }
        }

        $score = 0;

        // Links (http(s):// and scheme-less www.), graduated: a single link is
        // innocent, a pile of them is not.
        $links = self::countLinks($combined);
        if ($links >= 5) {
            $score += 3;
        } elseif ($links >= 3) {
            $score += 2;
        }

        // A URL in the name field is near-certain spam — real names aren't links.
        if ($identity !== '' && self::countLinks($identity) >= 1) {
            $score += 3;
        }

        if (preg_match('~\[url=|</?a\s~i', $combined)) {
            $score += 2; // injected markup
        }

        if (preg_match('~[\p{Cyrillic}\p{Han}\p{Hangul}]~u', $combined)) {
            $score += 2; // wrong script for a FI/SV site
        }

        return $score >= $threshold;
    }

    /**
     * Strips zero-width / invisible characters used to break keyword and link
     * matching, and collapses whitespace.
     */
    private static function normalize(string $text): string
    {
        $text = preg_replace('~[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]~u', '', $text) ?? $text;

        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    /**
     * Case-insensitive, unicode-aware whole-word match (avoids the Scunthorpe
     * problem when a short keyword is configured).
     */
    private static function containsWord(string $text, string $word): bool
    {
        return (bool) preg_match('~(?<![\p{L}\p{N}])'.preg_quote($word, '~').'(?![\p{L}\p{N}])~iu', $text);
    }

    /**
     * Counts distinct URLs — `https?://…` or a scheme-less `www.…` — without
     * double-counting `http://www.…` as two.
     */
    private static function countLinks(string $text): int
    {
        return (int) preg_match_all('~https?://\S+|(?<![\w@.])www\.\S+~i', $text);
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
