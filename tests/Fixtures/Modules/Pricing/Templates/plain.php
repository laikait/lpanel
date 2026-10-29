<?php

declare(strict_types=1);

/**
 * The PHP spelling of the helpers receipt.twig uses.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var int    $total in minor units
 * @var string $label
 */
?>
<p class="total"><?= $e($view->filter('money', $total)) ?></p>
<p class="shout"><?= $e($view->call('shout', $label)) ?></p>
