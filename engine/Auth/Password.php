<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Security\Secret;

/**
 * Hashing and checking passwords, and nothing else.
 *
 * A thin wrapper over PHP's own functions, which are the right ones. What it
 * adds is three decisions that are easy to get wrong and invisible when they
 * are:
 *
 * **The algorithm is PASSWORD_DEFAULT, not a name.** Naming bcrypt pins the
 * application to whatever was current when somebody typed it; PASSWORD_DEFAULT
 * moves when PHP's default moves, and needsRehash() is what carries existing
 * users across. A hash records which algorithm made it, so old and new coexist
 * without a migration.
 *
 * **A missing account still costs a hash.** verify() against a null hash runs
 * against a dummy one rather than returning early. Otherwise a wrong username
 * answers in microseconds and a wrong password answers in fifty milliseconds,
 * and the difference is a way to find out which accounts exist -- worth having
 * because the usual next step is to try that name everywhere else.
 *
 * **The plaintext arrives as a Secret.** Not so that it cannot be read, but so
 * that reading it says reveal() at the call site. A password reaching here is a
 * password one var_dump away from a ticket.
 */
final class Password
{
    /**
     * @param array<string, int|string> $options passed straight to PHP, e.g.
     *                                           ['cost' => 12]. Empty means the
     *                                           algorithm's own default, which
     *                                           is the right answer until
     *                                           somebody has measured
     */
    public function __construct(private readonly array $options = []) {}

    public function describe(): string
    {
        $options = $this->options === []
            ? 'default cost'
            : \implode(', ', \array_map(
                static fn(string $key, int|string $value): string => $key . '=' . $value,
                \array_keys($this->options),
                \array_values($this->options),
            ));

        return \sprintf('%s (%s)', $this->algorithmName(), $options);
    }

    public function hash(Secret $plain): string
    {
        return \password_hash($plain->reveal(), \PASSWORD_DEFAULT, $this->options);
    }

    /**
     * Whether this password matches this hash.
     *
     * A null or empty hash answers false, and takes the same time doing it as
     * a real comparison would. See the class docblock.
     *
     * The cost is paid by hashing the password with this instance's own
     * algorithm and options and throwing the result away -- not by checking
     * it against a fixed dummy hash. A fixed one has a fixed cost: it used to
     * be bcrypt at cost 10, so configuring cost 12 (or running PHP 8.4, whose
     * default is 12) made a missing account answer four times faster than a
     * real one. A hash made now always costs exactly what checking a hash made
     * by hash() costs.
     */
    public function verify(Secret $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            \password_hash($plain->reveal(), \PASSWORD_DEFAULT, $this->options);

            return false;
        }

        return \password_verify($plain->reveal(), $hash);
    }

    /**
     * Whether this hash was made by something weaker than the current default.
     *
     * The only moment an application can upgrade a hash is the moment it holds
     * the plaintext, which is the moment somebody logs in. A framework that
     * offered no way to notice would leave every account on whatever was
     * default the year it was created.
     */
    public function needsRehash(string $hash): bool
    {
        return \password_needs_rehash($hash, \PASSWORD_DEFAULT, $this->options);
    }

    /**
     * Which algorithm PASSWORD_DEFAULT currently is.
     *
     * Asked by hashing a throwaway value rather than by reading a constant,
     * because the constant is the name of a default that moves and the hash is
     * the thing that actually records what was used. It costs one hash, and the
     * only caller is a command printing a line for a person to read.
     */
    private function algorithmName(): string
    {
        return \password_get_info(\password_hash('x', \PASSWORD_DEFAULT, $this->options))['algoName'];
    }
}
