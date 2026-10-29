<?php

declare(strict_types=1);

/**
 * The plugin's own customer profile -- the fallback that an override replaces.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var \App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer $customer
 */
?>
<article class="profile profile--module">
    <h2><?= $e($customer->name()) ?></h2>
    <p><?= $e($customer->email()) ?></p>
</article>
