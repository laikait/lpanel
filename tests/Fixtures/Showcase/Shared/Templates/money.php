<?php

declare(strict_types=1);

/**
 * A shared partial, reachable as "@Shared/money" from any module.
 *
 * The shared module gets a template namespace even though it gets no asset
 * namespace, because unlike a URL there is a name for it.
 *
 * @var \App\Engine\Template\Escaper $e
 * @var int    $amount   in minor units, because money is not a float
 * @var string $currency
 */
?>
<span class="money"><?= $e(number_format($amount / 100, 2)) ?> <?= $e($currency) ?></span>
