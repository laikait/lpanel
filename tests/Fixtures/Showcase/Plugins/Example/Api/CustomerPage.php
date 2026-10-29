<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Api;

use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;

/**
 * The customer list, in whichever representation the client asked for.
 *
 * One route, two media types, and the interesting part is how little code the
 * second one costs. There is no separate web kernel and no separate API kernel:
 * a browser request and a REST request are the same request arriving with
 * different Accept headers, so answering both is a branch rather than an
 * architecture.
 *
 *     curl /customers                                 -> the page
 *     curl -H 'Accept: application/json' /customers   -> the payload
 *     curl /customers.json                            -> the payload, for clients
 *                                                        that cannot set Accept
 *
 * The JSON branch delegates to ListCustomers -- the same handler the .json
 * route uses -- so the two representations cannot drift apart. Duplicating the
 * query here is how they would.
 */
final class CustomerPage
{
    public function __construct(
        private readonly CustomerQuery $customers,
        private readonly TemplateManager $templates,
        private readonly ListCustomers $json,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Offers in the server's order of preference, which is what breaks a
        // tie the client did not break -- a browser sending Accept: */* gets
        // the page. Anything this endpoint cannot produce is a 406 rather than
        // a payload the client has no way to read.
        if ($request->negotiate(['text/html', 'application/json']) === 'application/json') {
            return ($this->json)($request);
        }

        $page = $this->customers->listPage(1, 25);

        // Two renders, not one with a magic parent: the page produces markup,
        // the layout is handed it. A layout is a template, not a keyword.
        // The page is a PHP template shipped by this module; the layout is the
        // active theme's Twig one. Handing markup from one engine to the other
        // is a string, so the two mix without either knowing.
        $content = $this->templates->render('@Example/customers', [
            'customers' => $page->items(),
            'total' => $page->total,
        ]);

        $html = $this->templates->render('layout', [
            'title' => 'Customers',
            'content' => $content,
            'home' => $request->basePath() . '/',
        ]);

        return (new Response($html))->withContentType('text/html');
    }
}
