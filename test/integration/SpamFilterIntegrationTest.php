<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Integration;

use Genero\GravityFormsAltcha\Settings;

/**
 * End-to-end content filtering + rate limiting through the real
 * `gform_entry_is_spam` filter chain, a real GF form, and real transients.
 */
class SpamFilterIntegrationTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $formIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireGravityForms();

        $addon = Settings::get_instance();
        $addon->update_plugin_settings(array_merge($addon->get_plugin_settings() ?: [], [
            'enable_content_filter' => '1',
            'enable_rate_limit' => '',
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->formIds as $id) {
            \GFAPI::delete_form($id);
        }
        $this->formIds = [];
        parent::tearDown();
    }

    private function makeForm(): array
    {
        $id = \GFAPI::add_form([
            'title' => 'Spam test form',
            'fields' => [
                ['id' => 1, 'type' => 'name', 'inputs' => [['id' => '1.3'], ['id' => '1.6']]],
                ['id' => 2, 'type' => 'textarea'],
            ],
        ]);
        $this->formIds[] = (int) $id;

        return \GFAPI::get_form($id);
    }

    private function isSpam(array $form, array $entry): bool
    {
        return (bool) apply_filters('gform_entry_is_spam', false, $form, $entry);
    }

    public function test_keyword_in_message_is_flagged(): void
    {
        $this->assertTrue($this->isSpam($this->makeForm(), ['2' => 'cheap VIAGRA, best price']));
    }

    public function test_url_in_name_field_is_flagged(): void
    {
        $this->assertTrue($this->isSpam($this->makeForm(), [
            '1.3' => 'http://spam.example', '1.6' => 'x', '2' => 'hello',
        ]));
    }

    public function test_clean_submission_passes(): void
    {
        $this->assertFalse($this->isSpam($this->makeForm(), [
            '1.3' => 'Matti', '1.6' => 'Meikäläinen', '2' => 'Kiitos hyvästä tuotteesta!',
        ]));
    }

    public function test_rate_limit_flags_beyond_the_allowance(): void
    {
        Settings::get_instance()->update_plugin_settings(array_merge(
            Settings::get_instance()->get_plugin_settings() ?: [],
            ['enable_rate_limit' => '1', 'enable_content_filter' => ''],
        ));
        add_filter('genero/gravityforms_altcha/client_ip_headers', fn () => ['REMOTE_ADDR']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.200';

        $form = $this->makeForm();
        $entry = ['1.3' => 'Matti', '1.6' => 'M', '2' => 'hi'];

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->isSpam($form, $entry);
        }

        // Default 3/hour → first three pass, the rest are flagged.
        $this->assertSame([false, false, false, true, true], $results);

        unset($_SERVER['REMOTE_ADDR']);
    }
}
