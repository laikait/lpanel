<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Dispatch;

use App\Engine\Http\Request;
use App\Engine\Http\Response;

final class Greeting
{
    /** Defaulted so handlers that do not care about it stay autowirable. */
    public function __construct(public readonly string $text = 'hello') {}
}

final class InvokableHandler
{
    public function __construct(private readonly Greeting $greeting) {}

    public function __invoke(): Response
    {
        return new Response($this->greeting->text);
    }
}

final class MethodHandler
{
    public function __construct(private readonly Greeting $greeting) {}

    public function greet(): Response
    {
        return new Response($this->greeting->text);
    }

    public static function staticGreet(): Response
    {
        return new Response('static');
    }

    public function showString(string $id): Response
    {
        return new Response('id=' . $id);
    }

    public function showInt(int $id): Response
    {
        return new Response('int:' . $id);
    }

    public function offset(int $value): Response
    {
        return new Response('int:' . $value);
    }

    public function amount(float $value): Response
    {
        return new Response('float:' . $value);
    }

    public function flag(bool $value): Response
    {
        return new Response('bool:' . ($value ? 'true' : 'false'));
    }

    /** Declared in the opposite order to the path, to prove binding is by name. */
    public function reversedOrder(string $second, string $first): Response
    {
        return new Response('second=' . $second . ' first=' . $first);
    }

    /** The parameter is named "request" on purpose; the real Request must win. */
    public function echoesRequestPath(Request $request): Response
    {
        return new Response($request->path());
    }
}
