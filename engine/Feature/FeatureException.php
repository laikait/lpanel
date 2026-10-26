<?php

declare(strict_types=1);

namespace App\Engine\Feature;

use App\Engine\Error\FrameworkException;

final class FeatureException extends FrameworkException
{
    public static function invalid(string $name, string $why): self
    {
        return new self(\sprintf('The feature flag "%s" is not valid: %s', $name, $why));
    }
}
