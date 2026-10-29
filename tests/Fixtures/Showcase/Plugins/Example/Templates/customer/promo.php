<?php

declare(strict_types=1);

/**
 * A template the plugin ships, reachable as "@Example/customer/promo".
 *
 * It lives in modules/, which the web server refuses to serve, and it is
 * rendered rather than served -- the same distinction the asset manager draws.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var string                            $heading
 */
?>
<section class="promo promo--module">
    <h2><?= $e($heading) ?></h2>
    <p>This markup ships with the Example plugin.</p>
</section>
