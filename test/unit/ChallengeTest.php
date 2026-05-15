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
}
