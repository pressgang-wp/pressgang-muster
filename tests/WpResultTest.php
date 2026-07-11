<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Support\WpResult;

final class WpResultTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testPositiveIntIsId(): void
    {
        self::assertSame(true, WpResult::isId(42));
    }

    public function testFailureShapesAreNotIds(): void
    {
        self::assertSame(false, WpResult::isId(new \WP_Error('nope')));
        self::assertSame(false, WpResult::isId(0));
        self::assertSame(false, WpResult::isId(-1));
        self::assertSame(false, WpResult::isId('7'));
        self::assertSame(false, WpResult::isId(null));
    }
}
