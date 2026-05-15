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
     * Iterations the client must enumerate to find the matching derived key.
     * 10k is the upstream example default — solves in ~50–500 ms on modern
     * hardware, slow enough to deter scripted abuse but invisible to humans.
     */
    public const DEFAULT_COST = 10000;

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
}
