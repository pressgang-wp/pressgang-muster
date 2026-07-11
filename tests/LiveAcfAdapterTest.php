<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Adapters\LiveAcfAdapter;

final class LiveAcfAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testPostFieldsPassThroughWithIntTarget(): void
    {
        (new LiveAcfAdapter())->updateFields(['headline' => 'Hello', 'count' => 3], 'post', 42);

        self::assertSame(
            [
                ['selector' => 'headline', 'value' => 'Hello', 'target' => 42],
                ['selector' => 'count', 'value' => 3, 'target' => 42],
            ],
            $GLOBALS['__muster_acf_updates']
        );
    }

    public function testTermUserAndOptionTargetsAreTranslated(): void
    {
        $adapter = new LiveAcfAdapter();
        $adapter->updateFields(['colour' => 'red'], 'term', 7);
        $adapter->updateFields(['role_note' => 'x'], 'user', 9);
        $adapter->updateFields(['footer_text' => 'y'], 'option', 0);

        $targets = array_column($GLOBALS['__muster_acf_updates'], 'target');
        self::assertSame(['term_7', 'user_9', 'option'], $targets);
    }

    public function testComplexValuesPassThroughUntouched(): void
    {
        $rows = [['heading' => 'One'], ['heading' => 'Two']];
        (new LiveAcfAdapter())->updateFields(['slides' => $rows], 'post', 5);

        self::assertSame($rows, $GLOBALS['__muster_acf_updates'][0]['value']);
    }
}
