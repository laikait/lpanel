<?php

declare(strict_types=1);

namespace App\Engine\System\Filesystem;

/**
 * The filesystem policy did not allow a path: outside every root, read-only,
 * relative, through a dangling link, a root itself, or permission bits that
 * are refused.
 *
 * Nothing was touched. Audited as a refusal, because a run of these is
 * something probing the edges of what the application may reach.
 */
class FilesystemPolicyException extends FilesystemException {}
