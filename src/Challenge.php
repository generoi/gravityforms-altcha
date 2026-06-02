<?php

namespace Genero\GravityFormsAltcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge as AltchaChallenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\ServerSignature;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\VerifySolutionOptions;

/**
 * Thin wrapper around altcha-org/altcha for creating + verifying invisible
 * proof-of-work challenges. PBKDF2/SHA-256 is the default algorithm because
 * it has zero PHP extension requirements; Argon2id (ext-sodium) or Scrypt
 * (ext-scrypt) can be swapped in via a filter if the host needs higher
 * memory-hardness guarantees.
 */
class Challenge
{
    /**
     * PBKDF2 iterations per attempt the client must grind through to solve the
     * challenge. The work scales linearly with this number, so it is the main
     * lever for making each submission more expensive to a bot.
     *
     * 200k is ~20× the upstream example default of 10k. Because the widget runs
     * `auto=onload` in a worker the moment the form renders, the proof is
     * almost always finished long before a human submits, so the higher cost
     * stays invisible in practice. Tune via the `genero/gravityforms_altcha/cost`
     * filter if you support low-end devices.
     */
    public const DEFAULT_COST = 200000;

    /**
     * Bounds for an admin-configured cost. The floor keeps the proof from
     * becoming trivial; the ceiling stops a fat-fingered value from locking
     * real visitors out behind a multi-minute solve.
     */
    public const MIN_COST = 1000;

    public const MAX_COST = 5000000;

    /**
     * Window during which a generated challenge stays usable. Long enough for
     * a user to fill out a form, short enough that captured payloads can't be
     * replayed against a future session.
     */
    public const DEFAULT_EXPIRES_SECONDS = 600;

    public function __construct(
        private readonly string $hmacSecret,
        private readonly int $cost = self::DEFAULT_COST,
        private readonly int $expiresSeconds = self::DEFAULT_EXPIRES_SECONDS,
    ) {}

    public function create(): AltchaChallenge
    {
        $altcha = new Altcha($this->hmacSecret);

        return $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2,
            cost: $this->cost,
            expiresAt: time() + $this->expiresSeconds,
        ));
    }

    /**
     * Constrains a cost to the supported range (see {@see self::MIN_COST} /
     * {@see self::MAX_COST}).
     */
    public static function clampCost(int $cost): int
    {
        return max(self::MIN_COST, min(self::MAX_COST, $cost));
    }

    /**
     * Verifies a base64-encoded payload as posted by the ALTCHA widget. Both
     * client-solution payloads (the common case) and server-signature payloads
     * (used by ALTCHA Sentinel / Spam Filter) are accepted — `altcha-org/altcha`
     * tells the two apart by the presence of `verificationData`.
     */
    public function verify(string $base64Payload): bool
    {
        if ($base64Payload === '') {
            return false;
        }

        $decoded = base64_decode($base64Payload, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return false;
        }

        if (isset($payload['verificationData'])) {
            return ServerSignature::verifyServerSignature($payload, $this->hmacSecret)->verified;
        }

        if (! isset($payload['challenge'], $payload['solution'])
            || ! is_array($payload['challenge'])
            || ! is_array($payload['solution'])
            || ! is_array($payload['challenge']['parameters'] ?? null)
        ) {
            return false;
        }

        $challenge = new AltchaChallenge(
            ChallengeParameters::fromArray($payload['challenge']['parameters']),
            $payload['challenge']['signature'] ?? null,
        );
        $solution = new Solution(
            counter: (int) ($payload['solution']['counter'] ?? 0),
            derivedKey: (string) ($payload['solution']['derivedKey'] ?? ''),
        );

        $altcha = new Altcha($this->hmacSecret);

        return $altcha->verifySolution(new VerifySolutionOptions(
            algorithm: new Pbkdf2,
            payload: new Payload($challenge, $solution),
        ))->verified;
    }

    /**
     * Extracts a stable, unique fingerprint from a solved payload so callers
     * can enforce one-time use (replay protection). The challenge `signature`
     * is an HMAC over the challenge's random salt + nonce, so it uniquely
     * identifies a single issued challenge — two different visitors (or browser
     * tabs) always get distinct signatures, so deduping on it only ever blocks
     * resubmitting the very same solved challenge.
     *
     * `expiresAt` is returned so the caller can scope the dedup record to the
     * challenge's remaining lifetime — past that the payload can't verify
     * anyway, so there's nothing left to replay.
     *
     * @return array{signature: string, expiresAt: ?int}|null Null when the
     *                                                        payload can't be
     *                                                        parsed or carries
     *                                                        no signature.
     */
    public function fingerprint(string $base64Payload): ?array
    {
        if ($base64Payload === '') {
            return null;
        }

        $decoded = base64_decode($base64Payload, true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return null;
        }

        // Client-solution payloads carry the signature under `challenge`;
        // server-signature payloads (Sentinel) carry it at the top level.
        $signature = $payload['challenge']['signature']
            ?? ($payload['signature'] ?? null);

        if (! is_string($signature) || $signature === '') {
            return null;
        }

        $expiresAt = $payload['challenge']['parameters']['expiresAt'] ?? null;

        return [
            'signature' => $signature,
            'expiresAt' => is_int($expiresAt) ? $expiresAt : null,
        ];
    }
}
