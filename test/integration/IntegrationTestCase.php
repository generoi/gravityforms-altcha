<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Integration;

use WP_UnitTestCase;

/**
 * Base for real-WordPress integration tests. Gravity Forms is commercial and
 * absent in CI, so GF-dependent tests skip there and run on DDEV / wp-env.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    protected function requireGravityForms(): void
    {
        if (! class_exists('GFForms') || ! class_exists('GFAPI') || ! class_exists('GFAddOn')) {
            $this->markTestSkipped('Gravity Forms is not active.');
        }
    }
}
