<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Identity\Http\Pages;

/** GET /: a guest is sent to /login; anyone signed in sees what they may use. */
final class Home
{
    public function __construct(private readonly Pages $pages, private readonly Authorizer $authorizer) {}

    public function __invoke(Request $request, Identity $identity): Response
    {
        if ($identity->isGuest()) {
            return new RedirectResponse($this->pages->url('login.show'), 303);
        }

        return $this->pages->render($request, '@Dashboard/home', [
            'admin' => $this->authorizer->allows($identity, 'panel.admin'),
            'customer' => $this->authorizer->allows($identity, 'panel.customer'),
        ], identity: $identity);
    }
}
