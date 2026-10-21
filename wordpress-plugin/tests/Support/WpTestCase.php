<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Support;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Brain Monkey lifecycle plus Mockery integration, so `Functions\expect()` calls
 * count as assertions and unmet expectations fail the test.
 */
abstract class WpTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
