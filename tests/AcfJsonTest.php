<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Acf\AcfJson;

final class AcfJsonTest extends TestCase
{
    /**
     * @param string $param
     * @return array<string, mixed>
     */
    private function groupOn(string $param, string $value): array
    {
        return [
            'key' => 'group_x',
            'fields' => [],
            'location' => [[['param' => $param, 'operator' => '==', 'value' => $value]]],
        ];
    }

    public function testEverySeedableParamIsReturned(): void
    {
        foreach (AcfJson::SEEDABLE_PARAMS as $param) {
            $targets = AcfJson::targets($this->groupOn($param, 'x'));

            self::assertSame([['param' => $param, 'value' => 'x']], $targets, "param {$param} should be seedable");
        }
    }

    public function testUnseedableParamsAreSkipped(): void
    {
        self::assertSame([], AcfJson::targets($this->groupOn('taxonomy', 'category')));
        self::assertSame([], AcfJson::targets($this->groupOn('current_user_role', 'administrator')));
    }

    public function testNonEqualityOperatorsAreSkipped(): void
    {
        $group = [
            'key' => 'group_x',
            'fields' => [],
            'location' => [[['param' => 'post_type', 'operator' => '!=', 'value' => 'event']]],
        ];

        self::assertSame([], AcfJson::targets($group));
    }

    public function testDuplicateRulesAreDeduped(): void
    {
        $group = [
            'key' => 'group_x',
            'fields' => [],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'event']],
                [['param' => 'post_type', 'operator' => '==', 'value' => 'event']],
            ],
        ];

        self::assertSame([['param' => 'post_type', 'value' => 'event']], AcfJson::targets($group));
    }
}
