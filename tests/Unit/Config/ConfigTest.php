<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Engine\Config\Config;
use App\Engine\Config\ConfigurationException;
use App\Tests\Support\TestCase;

final class ConfigTest extends TestCase
{
    public function test_get_reads_a_top_level_key(): void
    {
        $config = new Config(['app' => 'framework']);

        self::assertSame('framework', $config->get('app'));
    }

    public function test_get_reads_a_nested_key_with_dot_notation(): void
    {
        $config = new Config(['app' => ['debug' => true, 'env' => 'testing']]);

        self::assertTrue($config->get('app.debug'));
        self::assertSame('testing', $config->get('app.env'));
    }

    public function test_get_returns_the_default_for_a_missing_key(): void
    {
        $config = new Config(['app' => ['env' => 'testing']]);

        self::assertSame('fallback', $config->get('app.missing', 'fallback'));
        self::assertSame('fallback', $config->get('nothing.here.at.all', 'fallback'));
        self::assertNull($config->get('app.missing'));
    }

    public function test_get_does_not_walk_through_a_scalar(): void
    {
        $config = new Config(['app' => 'framework']);

        self::assertSame('default', $config->get('app.debug', 'default'));
    }

    public function test_get_distinguishes_a_stored_null_from_a_missing_key(): void
    {
        $config = new Config(['app' => ['debug' => null]]);

        self::assertNull($config->get('app.debug', 'default'));
        self::assertSame('default', $config->get('app.absent', 'default'));
    }

    public function test_has_reports_presence_including_stored_nulls(): void
    {
        $config = new Config(['app' => ['debug' => null]]);

        self::assertTrue($config->has('app'));
        self::assertTrue($config->has('app.debug'));
        self::assertFalse($config->has('app.env'));
    }

    public function test_set_creates_intermediate_levels(): void
    {
        $config = new Config();
        $config->set('http.trusted_proxies', ['10.0.0.1']);

        self::assertSame(['10.0.0.1'], $config->get('http.trusted_proxies'));
        self::assertSame(['trusted_proxies' => ['10.0.0.1']], $config->get('http'));
    }

    public function test_set_replaces_a_scalar_standing_where_an_array_is_needed(): void
    {
        $config = new Config(['http' => 'nonsense']);
        $config->set('http.port', 8080);

        self::assertSame(8080, $config->get('http.port'));
    }

    public function test_merge_namespaces_values_under_a_key(): void
    {
        $config = new Config();
        $config->merge('Example', ['currency' => 'USD', 'page_size' => 25]);

        self::assertSame('USD', $config->get('Example.currency'));
        self::assertSame(25, $config->get('Example.page_size'));
    }

    public function test_merge_is_recursive_and_does_not_clobber_siblings(): void
    {
        $config = new Config([
            'module' => [
                'keep' => 'me',
                'nested' => ['a' => 1, 'b' => 2],
            ],
        ]);

        $config->merge('module', ['nested' => ['b' => 20, 'c' => 30]]);

        self::assertSame('me', $config->get('module.keep'));
        self::assertSame(1, $config->get('module.nested.a'));
        self::assertSame(20, $config->get('module.nested.b'));
        self::assertSame(30, $config->get('module.nested.c'));
    }

    public function test_merge_over_a_scalar_replaces_it(): void
    {
        $config = new Config(['module' => 'scalar']);
        $config->merge('module', ['a' => 1]);

        self::assertSame(1, $config->get('module.a'));
    }

    public function test_all_returns_the_whole_tree(): void
    {
        $items = ['app' => ['env' => 'testing']];

        self::assertSame($items, (new Config($items))->all());
    }

    public function test_an_empty_key_is_inert(): void
    {
        $config = new Config(['app' => 'framework']);
        $config->set('', 'ignored');

        self::assertSame('default', $config->get('', 'default'));
        self::assertSame(['app' => 'framework'], $config->all());
    }

    // ---- typed retrieval ----------------------------------------------------

    public function test_a_typed_read_returns_the_value(): void
    {
        $config = new Config([
            'name' => 'framework',
            'workers' => 4,
            'ratio' => 0.5,
            'debug' => true,
            'paths' => ['a', 'b'],
        ]);

        self::assertSame('framework', $config->string('name'));
        self::assertSame(4, $config->int('workers'));
        self::assertSame(0.5, $config->float('ratio'));
        self::assertTrue($config->bool('debug'));
        self::assertSame(['a', 'b'], $config->strings('paths'));
    }

    public function test_a_missing_key_takes_the_default(): void
    {
        $config = new Config([]);

        self::assertSame('utc', $config->string('app.timezone', 'utc'));
        self::assertSame(30, $config->int('retention', 30));
        self::assertTrue($config->bool('debug', true));
        self::assertSame(['file'], $config->strings('writers', ['file']));
    }

    public function test_a_missing_key_with_no_default_is_null(): void
    {
        self::assertNull((new Config([]))->string('nothing'));
    }

    /**
     * The decision this class makes, and the reason it is not defensive: a
     * value that is present but of the wrong type is a mistake in a file
     * somebody has open. Falling back to the default instead turns
     * "retention_days" => "30" into zero, which means keep the logs forever,
     * and nobody finds out for a year.
     */
    public function test_a_value_of_the_wrong_type_is_refused_rather_than_coerced(): void
    {
        $config = new Config(['logging' => ['retention_days' => '30']]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/logging\.retention_days/');

        $config->int('logging.retention_days');
    }

    public function test_the_refusal_says_what_was_there_instead(): void
    {
        $config = new Config(['app' => ['debug' => 'true']]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must be of type bool, string given/');

        $config->bool('app.debug');
    }

    /**
     * The one widening PHP does without losing anything. Writing 30 rather than
     * 30.0 in a config file is not a mistake worth stopping a boot for.
     */
    public function test_an_integer_is_acceptable_where_a_float_is_wanted(): void
    {
        self::assertSame(30.0, (new Config(['timeout' => 30]))->float('timeout'));
    }

    public function test_a_float_is_not_acceptable_where_an_integer_is_wanted(): void
    {
        $this->expectException(ConfigurationException::class);

        (new Config(['workers' => 2.5]))->int('workers');
    }

    public function test_a_list_with_a_non_string_in_it_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/list of strings/');

        (new Config(['writers' => ['file', 3]]))->strings('writers');
    }

    public function test_a_list_is_reindexed(): void
    {
        self::assertSame(['b'], (new Config(['writers' => [1 => 'b']]))->strings('writers'));
    }

    // ---- defaults -------------------------------------------------------------

    /**
     * A module's config() declaration is defaults, not decisions. Modules
     * register long after config/ is read, so merging their values the ordinary
     * way would have every module quietly overwrite whatever the application
     * configured for it -- and the symptom is a config file that appears to do
     * nothing at all.
     */
    public function test_defaults_do_not_displace_what_is_already_configured(): void
    {
        $config = new Config(['Example' => ['page_size' => 10]]);

        $config->defaults('Example', ['page_size' => 25, 'currency' => 'USD']);

        self::assertSame(['page_size' => 10, 'currency' => 'USD'], $config->get('Example'));
    }

    public function test_defaults_apply_where_nothing_is_configured(): void
    {
        $config = new Config([]);

        $config->defaults('Example', ['page_size' => 25]);

        self::assertSame(25, $config->int('Example.page_size'));
    }

    public function test_defaults_reach_into_nested_values(): void
    {
        $config = new Config(['mod' => ['limits' => ['rows' => 10]]]);

        $config->defaults('mod', ['limits' => ['rows' => 100, 'depth' => 3]]);

        self::assertSame(['limits' => ['rows' => 10, 'depth' => 3]], $config->get('mod'));
    }

    /** merge() is still the other direction, and both are used. */
    public function test_merge_still_overrides(): void
    {
        $config = new Config(['mod' => ['page_size' => 10]]);

        $config->merge('mod', ['page_size' => 25]);

        self::assertSame(25, $config->int('mod.page_size'));
    }
}
