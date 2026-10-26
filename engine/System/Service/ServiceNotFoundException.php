<?php

declare(strict_types=1);

namespace App\Engine\System\Service;

/** systemd has no unit by that name, so nothing was started, stopped or changed. */
final class ServiceNotFoundException extends ServiceException {}
