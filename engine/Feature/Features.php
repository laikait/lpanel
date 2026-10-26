<?php

declare(strict_types=1);

namespace App\Engine\Feature;

use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;

/**
 * Feature flags: switch code on for some people before everyone.
 *
 *     // config/features.php
 *     return [
 *         'new-checkout' => ['roles' => ['staff'], 'percent' => 10],
 *         'dark-mode'    => true,
 *         'old-search'   => false,
 *     ];
 *
 *     $features->active('new-checkout');                   // for the current identity
 *     {% if feature('new-checkout') %} … {% endif %}       // in a template
 *     $routes->get('/checkout/v2', …)->meta(['feature' => 'new-checkout']);   // 404 while off
 *
 * **A flag is true, false, or rules**, any of which switches it on:
 *
 * - accounts: identity ids;
 * - roles: role names the identity holds;
 * - percent: 0-100 of signed-in identities, chosen by a hash of the flag and
 *   the id -- so the same person stays in or out on every request and every
 *   machine, raising the percentage only adds people, and two flags at 10%
 *   reach different tens. A guest has no id to hash, so a percentage never
 *   includes guests.
 *
 * A flag nobody configured is off; rules that do not parse are refused at
 * boot, so a typo cannot quietly switch a feature off. The feature.active
 * filter has the last word -- a query string override for staff, a value from
 * a database.
 */
final class Features
{
    public const META = 'feature';

    private const RULES = ['accounts', 'roles', 'percent'];

    /** @var array<string, bool|array{accounts: list<string>, roles: list<string>, percent: int}> */
    private array $flags = [];

    /**
     * @param array<array-key, mixed>   $flags    the features configuration
     * @param ?\Closure(): Identity      $identity who "the current identity" is, asked only when needed
     */
    public function __construct(
        array $flags,
        private readonly ?\Closure $identity = null,
        private readonly ?FilterEngine $filters = null,
    ) {
        foreach ($flags as $name => $rule) {
            $this->define((string) $name, $rule);
        }
    }

    /** Whether a flag is on, for $identity or the current one. */
    public function active(string $name, ?Identity $identity = null): bool
    {
        $rule = $this->flags[$name] ?? false;
        $identity ??= $this->identity !== null ? ($this->identity)() : Identity::guest();
        $active = \is_bool($rule) ? $rule : self::matches($name, $rule, $identity);

        if ($this->filters !== null) {
            $active = $this->filters->apply('feature.active', $active, $name, $identity) === true;
        }

        return $active;
    }

    /** @return array<string, bool> every configured flag, for $identity or the current one */
    public function all(?Identity $identity = null): array
    {
        $all = [];

        foreach (\array_keys($this->flags) as $name) {
            $all[$name] = $this->active($name, $identity);
        }

        return $all;
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_keys($this->flags);
    }

    /**
     * Replace one flag's rule: for a test, or a module that owns a flag.
     *
     * @throws FeatureException for a rule that does not parse
     */
    public function define(string $name, mixed $rule): void
    {
        if ($name === '') {
            throw FeatureException::invalid($name, 'a flag needs a name.');
        }

        if (\is_bool($rule)) {
            $this->flags[$name] = $rule;

            return;
        }

        if (!\is_array($rule)) {
            throw FeatureException::invalid($name, \sprintf('it is %s; use true, false or an array of rules.', \get_debug_type($rule)));
        }

        $unknown = \array_diff(\array_map(\strval(...), \array_keys($rule)), self::RULES);

        if ($unknown !== []) {
            throw FeatureException::invalid($name, \sprintf('"%s" is not a rule; use %s.', \implode('", "', $unknown), \implode(', ', self::RULES)));
        }

        $percent = $rule['percent'] ?? 0;

        if (!\is_int($percent) || $percent < 0 || $percent > 100) {
            throw FeatureException::invalid($name, 'percent is a whole number from 0 to 100.');
        }

        $this->flags[$name] = [
            'accounts' => self::strings($name, 'accounts', $rule['accounts'] ?? []),
            'roles' => self::strings($name, 'roles', $rule['roles'] ?? []),
            'percent' => $percent,
        ];
    }

    /** Which of 100 buckets an identity falls in for a flag: stable, and different per flag. */
    public static function bucket(string $name, string $id): int
    {
        $hash = \unpack('N', \hash('sha256', $name . "\0" . $id, true));

        return (\is_array($hash) ? (int) $hash[1] : 0) % 100;
    }

    /** @param array{accounts: list<string>, roles: list<string>, percent: int} $rule */
    private static function matches(string $name, array $rule, Identity $identity): bool
    {
        if ($identity->isGuest()) {
            return \array_intersect($rule['roles'], $identity->roles) !== [];
        }

        return \in_array($identity->id, $rule['accounts'], true)
            || \array_intersect($rule['roles'], $identity->roles) !== []
            || self::bucket($name, $identity->id) < $rule['percent'];
    }

    /** @return list<string> */
    private static function strings(string $name, string $key, mixed $value): array
    {
        if (!\is_array($value)) {
            throw FeatureException::invalid($name, $key . ' is a list.');
        }

        $strings = [];

        foreach ($value as $item) {
            if (!\is_string($item) && !\is_int($item)) {
                throw FeatureException::invalid($name, $key . ' holds strings.');
            }

            $strings[] = (string) $item;
        }

        return $strings;
    }
}
