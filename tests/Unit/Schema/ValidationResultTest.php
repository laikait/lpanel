<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Engine\Schema\ValidationError;
use App\Engine\Schema\ValidationResult;
use App\Tests\Support\TestCase;

final class ValidationResultTest extends TestCase
{
    private function errors(): ValidationResult
    {
        return ValidationResult::of([
            new ValidationError('name', 'is required'),
            new ValidationError('age', 'must be an integer'),
            new ValidationError('age', 'must be at least 0'),
        ]);
    }

    public function test_an_empty_result_is_valid(): void
    {
        $valid = ValidationResult::valid();

        self::assertTrue($valid->isValid());
        self::assertCount(0, $valid);
        self::assertNull($valid->first());
        self::assertSame([], $valid->errors());
        self::assertSame('', $valid->summary());
    }

    public function test_it_reports_every_error_it_was_given(): void
    {
        $result = $this->errors();

        self::assertFalse($result->isValid());
        self::assertCount(3, $result);
        self::assertSame('name', $result->first()?->path);
    }

    /** One field can be wrong in more than one way, and both are reported. */
    public function test_messages_are_grouped_by_path(): void
    {
        self::assertSame([
            'name' => ['is required'],
            'age' => ['must be an integer', 'must be at least 0'],
        ], $this->errors()->messages());
    }

    public function test_paths_are_distinct_and_in_order(): void
    {
        self::assertSame(['name', 'age'], $this->errors()->paths());
        self::assertTrue($this->errors()->has('age'));
        self::assertFalse($this->errors()->has('email'));
    }

    public function test_it_is_iterable(): void
    {
        $paths = [];

        foreach ($this->errors() as $error) {
            $paths[] = $error->path;
        }

        self::assertSame(['name', 'age', 'age'], $paths);
    }

    public function test_results_merge(): void
    {
        $merged = ValidationResult::of([new ValidationError('a', 'x')])
            ->merge(ValidationResult::of([new ValidationError('b', 'y')]));

        self::assertCount(2, $merged);
        self::assertSame(['a', 'b'], $merged->paths());
    }

    /** How a nested schema reports upwards: address.postcode, not postcode. */
    public function test_errors_can_be_re_reported_under_a_parent_path(): void
    {
        $nested = ValidationResult::of([new ValidationError('postcode', 'is required')])->under('address');

        self::assertSame(['address.postcode'], $nested->paths());
    }

    public function test_prefixing_with_an_empty_path_changes_nothing(): void
    {
        self::assertSame('postcode', (new ValidationError('postcode', 'is required'))->under('')->path);
        self::assertSame(['name', 'age'], $this->errors()->under('')->paths());
    }

    public function test_an_error_reads_as_path_and_message(): void
    {
        self::assertSame('address.postcode: is required', (string) new ValidationError('address.postcode', 'is required'));
    }

    public function test_the_summary_lists_everything(): void
    {
        self::assertSame(
            'name: is required; age: must be an integer; age: must be at least 0',
            $this->errors()->summary(),
        );
    }

    /** Prefixing produces a new result rather than editing one in place. */
    public function test_results_are_immutable(): void
    {
        $original = $this->errors();
        $original->under('parent');
        $original->merge(ValidationResult::of([new ValidationError('z', 'z')]));

        self::assertCount(3, $original);
        self::assertSame(['name', 'age'], $original->paths());
    }
}
