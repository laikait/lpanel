<?php

declare(strict_types=1);

namespace App\Engine\Filter;

use App\Engine\Error\FrameworkException;
use App\Engine\Support\Callback;

final class FilterException extends FrameworkException
{
    public static function tooDeep(string $filter, int $limit): self
    {
        return new self(\sprintf(
            'Filter "%s" is still applying %d levels deep. A listener is almost certainly applying it again, '
            . 'directly or through another filter.',
            $filter,
            $limit,
        ));
    }

    /**
     * Raised only in debug mode. Forgetting to return is the commonest filter
     * bug by a wide margin, and in production the null is simply used, so this
     * costs one boolean check per listener to make development sane.
     */
    public static function returnedNull(string $filter, Callback $callback): self
    {
        return new self(\sprintf(
            'Filter "%s": %s%s returned null for a non-null value. A filter must return the value it was given, '
            . 'transformed or not. (Set app.debug to false to allow it.)',
            $filter,
            $callback->identity(),
            $callback->module === null ? '' : \sprintf(' (module %s)', $callback->module),
        ));
    }
}
