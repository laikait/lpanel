<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Engine\Session\Session;
use App\Engine\Session\SessionException;
use App\Engine\Session\SessionId;
use App\Tests\Support\TestCase;

/**
 * The object a handler holds, and the change log underneath it.
 *
 * Most of this file is about mergeInto(), because that is the part which is not
 * obvious: a session that wrote back everything it read would be correct in a
 * test and wrong the first time a user had two tabs open.
 */
final class SessionTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function session(array $payload = []): Session
    {
        return new Session(SessionId::generate(), $payload);
    }

    // ---- the ordinary things ---------------------------------------------

    public function test_a_value_can_be_written_and_read(): void
    {
        $session = $this->session();
        $session->set('user', 7);

        self::assertTrue($session->has('user'));
        self::assertSame(7, $session->get('user'));
    }

    public function test_a_missing_key_answers_the_default(): void
    {
        self::assertSame('guest', $this->session()->get('user', 'guest'));
        self::assertFalse($this->session()->has('user'));
    }

    /** null is stored but is not "has" -- the same rule isset() has. */
    public function test_a_null_value_is_stored_and_is_not_has(): void
    {
        $session = $this->session();
        $session->set('user', null);

        self::assertFalse($session->has('user'));
        self::assertSame([], \array_diff_key($session->all(), ['user' => null]));
    }

    public function test_pull_reads_once(): void
    {
        $session = $this->session(['token' => 'abc']);

        self::assertSame('abc', $session->pull('token'));
        self::assertFalse($session->has('token'));
        self::assertSame('gone', $session->pull('token', 'gone'));
    }

    public function test_fill_writes_several(): void
    {
        $session = $this->session();
        $session->fill(['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $session->all());
    }

    public function test_the_bookkeeping_key_is_not_part_of_all(): void
    {
        $session = $this->session();
        $session->flash('status', 'saved');

        self::assertSame(['status' => 'saved'], $session->all());
    }

    public function test_the_bookkeeping_key_cannot_be_written(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('reserved');

        $this->session()->set(Session::FLASH_KEY, 'anything');
    }

    public function test_an_empty_key_is_refused(): void
    {
        $this->expectException(SessionException::class);

        $this->session()->set('', 'anything');
    }

    // ---- the change log ---------------------------------------------------

    /**
     * The point of the design: a key this request never mentioned survives a
     * write, even though this request's copy of the session never had it.
     */
    public function test_a_key_another_request_wrote_is_not_erased(): void
    {
        $session = $this->session(['cart' => 3]);
        $session->set('locale', 'fr');

        // Meanwhile, somewhere else, another request added one.
        $merged = $session->mergeInto(['cart' => 3, 'theme' => 'dark']);

        self::assertSame(['cart' => 3, 'theme' => 'dark', 'locale' => 'fr'], $merged);
    }

    public function test_a_forgotten_key_is_removed_from_storage(): void
    {
        $session = $this->session(['cart' => 3]);
        $session->forget('cart');

        self::assertSame(['other' => 1], $session->mergeInto(['cart' => 3, 'other' => 1]));
    }

    /** Set then forgotten in one request ends up gone, not set. */
    public function test_the_last_word_in_one_request_wins(): void
    {
        $session = $this->session();
        $session->set('a', 1);
        $session->forget('a');

        self::assertSame([], $session->mergeInto([]));

        $session->set('a', 2);

        self::assertSame(['a' => 2], $session->mergeInto([]));
    }

    public function test_a_session_that_only_read_is_not_dirty(): void
    {
        $session = $this->session(['user' => 7]);

        self::assertFalse($session->isDirty());

        self::assertSame(7, $session->get('user'));
        self::assertFalse($session->isDirty(), 'reading is not a change');

        $session->set('user', 8);

        self::assertTrue($session->isDirty());
    }

    /**
     * clear() means the session, not "the keys I read".
     *
     * 'c' is a key this request never saw -- written by a concurrent request
     * after this one loaded. Every other operation here is per-key and leaves
     * it alone, and this one must not, because invalidate() is built on it and
     * a logout that left data behind would be a real one.
     */
    public function test_clear_removes_even_a_key_this_request_never_saw(): void
    {
        $session = $this->session(['a' => 1, 'b' => 2]);
        $session->clear();

        self::assertSame([], $session->all());
        self::assertSame([], $session->mergeInto(['a' => 1, 'b' => 2, 'c' => 3]));
    }

    public function test_a_write_after_a_clear_still_lands(): void
    {
        $session = $this->session(['a' => 1]);
        $session->clear();
        $session->set('b', 2);

        self::assertSame(['b' => 2], $session->mergeInto(['a' => 1, 'c' => 3]));
    }

    // ---- flash -------------------------------------------------------------

    public function test_flashed_data_is_readable_in_the_request_that_set_it(): void
    {
        $session = $this->session();
        $session->flash('status', 'saved');

        self::assertSame('saved', $session->get('status'));
    }

    public function test_flashed_data_survives_exactly_one_more_request(): void
    {
        $first = $this->session();
        $first->flash('status', 'saved');

        $second = $this->session($first->mergeInto([]));

        self::assertSame('saved', $second->get('status'), 'the next request must see it');

        $third = $this->session($second->mergeInto($first->mergeInto([])));

        self::assertNull($third->get('status'), 'and the one after that must not');
    }

    public function test_ageing_removes_the_value_from_storage_as_well(): void
    {
        $first = $this->session();
        $first->flash('status', 'saved');
        $stored = $first->mergeInto([]);

        $second = $this->session($stored);
        $third = $this->session($second->mergeInto($stored));

        self::assertArrayNotHasKey('status', $third->mergeInto($second->mergeInto($stored)));
    }

    public function test_reflash_keeps_everything_for_one_more_request(): void
    {
        $first = $this->session();
        $first->flash('status', 'saved');
        $stored = $first->mergeInto([]);

        $second = $this->session($stored);
        $second->reflash();

        $third = $this->session($second->mergeInto($stored));

        self::assertSame('saved', $third->get('status'));
    }

    public function test_keep_is_reflash_for_one_key(): void
    {
        $first = $this->session();
        $first->flash('status', 'saved');
        $first->flash('error', 'nope');
        $stored = $first->mergeInto([]);

        $second = $this->session($stored);
        $second->keep('status');

        $third = $this->session($second->mergeInto($stored));

        self::assertSame('saved', $third->get('status'));
        self::assertNull($third->get('error'));
    }

    /** now() is for the current request only and writes nothing. */
    public function test_now_is_not_stored(): void
    {
        $session = $this->session();
        $session->now('status', 'saved');

        self::assertSame('saved', $session->get('status'));
        self::assertSame([], $session->mergeInto([]));
    }

    // ---- the id ------------------------------------------------------------

    public function test_regenerating_asks_the_manager_for_the_new_id(): void
    {
        $replacement = SessionId::generate();
        $asked = null;

        $session = new Session(
            SessionId::generate(),
            ['user' => 7],
            true,
            static function (bool $keepData) use ($replacement, &$asked): string {
                $asked = $keepData;

                return $replacement;
            },
        );

        self::assertSame($replacement, $session->regenerate());
        self::assertSame($replacement, $session->id());
        self::assertTrue($asked);
        self::assertTrue($session->wasRegenerated());
        self::assertSame(7, $session->get('user'), 'the data comes with it');
    }

    public function test_invalidating_drops_the_data_and_changes_the_id(): void
    {
        $replacement = SessionId::generate();

        $session = new Session(
            SessionId::generate(),
            ['user' => 7],
            true,
            static fn(): string => $replacement,
        );

        $session->invalidate();

        self::assertSame($replacement, $session->id());
        self::assertSame([], $session->all());
        self::assertSame([], $session->mergeInto(['user' => 7]), 'and from storage');
    }

    public function test_a_session_knows_whether_it_was_resumed(): void
    {
        self::assertFalse($this->session()->existed());
        self::assertTrue((new Session(SessionId::generate(), [], true))->existed());
    }
}
