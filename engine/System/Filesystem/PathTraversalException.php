<?php

declare(strict_types=1);

namespace App\Engine\System\Filesystem;

/**
 * A path with a ".." segment, refused before anything was resolved.
 *
 * Its own type because it is almost never an accident: application code says
 * the path it means, and "../../etc/passwd" is what an attacker's input looks
 * like on its way to a file it should not reach.
 */
final class PathTraversalException extends FilesystemPolicyException {}
