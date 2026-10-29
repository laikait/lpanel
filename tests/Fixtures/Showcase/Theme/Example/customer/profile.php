<?php

declare(strict_types=1);

/**
 * The site's replacement for the Example plugin's customer profile.
 *
 * The plugin was not edited, asked, or told. The only thing that makes this
 * file win is where it sits: templates/<namespace>/<path>, which
 * the registry searches before the module's own Templates/ directory.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var \App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer $customer
 */
?>
<article class="profile profile--overridden">
    <h2><?= $e($customer->name()) ?></h2>
    <p>Overridden by the default template.</p>
</article>
