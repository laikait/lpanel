<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Csrf;
use App\Engine\Template\TemplateManager;

/** Renders an Identity page inside the LPanel layout, with what every page needs. */
final class Pages
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Csrf $csrf,
        private readonly Router $router,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(Request $request, string $template, array $data = [], int $status = 200, ?Identity $identity = null): Response
    {
        $html = $this->templates->render($template, $data + [
            'token' => $this->csrf->token($request),
            'home' => $request->basePath() . '/',
            'me' => $identity !== null && !$identity->isGuest() ? $identity : null,
            'urls' => [
                'home' => $this->router->url('home'),
                'login' => $this->router->url('login.show'),
                'login_code' => $this->router->url('login.code'),
                'logout' => $this->router->url('logout'),
                'security' => $this->router->url('account.security'),
            ],
        ]);

        return (new Response($html))
            ->withStatus($status)
            ->withContentType('text/html')
            // Login and security pages show codes and errors that must not be cached anywhere.
            ->withHeader('Cache-Control', 'no-store');
    }

    public function url(string $name): string
    {
        return $this->router->url($name);
    }
}
