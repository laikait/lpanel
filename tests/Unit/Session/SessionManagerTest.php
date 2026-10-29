<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Session\SessionId;
use App\Engine\Session\SessionManager;
use App\Engine\Session\SessionRecord;
use App\Engine\Session\Stores\ArrayStore;
use App\Tests\Support\TestCase;

/**
 * Policy: when a session is read, when it is written, what the cookie says, and
 * what happens to a request that arrives one moment too late.
 */
final class SessionManagerTest extends TestCase
{
    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
    }

    private function manager(int $idle = 7200, int $absolute = 0, int $grace = 30): SessionManager
    {
        return new SessionManager($this->store, null, 'session', $idle, $absolute, $grace);
    }

    /** @param array<string, string> $cookies */
    private function request(array $cookies = []): Request
    {
        return Request::create('GET', '/', ['cookies' => $cookies]);
    }

    // ---- laziness ----------------------------------------------------------

    /**
     * The claim the whole design rests on: a request that does not use the
     * session costs one method call and touches no storage.
     */
    public function test_a_request_that_never_asks_reads_nothing_and_writes_nothing(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request());

        $response = $manager->onResponse(new Response('hello'));

        self::assertFalse($manager->active());
        self::assertSame([], $response->cookies());
        self::assertSame(0, $this->store->count());
    }

    public function test_asking_for_the_session_starts_one(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request());

        $session = $manager->session();

        self::assertTrue($manager->active());
        self::assertTrue(SessionId::isValid($session->id()));
        self::assertFalse($session->existed());
    }

    public function test_the_same_session_is_returned_all_request(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request());

        self::assertSame($manager->session(), $manager->session());
    }

    // ---- the cookie --------------------------------------------------------

    public function test_a_new_session_gets_a_cookie(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request());
        $id = $manager->session()->id();

        $cookies = $manager->onResponse(new Response())->cookies();

        self::assertCount(1, $cookies);
        self::assertSame('session', $cookies[0]->name);
        self::assertSame($id, $cookies[0]->value);
    }

    /**
     * The one cookie in the framework that must never be readable by script:
     * it is the credential itself.
     */
    public function test_the_cookie_is_http_only_and_not_sent_cross_site(): void
    {
        $cookie = $this->manager()->cookie(SessionId::generate());

        self::assertTrue($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);
        self::assertSame(0, $cookie->expires, 'it goes when the browser does');
    }

    public function test_the_cookie_is_secure_over_https(): void
    {
        $manager = $this->manager();
        $manager->onRequest(Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]));
        $manager->session();

        $cookies = $manager->onResponse(new Response())->cookies();

        self::assertTrue($cookies[0]->secure);
    }

    /** A browser already holding the right id is not told again. */
    public function test_an_unchanged_id_is_not_sent_back(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));
        $manager->session();

        self::assertSame([], $manager->onResponse(new Response())->cookies());
    }

    // ---- resuming ----------------------------------------------------------

    public function test_a_session_resumes_from_its_cookie(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));

        $session = $manager->session();

        self::assertSame($id, $session->id());
        self::assertTrue($session->existed());
        self::assertSame(7, $session->get('user'));
    }

    public function test_an_id_this_framework_did_not_issue_starts_a_fresh_session(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => '../../etc/passwd']));

        self::assertFalse($manager->session()->existed());
    }

    public function test_an_id_storage_has_never_heard_of_starts_a_fresh_session(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => SessionId::generate()]));

        self::assertFalse($manager->session()->existed());
    }

    // ---- expiry ------------------------------------------------------------

    public function test_a_session_idle_past_its_lifetime_is_gone(): void
    {
        $id = $this->put(['user' => 7], createdAt: \time() - 9000, touchedAt: \time() - 9000);

        $manager = $this->manager(idle: 7200);
        $manager->onRequest($this->request(['session' => $id]));

        self::assertFalse($manager->session()->existed());
        self::assertNull($manager->session()->get('user'));
    }

    /**
     * The clock a busy session cannot outrun.
     *
     * Touched one second ago, so idle expiry would keep it -- and created a
     * week ago, which is exactly the session a background poll keeps alive for
     * ever.
     */
    public function test_a_session_past_its_absolute_lifetime_is_gone_however_active(): void
    {
        $id = $this->put(['user' => 7], createdAt: \time() - 604800, touchedAt: \time() - 1);

        $manager = $this->manager(idle: 7200, absolute: 86400);
        $manager->onRequest($this->request(['session' => $id]));

        self::assertFalse($manager->session()->existed());
    }

    public function test_the_absolute_lifetime_is_not_reset_by_regenerating(): void
    {
        $created = \time() - 80000;
        $id = $this->put(['user' => 7], createdAt: $created, touchedAt: \time());

        $manager = $this->manager(idle: 7200, absolute: 86400);
        $manager->onRequest($this->request(['session' => $id]));

        $session = $manager->session();
        $next = $session->regenerate();
        $manager->save();

        $record = $this->store->read($next);

        self::assertNotNull($record);
        self::assertSame($created, $record->createdAt, 'regenerating is not a way to live for ever');
    }

    // ---- saving ------------------------------------------------------------

    public function test_saving_writes_what_changed(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request());

        $session = $manager->session();
        $session->set('user', 7);
        $manager->save();

        $record = $this->store->read($session->id());

        self::assertNotNull($record);
        self::assertSame(['user' => 7], $record->payload);
    }

    /**
     * Two requests, each setting a different key, and both survive.
     *
     * This is the scenario a read-then-write-everything design loses: the
     * second writer would put back the copy it read at the start, which never
     * had the first writer's key in it.
     */
    public function test_a_concurrent_writer_is_not_clobbered(): void
    {
        $id = $this->put(['cart' => 3]);

        $slow = $this->manager();
        $slow->onRequest($this->request(['session' => $id]));
        $slow->session()->set('locale', 'fr');

        // Another request lands and finishes first.
        $quick = $this->manager();
        $quick->onRequest($this->request(['session' => $id]));
        $quick->session()->set('theme', 'dark');
        $quick->save();

        $slow->save();

        $record = $this->store->read($id);

        self::assertNotNull($record);
        self::assertSame(['cart' => 3, 'theme' => 'dark', 'locale' => 'fr'], $record->payload);
    }

    public function test_reading_alone_still_records_activity(): void
    {
        $id = $this->put(['user' => 7], createdAt: \time() - 100, touchedAt: \time() - 100);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));
        self::assertSame(7, $manager->session()->get('user'));
        $manager->save();

        $record = $this->store->read($id);

        self::assertNotNull($record);
        self::assertGreaterThan(\time() - 5, $record->touchedAt);
    }

    // ---- regeneration and the grace window ---------------------------------

    public function test_regenerating_moves_the_data_to_a_new_id(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));

        $session = $manager->session();
        $next = $session->regenerate();
        $manager->save();

        self::assertNotSame($id, $next);
        self::assertTrue(SessionId::isValid($next));

        $record = $this->store->read($next);

        self::assertNotNull($record);
        self::assertSame(['user' => 7], $record->payload);
    }

    /**
     * The reason the old record is a pointer rather than a deletion.
     *
     * A page that logs in usually has other requests already on the wire. Each
     * of them is holding the id that has just stopped existing, and without the
     * signpost every one of them would be handed a brand new empty session --
     * the user logged out by the act of logging in.
     */
    public function test_a_request_still_in_flight_follows_the_new_id(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));
        $manager->session()->regenerate();
        $manager->save();

        $late = $this->manager();
        $late->onRequest($this->request(['session' => $id]));

        $session = $late->session();

        self::assertTrue($session->existed());
        self::assertSame(7, $session->get('user'));
    }

    public function test_the_pointer_carries_no_data_of_its_own(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));
        $manager->session()->regenerate();
        $manager->save();

        $pointer = $this->store->read($id);

        self::assertNotNull($pointer);
        self::assertTrue($pointer->isPointer());
        self::assertSame([], $pointer->payload, 'a signpost is not a second copy');
    }

    public function test_the_grace_window_closes(): void
    {
        $id = SessionId::generate();
        $next = SessionId::generate();

        $this->store->commit($next, static fn(): SessionRecord => SessionRecord::fresh($next, ['user' => 7]));
        $this->store->commit(
            $id,
            static fn(): SessionRecord => SessionRecord::fresh($id)->replacedBy($next, \time() - 60),
        );

        $manager = $this->manager(grace: 30);
        $manager->onRequest($this->request(['session' => $id]));

        self::assertFalse($manager->session()->existed());
    }

    /** Zero seconds of grace is none: it used to skip the check and follow forever. */
    public function test_no_grace_follows_nothing(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager(grace: 0);
        $manager->onRequest($this->request(['session' => $id]));
        $manager->session()->regenerate();
        $manager->save();

        $late = $this->manager(grace: 0);
        $late->onRequest($this->request(['session' => $id]));

        self::assertFalse($late->session()->existed());
        self::assertNull($late->session()->get('user'));
    }

    /**
     * Session fixation. The attacker's own, genuine id is planted in the
     * victim's browser; the victim logs in. A followable pointer would hand
     * whoever still holds the old id the session the login made -- and its new
     * id -- so a change of privilege leaves none.
     */
    public function test_an_unfollowable_regeneration_leaves_the_old_id_worthless(): void
    {
        $planted = $this->put(['cart' => 3]);

        $victim = $this->manager();
        $victim->onRequest($this->request(['session' => $planted]));
        $session = $victim->session();
        $next = $session->regenerate(followable: false);
        $session->setReserved('_auth.id', 'victim');
        $victim->save();

        self::assertNull($this->store->read($planted), 'no pointer was left behind');
        self::assertSame(['cart' => 3, '_auth.id' => 'victim'], $this->store->read($next)?->payload, 'the data moved with the login');

        $attacker = $this->manager();
        $attacker->onRequest($this->request(['session' => $planted]));
        $theirs = $attacker->session();

        self::assertFalse($theirs->existed());
        self::assertNull($theirs->reserved('_auth.id'));
        self::assertNotSame($next, $theirs->id());
    }

    /**
     * One hop, not a chain.
     *
     * Following pointers until they stop would let one very old id walk through
     * every regeneration a session ever had, which is the opposite of what
     * regenerating is for.
     */
    public function test_a_pointer_to_a_pointer_is_not_followed(): void
    {
        $first = SessionId::generate();
        $second = SessionId::generate();
        $third = SessionId::generate();

        $this->store->commit($third, static fn(): SessionRecord => SessionRecord::fresh($third, ['user' => 7]));
        $this->store->commit($second, static fn(): SessionRecord => SessionRecord::fresh($second)->replacedBy($third));
        $this->store->commit($first, static fn(): SessionRecord => SessionRecord::fresh($first)->replacedBy($second));

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $first]));

        self::assertFalse($manager->session()->existed());
    }

    public function test_invalidating_leaves_nothing_behind(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));

        $session = $manager->session();
        $next = $session->invalidate();
        $manager->save();

        self::assertNull($this->store->read($id), 'a logout leaves no signpost');

        $record = $this->store->read($next);

        self::assertNotNull($record);
        self::assertSame([], $record->payload);
    }

    public function test_a_regenerated_session_is_given_a_new_cookie(): void
    {
        $id = $this->put(['user' => 7]);

        $manager = $this->manager();
        $manager->onRequest($this->request(['session' => $id]));

        $next = $manager->session()->regenerate();
        $cookies = $manager->onResponse(new Response())->cookies();

        self::assertCount(1, $cookies);
        self::assertSame($next, $cookies[0]->value);
    }

    // ---- sweeping and destroying -------------------------------------------

    public function test_destroy_removes_the_session_from_storage(): void
    {
        $manager = $this->manager();
        $manager->onRequest($this->request());
        $session = $manager->session();
        $manager->save();

        $manager->destroy();

        self::assertNull($this->store->read($session->id()));
        self::assertFalse($manager->active());
    }

    public function test_gc_uses_the_configured_lifetimes(): void
    {
        $this->put(['a' => 1], createdAt: \time() - 9000, touchedAt: \time() - 9000);
        $this->put(['b' => 2]);

        self::assertSame(1, $this->manager(idle: 7200)->gc());
        self::assertSame(1, $this->store->count());
    }

    public function test_the_forget_cookie_expires_immediately(): void
    {
        $cookie = $this->manager()->forgetCookie();

        self::assertSame('session', $cookie->name);
        self::assertSame('', $cookie->value);
        self::assertLessThan(\time(), $cookie->expires);
    }

    /** @param array<string, mixed> $payload */
    private function put(array $payload, ?int $createdAt = null, ?int $touchedAt = null): string
    {
        $id = SessionId::generate();
        $createdAt ??= \time();
        $touchedAt ??= \time();

        $this->store->commit(
            $id,
            static fn(): SessionRecord => new SessionRecord($id, $payload, $createdAt, $touchedAt),
        );

        return $id;
    }
}
