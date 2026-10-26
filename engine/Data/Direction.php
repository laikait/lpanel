<?php

declare(strict_types=1);

namespace App\Engine\Data;

enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
