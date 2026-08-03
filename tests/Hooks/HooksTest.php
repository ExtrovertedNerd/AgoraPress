<?php

/**
 * Unit tests for the full action/filter hook system.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Hooks;

use AP_Hook;
use AP_Hooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Hook::class)]
#[CoversClass(AP_Hooks::class)]
final class HooksTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/hooks.php';
        ap_reset_hooks();
    }

    protected function tearDown(): void
    {
        ap_reset_hooks();
    }

    public function testAddDoActionPriorityOrder(): void
    {
        $order = [];
        ap_add_action('t', static function () use (&$order): void {
            $order[] = 'b';
        }, 20);
        ap_add_action('t', static function () use (&$order): void {
            $order[] = 'a';
        }, 5);
        ap_add_action('t', static function () use (&$order): void {
            $order[] = 'm';
        }, 10);

        ap_do_action('t');
        $this->assertSame(['a', 'm', 'b'], $order);
        $this->assertSame(1, ap_did_action('t'));
        ap_do_action('t');
        $this->assertSame(2, ap_did_action('t'));
    }

    public function testEqualPriorityPreservesRegistrationOrder(): void
    {
        $order = [];
        ap_add_action('eq', static function () use (&$order): void {
            $order[] = 1;
        });
        ap_add_action('eq', static function () use (&$order): void {
            $order[] = 2;
        });
        ap_add_action('eq', static function () use (&$order): void {
            $order[] = 3;
        });
        ap_do_action('eq');
        $this->assertSame([1, 2, 3], $order);
    }

    public function testApplyFiltersChainsValues(): void
    {
        ap_add_filter('f', static function (string $v): string {
            return $v . '-x';
        });
        ap_add_filter('f', static function (string $v): string {
            return $v . '-y';
        }, 11);
        $this->assertSame('v-x-y', ap_apply_filters('f', 'v'));
        $this->assertSame(2, ap_has_filter('f'));
    }

    public function testAcceptedArgsLimitsParameters(): void
    {
        $seen = null;
        ap_add_action('args', static function (mixed ...$a) use (&$seen): void {
            $seen = $a;
        }, 10, 2);
        ap_do_action('args', 'one', 'two', 'three');
        $this->assertSame(['one', 'two'], $seen);

        ap_add_filter('fargs', static function (string $v, string $extra): string {
            return $v . ':' . $extra;
        }, 10, 2);
        $this->assertSame('base:extra', ap_apply_filters('fargs', 'base', 'extra', 'ignored'));
    }

    public function testRemoveActionAndFilter(): void
    {
        $fn = static function (): void {
        };
        ap_add_action('r', $fn, 10);
        $this->assertNotFalse(ap_has_action('r', $fn));
        $this->assertTrue(ap_remove_action('r', $fn, 10));
        $this->assertFalse(ap_has_action('r', $fn));
        $this->assertFalse(ap_remove_action('r', $fn, 10));

        $filter = static function (string $v): string {
            return $v . '!';
        };
        ap_add_filter('rf', $filter);
        $this->assertSame(10, ap_has_filter('rf', $filter));
        $this->assertTrue(ap_remove_filter('rf', $filter));
        $this->assertSame('plain', ap_apply_filters('rf', 'plain'));
    }

    public function testHasFilterReturnsPriorityForSpecificCallback(): void
    {
        $cb = static function (mixed $v): mixed {
            return $v;
        };
        ap_add_filter('hp', $cb, 42);
        $this->assertSame(42, ap_has_filter('hp', $cb));
        $this->assertSame(1, ap_has_filter('hp'));
        $this->assertFalse(ap_has_filter('missing'));
    }

    public function testRemoveAllFiltersAndActions(): void
    {
        ap_add_action('ra', static function (): void {
        }, 5);
        ap_add_action('ra', static function (): void {
        }, 15);
        $this->assertSame(2, ap_has_action('ra'));
        ap_remove_all_actions('ra', 5);
        $this->assertSame(1, ap_has_action('ra'));
        ap_remove_all_actions('ra');
        $this->assertFalse(ap_has_action('ra'));

        ap_add_filter('rf2', static function (mixed $v): mixed {
            return $v;
        });
        ap_remove_all_filters('rf2');
        $this->assertFalse(ap_has_filter('rf2'));
    }

    public function testCurrentAndDoingFilterStack(): void
    {
        $this->assertFalse(ap_current_filter());
        $this->assertFalse(ap_doing_filter());
        $this->assertFalse(ap_doing_action());

        $current = null;
        $doing = null;
        $doingNamed = null;
        ap_add_action('stack', static function () use (&$current, &$doing, &$doingNamed): void {
            $current = ap_current_filter();
            $doing = ap_doing_filter();
            $doingNamed = ap_doing_action('stack');
        });
        ap_do_action('stack');
        $this->assertSame('stack', $current);
        $this->assertTrue($doing);
        $this->assertTrue($doingNamed);
        $this->assertFalse(ap_current_action());
        $this->assertFalse(ap_doing_filter('stack'));
    }

    public function testNestedHooks(): void
    {
        $log = [];
        ap_add_action('outer', static function () use (&$log): void {
            $log[] = 'outer-start';
            $log[] = 'current:' . (string) ap_current_filter();
            ap_do_action('inner');
            $log[] = 'outer-end';
            $log[] = 'current-after-inner:' . (string) ap_current_filter();
        });
        ap_add_action('inner', static function () use (&$log): void {
            $log[] = 'inner';
            $log[] = 'current:' . (string) ap_current_filter();
            $log[] = 'doing-outer:' . (ap_doing_action('outer') ? 'yes' : 'no');
        });
        ap_do_action('outer');
        $this->assertSame(
            [
                'outer-start',
                'current:outer',
                'inner',
                'current:inner',
                'doing-outer:yes',
                'outer-end',
                'current-after-inner:outer',
            ],
            $log
        );
    }

    public function testAddDuringExecutionAtLaterPriorityRuns(): void
    {
        $order = [];
        ap_add_action('dyn', static function () use (&$order): void {
            $order[] = 'first';
            ap_add_action('dyn', static function () use (&$order): void {
                $order[] = 'late';
            }, 20);
        }, 10);
        ap_do_action('dyn');
        $this->assertSame(['first', 'late'], $order);
    }

    public function testAddDuringExecutionAtSamePriorityRuns(): void
    {
        $order = [];
        $added = false;
        ap_add_action('same', static function () use (&$order, &$added): void {
            $order[] = 'a';
            if (!$added) {
                $added = true;
                ap_add_action('same', static function () use (&$order): void {
                    $order[] = 'b';
                }, 10);
            }
        }, 10);
        ap_do_action('same');
        $this->assertSame(['a', 'b'], $order);
    }

    public function testRemoveDuringExecutionSkipsCallback(): void
    {
        $order = [];
        $second = static function () use (&$order): void {
            $order[] = 'second';
        };
        ap_add_action('rm', static function () use (&$order, $second): void {
            $order[] = 'first';
            ap_remove_action('rm', $second, 10);
        }, 10);
        ap_add_action('rm', $second, 10);
        ap_do_action('rm');
        $this->assertSame(['first'], $order);
    }

    public function testDoActionRefArrayAndApplyFiltersRefArray(): void
    {
        $got = null;
        ap_add_action('ref', static function (string $a, int $b) use (&$got): void {
            $got = [$a, $b];
        }, 10, 2);
        ap_do_action_ref_array('ref', ['hello', 7]);
        $this->assertSame(['hello', 7], $got);

        ap_add_filter('ref_f', static function (string $v, string $s): string {
            return $v . $s;
        }, 10, 2);
        $this->assertSame('ab', ap_apply_filters_ref_array('ref_f', ['a', 'b']));
    }

    public function testAllHookFiresBeforeTarget(): void
    {
        $log = [];
        ap_add_action('all', static function (string $hook) use (&$log): void {
            $log[] = 'all:' . $hook;
            $log[] = 'current:' . (string) ap_current_filter();
        }, 10, 1);
        ap_add_action('target', static function () use (&$log): void {
            $log[] = 'target';
        });
        ap_do_action('target');
        $this->assertSame(['all:target', 'current:target', 'target'], $log);

        ap_reset_hooks();
        $log = [];
        ap_add_action('all', static function (string $hook, string $value) use (&$log): void {
            $log[] = 'all-f:' . $hook . ':' . $value;
        }, 10, 2);
        ap_add_filter('target_f', static function (string $v) use (&$log): string {
            $log[] = 'filter';

            return $v . '-ok';
        });
        $out = ap_apply_filters('target_f', 'val');
        $this->assertSame('val-ok', $out);
        $this->assertSame(['all-f:target_f:val', 'filter'], $log);
    }

    public function testDidActionIncrementsEvenWithoutCallbacks(): void
    {
        $this->assertSame(0, ap_did_action('empty'));
        ap_do_action('empty');
        $this->assertSame(1, ap_did_action('empty'));
    }

    public function testEmptyHookNameRejected(): void
    {
        $this->assertFalse(ap_add_action('', static function (): void {
        }));
        $this->assertFalse(ap_add_filter('  ', static function (mixed $v): mixed {
            return $v;
        }));
    }

    public function testDedupeSameCallbackSamePriority(): void
    {
        $n = 0;
        $cb = static function () use (&$n): void {
            ++$n;
        };
        ap_add_action('dedupe', $cb, 10);
        ap_add_action('dedupe', $cb, 10);
        $this->assertSame(1, ap_has_action('dedupe'));
        ap_do_action('dedupe');
        $this->assertSame(1, $n);
    }

    public function testNamedFunctionAndObjectMethodCallbacks(): void
    {
        $this->assertTrue(ap_add_filter('named', 'strtoupper'));
        $this->assertSame('HI', ap_apply_filters('named', 'hi'));

        $obj = new class {
            public function tag(string $v): string
            {
                return $v . '-obj';
            }
        };
        ap_add_filter('named', [$obj, 'tag']);
        $this->assertSame('HI-obj', ap_apply_filters('named', 'hi'));
        $this->assertNotFalse(ap_has_filter('named', [$obj, 'tag']));
        $this->assertTrue(ap_remove_filter('named', [$obj, 'tag']));
    }

    public function testCallbackIdHelper(): void
    {
        $this->assertSame('fn:strlen', ap_hook_callback_id('strlen'));
        $c = static function (): void {
        };
        $this->assertStringStartsWith('closure:', ap_hook_callback_id($c));
    }

    public function testFilterWithNoCallbacksReturnsValue(): void
    {
        $this->assertSame(42, ap_apply_filters('none', 42));
        $this->assertSame('x', ap_apply_filters_ref_array('none', ['x']));
    }
}
