<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Genero\GravityFormsAltcha\Challenge;
use PHPUnit\Framework\TestCase;

class ChallengeTest extends TestCase
{
    private const SECRET = 'test-secret';

    public function test_create_returns_challenge_with_signature(): void
    {
        $challenge = (new Challenge(self::SECRET))->create();

        $this->assertNotNull($challenge->signature);
        $this->assertNotEmpty($challenge->parameters->salt);
        $this->assertSame(Challenge::DEFAULT_COST, $challenge->parameters->cost);
    }

    public function test_verify_accepts_valid_solution(): void
    {
        $altcha = new Altcha(self::SECRET);
        $challenge = (new Challenge(self::SECRET, cost: 1000))->create();

        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2,
            challenge: $challenge,
        ));
        $this->assertNotNull($solution);

        $payload = (new Payload($challenge, $solution))->toBase64();

        $this->assertTrue((new Challenge(self::SECRET))->verify($payload));
    }

    public function test_verify_rejects_empty_payload(): void
    {
        $this->assertFalse((new Challenge(self::SECRET))->verify(''));
    }

    public function test_verify_rejects_garbage_payload(): void
    {
        $this->assertFalse((new Challenge(self::SECRET))->verify('!!!not-base64!!!'));
    }

    public function test_verify_rejects_payload_signed_with_different_secret(): void
    {
        $altcha = new Altcha('attacker-secret');
        $foreign = (new Challenge('attacker-secret', cost: 1000))->create();

        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2,
            challenge: $foreign,
        ));
        $this->assertNotNull($solution);

        $payload = (new Payload($foreign, $solution))->toBase64();

        $this->assertFalse((new Challenge(self::SECRET))->verify($payload));
    }

    public function test_verify_rejects_payload_with_garbled_solution(): void
    {
        $altcha = new Altcha(self::SECRET);
        $challenge = (new Challenge(self::SECRET, cost: 1000))->create();

        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2,
            challenge: $challenge,
        ));
        $this->assertNotNull($solution);

        $arr = (new Payload($challenge, $solution))->toArray();
        $arr['solution']['derivedKey'] = str_repeat('0', strlen($arr['solution']['derivedKey']));
        $payload = base64_encode(json_encode($arr));

        $this->assertFalse((new Challenge(self::SECRET))->verify($payload));
    }

    /**
     * @return array{0: \AltchaOrg\Altcha\Challenge, 1: string} challenge + base64 payload
     */
    private function solvedPayload(int $expiresSeconds = 600): array
    {
        $altcha = new Altcha(self::SECRET);
        $challenge = (new Challenge(self::SECRET, cost: 1000, expiresSeconds: $expiresSeconds))->create();

        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2,
            challenge: $challenge,
        ));
        $this->assertNotNull($solution);

        return [$challenge, (new Payload($challenge, $solution))->toBase64()];
    }

    public function test_fingerprint_returns_signature_and_expiry(): void
    {
        [$challenge, $payload] = $this->solvedPayload();

        $fingerprint = (new Challenge(self::SECRET))->fingerprint($payload);

        $this->assertIsArray($fingerprint);
        $this->assertSame($challenge->signature, $fingerprint['signature']);
        $this->assertSame($challenge->parameters->expiresAt, $fingerprint['expiresAt']);
    }

    public function test_fingerprint_is_stable_for_the_same_payload(): void
    {
        [, $payload] = $this->solvedPayload();
        $challenge = new Challenge(self::SECRET);

        $this->assertSame(
            $challenge->fingerprint($payload),
            $challenge->fingerprint($payload),
        );
    }

    public function test_fingerprint_differs_across_challenges(): void
    {
        // Two independently issued challenges — as two concurrent visitors would
        // get — must never share a fingerprint, so replay dedup can't collide
        // between distinct users.
        [, $a] = $this->solvedPayload();
        [, $b] = $this->solvedPayload();
        $challenge = new Challenge(self::SECRET);

        $this->assertNotSame(
            $challenge->fingerprint($a)['signature'],
            $challenge->fingerprint($b)['signature'],
        );
    }

    public function test_fingerprint_returns_null_for_unparseable_payloads(): void
    {
        $challenge = new Challenge(self::SECRET);

        $this->assertNull($challenge->fingerprint(''));
        $this->assertNull($challenge->fingerprint('!!!not-base64!!!'));
        $this->assertNull($challenge->fingerprint(base64_encode('not json')));
        $this->assertNull($challenge->fingerprint(base64_encode(json_encode(['no' => 'signature']))));
    }

    public function test_clamp_cost_constrains_to_bounds(): void
    {
        $this->assertSame(Challenge::MIN_COST, Challenge::clampCost(1));
        $this->assertSame(Challenge::MIN_COST, Challenge::clampCost(Challenge::MIN_COST - 1));
        $this->assertSame(Challenge::MAX_COST, Challenge::clampCost(Challenge::MAX_COST + 1));
        $this->assertSame(250000, Challenge::clampCost(250000));
        $this->assertGreaterThanOrEqual(Challenge::MIN_COST, Challenge::DEFAULT_COST);
        $this->assertLessThanOrEqual(Challenge::MAX_COST, Challenge::DEFAULT_COST);
    }
}
