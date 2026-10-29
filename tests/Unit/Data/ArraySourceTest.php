<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Data\ArraySource;
use App\Engine\Data\Criterion;
use App\Engine\Data\DataException;
use App\Engine\Data\Operator;
use App\Engine\Data\Query;
use App\Engine\Model\ModelManager;
use App\Tests\Support\TestCase;

/**
 * The in-memory source is not a stand-in to be replaced. It is what makes a
 * repository testable at full speed, so the semantics it implements are the
 * ones a SQL source will have to match -- and they are pinned here.
 */
final class ArraySourceTest extends TestCase
{
    private ArraySource $source;

    protected function setUp(): void
    {
        $this->source = new ArraySource(['rows' => [
            ['id' => 1, 'name' => 'alpha', 'score' => 10, 'group' => 'a'],
            ['id' => 2, 'name' => 'Beta', 'score' => 30, 'group' => 'b'],
            ['id' => 3, 'name' => 'gamma', 'score' => null, 'group' => 'a'],
        ]]);
    }

    private function query(): Query
    {
        return Query::on($this->source, 'rows', new ModelManager());
    }

    // ---- the collection ---------------------------------------------------

    public function test_it_holds_named_collections(): void
    {
        self::assertTrue($this->source->has('rows'));
        self::assertFalse($this->source->has('other'));
        self::assertCount(3, $this->source->all('rows'));
        self::assertSame([], $this->source->all('missing'));
    }

    public function test_a_collection_can_be_seeded_and_emptied(): void
    {
        $this->source->seed('other', [['id' => 1]]);
        self::assertCount(1, $this->source->all('other'));

        $this->source->truncate('other');
        self::assertSame([], $this->source->all('other'));
    }

    public function test_reading_a_collection_that_does_not_exist_yields_nothing(): void
    {
        $query = Query::on($this->source, 'missing', new ModelManager());

        self::assertSame([], $query->rows());
        self::assertSame(0, $query->count());
    }

    // ---- criteria ---------------------------------------------------------

    public function test_every_operator_behaves(): void
    {
        self::assertSame([1], $this->query()->whereIs('score', 10)->column('id'));
        self::assertSame([2, 3], $this->query()->whereNot('score', 10)->column('id'));
        self::assertSame([1], $this->query()->where('score', Operator::Lt, 30)->column('id'));
        self::assertSame([1, 2], $this->query()->where('score', Operator::Lte, 30)->column('id'));
        self::assertSame([2], $this->query()->where('score', Operator::Gt, 10)->column('id'));
        self::assertSame([1, 2], $this->query()->where('score', Operator::Gte, 10)->column('id'));
        self::assertSame([1, 2], $this->query()->whereIn('id', [1, 2])->column('id'));
        self::assertSame([3], $this->query()->whereNotIn('id', [1, 2])->column('id'));
        self::assertSame([3], $this->query()->whereNull('score')->column('id'));
        self::assertSame([1, 2], $this->query()->whereNotNull('score')->column('id'));
    }

    /**
     * Null never takes part in an ordering comparison. PHP would decide
     * null < 30 is true; SQL would call it unknown. Following SQL keeps a test
     * against this source honest about what the database will do.
     */
    public function test_null_never_satisfies_an_ordering_comparison(): void
    {
        self::assertNotContains(3, $this->query()->where('score', Operator::Lt, 30)->column('id'));
        self::assertNotContains(3, $this->query()->where('score', Operator::Gt, 0)->column('id'));
        self::assertNotContains(3, $this->query()->where('score', Operator::Gte, 0)->column('id'));
    }

    /**
     * The one deliberate loosening, and the reason for it.
     *
     * No relational database stores a PHP boolean: a row read back holds 1
     * where the model holds true. Without this, whereIs('active', true) would
     * match against a database and not match the identical rows in memory --
     * which would make an in-memory test worse than no test at all.
     */
    public function test_true_matches_one_and_false_matches_zero(): void
    {
        $this->source->seed('rows', [
            ['id' => 1, 'active' => 1],
            ['id' => 2, 'active' => 0],
            ['id' => 3, 'active' => true],
            ['id' => 4, 'active' => false],
        ]);

        self::assertSame([1, 3], $this->query()->whereIs('active', true)->column('id'));
        self::assertSame([2, 4], $this->query()->whereIs('active', false)->column('id'));
        self::assertSame([1, 3], $this->query()->whereIs('active', 1)->column('id'));
        self::assertSame([2, 4], $this->query()->whereNot('active', true)->column('id'));
        self::assertSame([1, 3], $this->query()->whereIn('active', [true])->column('id'));
    }

    /** Bounded to that one pair: everything else stays strict. */
    public function test_nothing_else_is_loosened(): void
    {
        $this->source->seed('rows', [['id' => 1, 'v' => 5], ['id' => 2, 'v' => '5'], ['id' => 3, 'v' => null]]);

        self::assertSame([1], $this->query()->whereIs('v', 5)->column('id'));
        self::assertSame([2], $this->query()->whereIs('v', '5')->column('id'));
        self::assertSame([], $this->query()->whereIs('v', false)->column('id'), 'null is not false');
    }

    public function test_a_missing_field_is_treated_as_null(): void
    {
        self::assertSame([1, 2, 3], $this->query()->whereNull('absent')->column('id'));
    }

    public function test_like_uses_sql_wildcards_and_ignores_case(): void
    {
        self::assertSame([1], $this->query()->whereLike('name', 'alp%')->column('id'));
        self::assertSame([2], $this->query()->whereLike('name', 'bet%')->column('id'));
        self::assertSame([3], $this->query()->whereLike('name', '%ma')->column('id'));
        self::assertSame([1, 2, 3], $this->query()->whereLike('name', '%a')->column('id'), 'all three end in a');
        self::assertSame([1, 2, 3], $this->query()->whereLike('name', '%a%')->column('id'));
        self::assertSame([2], $this->query()->whereLike('name', 'Bet_')->column('id'));
        self::assertSame([], $this->query()->whereLike('name', 'alp')->column('id'));
    }

    /** A pattern with no wildcards is an exact, case-insensitive match. */
    public function test_like_anchors_at_both_ends(): void
    {
        self::assertSame([1], $this->query()->whereLike('name', 'ALPHA')->column('id'));
        self::assertSame([], $this->query()->whereLike('name', 'lph')->column('id'));
    }

    public function test_a_regex_metacharacter_in_a_like_pattern_is_literal(): void
    {
        $this->source->seed('rows', [['id' => 1, 'name' => 'a.b'], ['id' => 2, 'name' => 'axb']]);

        self::assertSame([1], $this->query()->whereLike('name', 'a.b')->column('id'));
    }

    /**
     * An empty IN matches nothing, which is nearly always an unfiltered list of
     * keys upstream. Saying so beats returning an empty result that looks like
     * an answer.
     */
    public function test_an_empty_in_list_is_refused_at_the_criterion(): void
    {
        $this->expectException(DataException::class);
        $this->expectExceptionMessageMatches('/can never match/');

        $this->query()->whereIn('id', []);
    }

    public function test_a_list_operator_given_a_single_value_is_refused(): void
    {
        $this->expectException(DataException::class);
        $this->expectExceptionMessageMatches('/compares against a list/');

        new Criterion('id', Operator::In, 1);
    }

    // ---- ordering ---------------------------------------------------------

    public function test_strings_sort_by_byte_order_consistently(): void
    {
        self::assertSame(['Beta', 'alpha', 'gamma'], $this->query()->orderBy('name')->column('name'));
    }

    public function test_nulls_sort_last_ascending_and_first_descending(): void
    {
        self::assertSame([1, 2, 3], $this->query()->orderBy('score')->column('id'));
        self::assertSame([3, 2, 1], $this->query()->orderByDesc('score')->column('id'));
    }

    public function test_a_later_ordering_only_breaks_earlier_ties(): void
    {
        self::assertSame([3, 1, 2], $this->query()->orderBy('group')->orderByDesc('name')->column('id'));
    }

    public function test_ordering_by_a_missing_field_leaves_the_order_alone(): void
    {
        self::assertSame([1, 2, 3], $this->query()->orderBy('absent')->column('id'));
    }

    // ---- selection and slicing --------------------------------------------

    /**
     * Column selection has to be real here, or a test passes against this
     * source and fails against one where the column is genuinely absent.
     */
    public function test_selection_removes_the_columns_that_were_not_asked_for(): void
    {
        $rows = $this->query()->select('id', 'name')->rows();

        self::assertSame(['id', 'name'], \array_keys($rows[0]));
    }

    public function test_selecting_a_column_that_does_not_exist_yields_nothing_for_it(): void
    {
        self::assertSame([], \array_keys($this->query()->select('absent')->rows()[0]));
    }

    public function test_offset_past_the_end_yields_nothing(): void
    {
        self::assertSame([], $this->query()->offset(99)->rows());
    }

    public function test_count_ignores_the_slice(): void
    {
        self::assertSame(3, $this->query()->limit(1)->offset(2)->count());
    }

    // ---- writing ----------------------------------------------------------

    public function test_an_insert_assigns_the_next_identity(): void
    {
        $identity = $this->source->insert('rows', 'id', ['name' => 'delta']);

        self::assertSame(4, $identity);
        self::assertSame(4, $this->source->all('rows')[3]['id']);
    }

    public function test_an_insert_keeps_an_identity_the_row_already_carries(): void
    {
        self::assertSame('GB', $this->source->insert('countries', 'code', ['code' => 'GB', 'label' => 'UK']));
        self::assertCount(1, $this->source->all('countries'));
    }

    public function test_inserting_into_a_new_collection_creates_it(): void
    {
        $this->source->insert('fresh', 'id', ['name' => 'one']);

        self::assertTrue($this->source->has('fresh'));
        self::assertSame(1, $this->source->all('fresh')[0]['id']);
    }

    public function test_an_update_changes_only_the_named_fields(): void
    {
        $changed = $this->source->update('rows', 'id', 1, ['name' => 'renamed']);

        self::assertSame(1, $changed);
        self::assertSame('renamed', $this->source->all('rows')[0]['name']);
        self::assertSame(10, $this->source->all('rows')[0]['score'], 'the other fields are untouched');
    }

    public function test_an_update_that_matches_nothing_reports_nothing(): void
    {
        self::assertSame(0, $this->source->update('rows', 'id', 99, ['name' => 'x']));
        self::assertSame(0, $this->source->update('missing', 'id', 1, ['name' => 'x']));
    }

    public function test_a_delete_removes_the_row_and_reports_how_many(): void
    {
        self::assertSame(1, $this->source->delete('rows', 'id', 2));
        self::assertSame([1, 3], \array_column($this->source->all('rows'), 'id'));
        self::assertSame(0, $this->source->delete('rows', 'id', 99));
    }

    public function test_the_rows_stay_a_list_after_a_delete(): void
    {
        $this->source->delete('rows', 'id', 1);

        self::assertSame([0, 1], \array_keys($this->source->all('rows')));
    }
}
