<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Integration;

use Genero\GravityFormsAltcha\Challenge;
use Genero\GravityFormsAltcha\Settings;

/**
 * Cost resolution through the real GF add-on settings + form meta.
 */
class SettingsIntegrationTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $formIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireGravityForms();
        // Reset global settings to a known baseline.
        Settings::get_instance()->update_plugin_settings([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->formIds as $id) {
            \GFAPI::delete_form($id);
        }
        $this->formIds = [];
        parent::tearDown();
    }

    private function setGlobal(array $settings): void
    {
        $addon = Settings::get_instance();
        $addon->update_plugin_settings(array_merge($addon->get_plugin_settings() ?: [], $settings));
    }

    public function test_cost_defaults_to_plugin_default(): void
    {
        $this->assertSame(Challenge::DEFAULT_COST, Settings::costForForm(null));
    }

    public function test_global_cost_setting_is_applied(): void
    {
        $this->setGlobal(['cost' => '500000']);
        $this->assertSame(500000, Settings::costForForm(null));
    }

    public function test_per_form_cost_overrides_global(): void
    {
        $this->setGlobal(['cost' => '500000']);

        $formId = \GFAPI::add_form([
            'title' => 'Per-form cost',
            'fields' => [],
            'gravityforms-altcha' => ['cost' => '50000'],
        ]);
        $this->formIds[] = (int) $formId;

        $this->assertSame(50000, Settings::costForForm((int) $formId));
    }

    public function test_out_of_range_cost_is_clamped(): void
    {
        $this->setGlobal(['cost' => '999999999']);
        $this->assertSame(Challenge::MAX_COST, Settings::costForForm(null));
    }

    public function test_email_block_modes_default_to_unset(): void
    {
        $this->assertSame(
            ['undeliverable' => false, 'risky' => false, 'disposable' => false],
            Settings::emailBlockModes(),
        );
    }
}
