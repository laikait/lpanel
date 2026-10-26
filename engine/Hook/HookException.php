<?php

declare(strict_types=1);

namespace App\Engine\Hook;

use App\Engine\Error\FrameworkException;

final class HookException extends FrameworkException
{
    public static function tooDeep(string $hook, int $limit): self
    {
        return new self(\sprintf(
            'Hook "%s" is still firing %d levels deep. A listener is almost certainly firing it again, '
            . 'directly or through another hook.',
            $hook,
            $limit,
        ));
    }
}
