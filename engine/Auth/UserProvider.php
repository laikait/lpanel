<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * Where the application keeps its users.
 *
 * **The framework does not know what a user is, and that is the specification's
 * point about authentication being modular.** There is no User model in
 * engine/, no users table it expects, no columns it names. An application
 * implements these two methods over whatever it already has -- a table, an
 * LDAP directory, another service -- and registers it in a module:
 *
 * ```php
 * $services->singleton(UserProvider::class, AccountProvider::class);
 * ```
 *
 * Both methods return null for "no such account" rather than throwing. A login
 * attempt against an identifier that does not exist is not an error; it is one
 * of the two ordinary outcomes, and it must be indistinguishable from a wrong
 * password (see AuthManager::attempt(), which is careful about this).
 */
interface UserProvider
{
    /** One line for about and auth:access, e.g. "users table via UserRepository". */
    public function describe(): string;

    /**
     * The account with this id, or null.
     *
     * Called on every request that resumes an authenticated session, so it is
     * the one method here worth making fast. It is also the place a suspended
     * account stops being able to do anything: the session still exists, the id
     * still resolves, and active being false is what ends it.
     */
    public function byId(string $id): ?Account;

    /**
     * The account with this login identifier, or null.
     *
     * Whatever the application calls a login -- a username, an email address,
     * an employee number. The framework passes through what was submitted and
     * has no opinion about which it is.
     */
    public function byLogin(string $login): ?Account;
}
