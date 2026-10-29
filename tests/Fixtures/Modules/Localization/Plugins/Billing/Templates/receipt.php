<?php

declare(strict_types=1);

/**
 * The PHP-template equivalent of |local: plain text, escaped by the author.
 *
 * @var \App\Engine\Template\TemplateView $view
 */
?>
<h1><?= $view->escaper()->html($view->local('Billing.invoice_created')) ?></h1>
<p><?= $view->escaper()->html($view->local('user_update_success', ['user' => $view->get('user')])) ?></p>
