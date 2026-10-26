<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * Stores a model property encrypted, and hands it back in the clear.
 *
 *     final class Customer extends Model
 *     {
 *         public function __construct(
 *             public ?int $id,
 *             public string $email,
 *             #[Encrypted] public ?string $taxNumber = null,
 *         ) {}
 *     }
 *
 * The repository encrypts the value as it writes the row, and hydration
 * decrypts it as it reads one, through Security\Encrypter (APP_KEY). In
 * between, in the model, it is plain text; in the database, it is a "v1.…"
 * token nobody can read or change without the key.
 *
 * **The purpose is "<Model class>.<property>".** A token copied into another
 * column, or another model's column, does not decrypt there.
 *
 * **Only string properties**, nullable or not: null is stored as null. Another
 * type is refused when the model is first hydrated or saved.
 *
 * **What it costs:** the column cannot be searched, sorted or made unique --
 * each encryption of the same value differs. Keep a separate hash column when
 * a lookup is needed. A value that does not decrypt (another key, a changed
 * token) is a ModelException, never a quiet null. The column needs room: the
 * token is about 1.4 times the value, plus 56 characters.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class Encrypted {}
