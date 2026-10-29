<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature;

use App\Engine\Auth\Identity;
use App\Engine\Feature\FeatureException;
use App\Engine\Feature\Features;
use App\Engine\Filter\FilterEngine;
use App\Tests\Support\TestCase;

final class FeaturesTest extends TestCase
{
    public function test_true_false_and_unknown(): void
    {
        $features = new Features(['on' => true, 'off' => false]);

        self::assertTrue($features->active('on'));
        self::assertFalse($features->active('off'));
        self::assertFalse($features->active('nobody-configured-this'));
        self::assertSame(['on' => true, 'off' => false], $features->all());
    }

    public function test_accounts_and_roles(): void
    {
        $features = new Features(['beta' => ['accounts' => ['7', 9], 'roles' => ['staff']]]);

        self::assertTrue($features->active('beta', new Identity('7')));
        self::assertTrue($features->active('beta', new Identity('9')));
        self::assertTrue($features->active('beta', new Identity('8', roles: ['staff'])));
        self::assertFalse($features->active('beta', new Identity('8', roles: ['customer'])));
        self::assertFalse($features->active('beta', Identity::guest()));
        self::assertTrue($features->active('beta', Identity::guest(['staff'])), 'A role granted to guests counts.');
    }

    public function test_the_current_identity_is_asked_for_only_when_needed(): void
    {
        $asked = 0;
        $features = new Features(['on' => true, 'beta' => ['accounts' => ['7']]], static function () use (&$asked): Identity {
            ++$asked;

            return new Identity('7');
        });

        self::assertTrue($features->active('beta'));
        self::assertSame(1, $asked);
    }

    public function test_a_percentage_is_stable_grows_and_differs_per_flag(): void
    {
        $ids = \array_map(\strval(...), \range(1, 2000));
        $at = static fn(string $flag, int $percent): array => \array_values(\array_filter(
            $ids,
            static fn(string $id): bool => (new Features([$flag => ['percent' => $percent]]))->active($flag, new Identity($id)),
        ));

        $ten = $at('checkout', 10);
        $thirty = $at('checkout', 30);

        self::assertEqualsWithDelta(200, \count($ten), 50, 'About ten percent.');
        self::assertEqualsWithDelta(600, \count($thirty), 80);
        self::assertSame([], \array_diff($ten, $thirty), 'Raising the percentage removed somebody.');
        self::assertSame($ten, $at('checkout', 10), 'Not stable.');
        self::assertNotSame($ten, $at('search', 10), 'Two flags reach the same people.');
        self::assertSame([], $at('checkout', 0));
        self::assertCount(2000, $at('checkout', 100));
        self::assertFalse((new Features(['all' => ['percent' => 100]]))->active('all', Identity::guest()), 'A guest has no id to hash.');
    }

    public function test_the_filter_has_the_last_word(): void
    {
        $filters = new FilterEngine();
        $filters->add('feature.active', static fn(bool $active, string $name, Identity $identity): bool => $name === 'forced' ? !$active : $active);
        $features = new Features(['forced' => false, 'plain' => false], filters: $filters);

        self::assertTrue($features->active('forced', Identity::guest()));
        self::assertFalse($features->active('plain', Identity::guest()));
    }

    public function test_bad_rules_are_refused_up_front(): void
    {
        $cases = [
            [['x' => 'yes'], 'use true, false or an array'],
            [['x' => ['percentage' => 10]], '"percentage" is not a rule'],
            [['x' => ['percent' => 120]], 'from 0 to 100'],
            [['x' => ['percent' => '10']], 'from 0 to 100'],
            [['x' => ['roles' => 'staff']], 'roles is a list'],
        ];

        foreach ($cases as [$flags, $message]) {
            try {
                new Features($flags);
                self::fail('Accepted: ' . $message);
            } catch (FeatureException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
