<?php

namespace Genero\GravityFormsAltcha;

/**
 * Records each protection decision so behaviour can be verified.
 *
 * Every decision fires the `genero/gravityforms_altcha/log` action; the default
 * listener (wired in {@see Plugin}) routes it into Gravity Forms' own logging
 * framework, so it shows up under Forms → Settings → Logging with a per-add-on
 * level (off / errors only / all) and a downloadable log file. Failures log at
 * ERROR level, everything else at DEBUG, so "log errors only" surfaces just the
 * blocks/failures.
 *
 * The action is also a clean extension point for routing elsewhere (Sentry,
 * Query Monitor, …). Privacy: callers pass only non-PII context — a form id, a
 * salted IP hash, an email domain — never a raw IP or full address.
 */
class Logger
{
    public const HOOK = 'genero/gravityforms_altcha/log';

    /**
     * @param  string  $event  e.g. altcha | rate_limit | content_filter | email_validation
     * @param  string  $outcome  e.g. pass | fail | blocked | spam | allowed
     * @param  array<string, mixed>  $context  non-PII detail
     */
    public static function record(string $event, string $outcome, array $context = []): void
    {
        do_action(self::HOOK, $event, $outcome, $context);
    }

    /**
     * Whether an outcome represents a failure/block — logged at error level so
     * it surfaces under GF's "log errors only".
     */
    public static function isFailure(string $outcome): bool
    {
        return in_array($outcome, ['fail', 'blocked', 'spam'], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function format(string $event, string $outcome, array $context = []): string
    {
        $suffix = $context !== [] ? ' '.json_encode($context) : '';

        return sprintf('%s: %s%s', $event, $outcome, $suffix);
    }
}
