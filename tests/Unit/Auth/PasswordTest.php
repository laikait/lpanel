<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\Password;
use App\Engine\Security\Secret;
use App\Tests\Support\TestCase;

/**
 * Hashing, and the three decisions underneath it.
 */
final class PasswordTest extends TestCase
{
    private Password $passwords;

    protected function setUp(): void
    {
        $this->passwords = new Password(['cost' => 4]);
    }

    public function test_a_password_verifies_against_its_own_hash(): void
    {
        $hash = $this->passwords->hash(new Secret('correct horse'));

        self::assertTrue($this->passwords->verify(new Secret('correct horse'), $hash));
        self::assertFalse($this->passwords->verify(new Secret('Correct horse'), $hash));
        self::assertFalse($this->passwords->verify(new Secret(''), $hash));
    }

    /**
     * Two hashes of one password differ, which is the salt doing its job.
     *
     * Without it, equal hashes mean equal passwords -- so one leaked table
     * tells an attacker which accounts to attack once and reuse everywhere.
     */
    public function test_the_same_password_hashes_differently_every_time(): void
    {
        $one = $this->passwords->hash(new Secret('same'));
        $two = $this->passwords->hash(new Secret('same'));

        self::assertNotSame($one, $two);
        self::assertTrue($this->passwords->verify(new Secret('same'), $one));
        self::assertTrue($this->passwords->verify(new Secret('same'), $two));
    }

    /** A hash is not a secret in the sense Secret means; it is the protected form. */
    public function test_a_hash_does_not_contain_the_password(): void
    {
        self::assertStringNotContainsString(
            'correct horse',
            $this->passwords->hash(new Secret('correct horse')),
        );
    }

    /**
     * No account still costs a hash.
     *
     * The observable part is the answer; the part that matters is that it
     * reaches password_verify() at all, which is why this pairs with the
     * enumeration test in AuthManagerTest rather than standing alone.
     */
    public function test_verifying_against_nothing_is_false_rather_than_an_error(): void
    {
        self::assertFalse($this->passwords->verify(new Secret('anything'), null));
        self::assertFalse($this->passwords->verify(new Secret('anything'), ''));
    }

    public function test_a_hash_made_with_weaker_settings_is_reported(): void
    {
        $weak = (new Password(['cost' => 4]))->hash(new Secret('x'));

        self::assertFalse((new Password(['cost' => 4]))->needsRehash($weak));
        self::assertTrue((new Password(['cost' => 5]))->needsRehash($weak));
    }

    /**
     * An old hash still verifies. That is the whole point of recording the
     * algorithm inside it -- upgrading is something that happens one login at a
     * time, not a migration.
     */
    public function test_a_hash_that_needs_rehashing_still_works(): void
    {
        $weak = (new Password(['cost' => 4]))->hash(new Secret('x'));
        $stronger = new Password(['cost' => 5]);

        self::assertTrue($stronger->needsRehash($weak));
        self::assertTrue($stronger->verify(new Secret('x'), $weak));
    }

    public function test_it_says_which_algorithm_it_is_using(): void
    {
        $description = $this->passwords->describe();

        self::assertStringContainsString('cost=4', $description);
        self::assertStringContainsString('default cost', (new Password())->describe());
    }
}
