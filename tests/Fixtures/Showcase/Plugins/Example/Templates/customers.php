<?php

declare(strict_types=1);

/**
 * The customer list, as a page rather than as JSON.
 *
 * The same read models the API endpoint returns, rendered instead of encoded.
 * That is the point of a read model: it is the shape of an answer, and what
 * happens to it afterwards is the caller's business.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var list<\App\Tests\Fixtures\Showcase\Plugins\Example\Model\CustomerListRecord> $customers
 * @var int                                                        $total
 */
?>
<h1>Customers</h1>

<p><?= $e($total) ?> in total.</p>

<ul class="customers">
<?php foreach ($customers as $customer): ?>
    <li>
        <?= $e($customer->name) ?>
        <span class="email"><?= $e($customer->email) ?></span>
    </li>
<?php endforeach ?>
</ul>

<?php if ($customers === []): ?>
    <p class="empty">No customers yet.</p>
<?php endif ?>

<?= $view->render('@Example/customer/promo', ['heading' => 'From the plugin']) ?>
