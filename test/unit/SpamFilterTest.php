<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests;

use Genero\GravityFormsAltcha\SpamFilter;
use PHPUnit\Framework\TestCase;

class SpamFilterTest extends TestCase
{
    private const KEYWORDS = ['viagra', 'casino'];

    private const THRESHOLD = 3;

    private function isSpam(string $text): bool
    {
        return SpamFilter::contentIsSpam($text, self::KEYWORDS, self::THRESHOLD);
    }

    public function test_empty_text_is_never_spam(): void
    {
        $this->assertFalse($this->isSpam(''));
        $this->assertFalse($this->isSpam('   '));
    }

    public function test_legitimate_message_is_not_spam(): void
    {
        $this->assertFalse($this->isSpam('Hei, tuotteenne oli loistava! Mistä saan lisää? Terveisin Matti'));
    }

    public function test_definite_keyword_flags_on_a_single_hit(): void
    {
        $this->assertTrue($this->isSpam('Buy VIAGRA now'));
        $this->assertTrue($this->isSpam('Welcome to the best CASINO online'));
    }

    public function test_a_single_link_is_allowed(): void
    {
        $this->assertFalse($this->isSpam('See our recipe at https://example.com, looks great!'));
    }

    public function test_three_links_alone_do_not_flag(): void
    {
        // One weak signal (score 2) stays below the threshold — err toward real users.
        $this->assertFalse($this->isSpam('http://a.com and http://b.com and http://c.com'));
    }

    public function test_a_lone_foreign_word_is_allowed(): void
    {
        $this->assertFalse($this->isSpam('Спасибо'));
    }

    public function test_two_combined_signals_flag(): void
    {
        // Link farm (2) + injected anchor markup (2) = 4 >= 3.
        $this->assertTrue($this->isSpam('http://a.com http://b.com http://c.com <a href="http://x.com">x</a>'));
        // Wrong-script text (2) + link farm (2) = 4 >= 3.
        $this->assertTrue($this->isSpam('Спасибо http://a.com http://b.com http://c.com'));
    }

    public function test_keywords_are_case_insensitive(): void
    {
        $this->assertTrue($this->isSpam('ViAgRa'));
    }

    public function test_resolve_ip_uses_remote_addr_by_default(): void
    {
        $this->assertSame('203.0.113.9', SpamFilter::resolveIp(['REMOTE_ADDR' => '203.0.113.9'], ['REMOTE_ADDR']));
    }

    public function test_resolve_ip_respects_header_precedence(): void
    {
        $server = ['HTTP_CF_CONNECTING_IP' => '198.51.100.7', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('198.51.100.7', SpamFilter::resolveIp($server, ['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR']));
    }

    public function test_resolve_ip_takes_first_of_a_forwarded_list(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '203.0.113.5, 70.41.3.18, 150.172.238.178'];
        $this->assertSame('203.0.113.5', SpamFilter::resolveIp($server, ['HTTP_X_FORWARDED_FOR']));
    }

    public function test_resolve_ip_skips_invalid_and_falls_through(): void
    {
        $server = ['HTTP_X_REAL_IP' => 'not-an-ip', 'REMOTE_ADDR' => '203.0.113.9'];
        $this->assertSame('203.0.113.9', SpamFilter::resolveIp($server, ['HTTP_X_REAL_IP', 'REMOTE_ADDR']));
    }

    public function test_resolve_ip_returns_null_when_nothing_valid(): void
    {
        $this->assertNull(SpamFilter::resolveIp(['REMOTE_ADDR' => 'garbage'], ['REMOTE_ADDR']));
        $this->assertNull(SpamFilter::resolveIp([], ['REMOTE_ADDR']));
    }
}
