# Pages and forms

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

This guide shows how to put HTML pages in your application:

- render a page from your module,
- put it inside the site's layout, and reuse small pieces (partials),
- add stylesheets, scripts and images,
- build a form that validates its input,
- accept file uploads,
- change pages that another module or the framework made.

Do [Getting started](../getting-started.md) first: this guide assumes you have a
module with a route. The examples use a module called `Desk`, in
`modules/Desk/`. Replace `Desk` with your own module's name.

## Render a page

A page needs three things, all in your module:

| What | Where | Does |
|---|---|---|
| a template | `Templates/profile.twig` | the HTML. Its name is `@Desk/profile`. |
| a handler | a class in `Http/` | renders the template and returns a `Response` |
| a route | `module.php` | connects a URL to the handler |

The handler's method looks like this:

```php
public function __invoke(Request $request): Response
{
    $html = $this->templates->render('@Desk/profile', [
        'user' => $user,
        'home' => $request->basePath() . '/',
    ]);

    return (new Response($html))->withContentType('text/html');
}
```

- `$this->templates` is a `TemplateManager`. Ask for it in the handler's
  constructor, and the framework passes it in.
- **Never write the file extension.** `render('x')` finds `x.twig` or `x.php`, so
  you can switch a template from PHP to Twig without changing any code that uses
  it.
- The array is the data the template can print.
- **Pass `home`** when the page uses the site's layout, so its home link works
  even when the application runs in a subfolder, as on XAMPP.

Where there is no constructor, such as in `module.php` or inside a template, the
function `template()` gives you the same manager: `template()->render(...)`.

## Put the page inside the layout

The **layout** is the template every page shares: the `<head>`, header and
footer. The site's layout is `templates/layout.twig`. It has three **blocks**,
places a page can fill: `title`, `content` and `footer`.

A Twig page fills them like this:

```twig
{% extends "layout.twig" %}

{% block title %}Contact us{% endblock %}

{% block content %}
<h1>Contact us</h1>
{% endblock %}
```

Twig **escapes** every value it prints: a `<` in the data is shown as `<`, and
never becomes HTML. That is what stops a visitor from putting a script into your
page. If a value is HTML you trust, print it with `{{ value|raw }}`. The word
`raw` then stands out when someone reviews the code.

A **PHP** template cannot extend a layout. Render the page first, then pass the
result to the layout as `content`:

```php
$content = $this->templates->render('@Desk/legacy', ['rows' => $rows]);
$html = $this->templates->render('layout', ['title' => 'Legacy', 'content' => $content]);
```

## Reuse a piece of a page (partials)

A **partial** is a small template used inside other templates, such as a message
box. In Twig:

```twig
{% include "@Desk/partials/message.twig" with {message: message} only %}
```

`only` means the partial sees just the values you pass, here `message`.

In a PHP template:

```php
<?= $view->render('@Desk/partials/message', ['message' => $message]) ?>
```

A PHP partial always sees only what it is passed, never its parent's variables.

## Escaping in PHP templates

Twig escapes for you. **PHP templates escape nothing by themselves**, so every
value needs escaping by hand. Every PHP template gets a helper called `$e`, and
which method to use depends on where the value goes:

```php
<p><?= $e($message->body()) ?></p>
<a title="<?= $e->attr($tip) ?>" href="?q=<?= $e->url($term) ?>">
<script>const id = <?= $e->js($id) ?>;</script>
<?= $e->raw($trustedHtml) ?>
```

| Where the value goes | Use |
|---|---|
| between tags | `$e($value)` |
| inside an attribute | `$e->attr($value)` |
| in a URL | `$e->url($value)` |
| inside `<script>` | `$e->js($value)` |
| HTML you trust | `$e->raw($value)` |

Forgetting `$e` once is a security hole. That is why new templates should be
Twig. See [What a PHP template gets](../reference/templates.md#what-a-php-template-gets).

## Stylesheets, scripts and images

Put them in an `assets/` folder in your module. There is nothing to register:
the folder is published because it exists.

```
modules/Desk/assets/css/desk.css   →   /assets/module/Desk/css/desk.css?v=…
```

Link to a file with `asset()`, never with a hand-written URL. In Twig:

```twig
{% block content %}
<link rel="stylesheet" href="{{ view.asset().module('Desk', 'css/desk.css') }}">
{% endblock %}
```

| From | Write |
|---|---|
| a Twig template | `view.asset().module('Desk', 'css/desk.css')` |
| a PHP template | `$view->asset()->module('Desk', 'css/desk.css')` |
| `module.php` | `asset()->module('Desk', 'css/desk.css')` |

- **The `?v=…` part** is made from the file's contents. When the file changes,
  the URL changes, so browsers can cache it for a long time and still get every
  new version.
- **Files for the whole site** go in `templates/assets/` and are linked with
  `asset()->template('css/theme.css')`. Files that are not about the look, such
  as a favicon, go in `public/assets/`, linked with `asset()->core(...)`.

`modules/` is not reachable from the web. The URL works because the framework
serves those files itself, after checking the path is safe. See
[Why PHP serves them at all](../reference/assets.md#why-php-serves-them-at-all).

## A form

A form that works properly needs four things:

1. a **CSRF token**, a hidden value that proves the form came from your site;
2. a **schema** that says what valid input looks like;
3. a way to **show the errors** next to the fields;
4. a **redirect** after it worked, so reloading the page does not send it twice.

Here is a complete contact form. It has four files.

### 1. The schema: `Schema/MessageSchema.php`

A **schema** lists the fields a form accepts and the rules for each:

```php
final class MessageSchema
{
    public static function input(): Schema
    {
        return Schema::of(
            'message.input',
            Field::string('email')->check(
                'an email address',
                static fn(mixed $value): bool => \is_string($value) && \filter_var($value, \FILTER_VALIDATE_EMAIL) !== false,
            ),
            Field::string('body')->length(1, 2000),
        );
    }
}
```

- `email` must be a string, and the `check()` function must answer `true` for
  it. `'an email address'` is what the error message says was expected.
- `body` must be between 1 and 2000 characters long.

### 2. The handler: `Http/ContactForm.php`

```php
final class ContactForm
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Csrf $csrf,
        private readonly Router $router,
        private readonly MessageRepository $messages,
        private readonly Queue $queue,
    ) {}

    public function show(Request $request, Session $session): Response
    {
        return $this->page($request, [
            'status' => $session->get('status'),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function send(Request $request, Session $session): Response
    {
        $input = ['email' => $request->input('email'), 'body' => $request->input('body')];
        $result = MessageSchema::input()->validate($input);

        if (!$result->isValid()) {
            return $this->page($request, [
                'status' => null,
                'errors' => $result->messages(),
                'old' => $input,
            ])->withStatus(422);
        }

        /** @var array{email: string, body: string} $attributes */
        $attributes = MessageSchema::input()->deserialize($input);
        $message = $this->messages->receive($attributes);

        $this->queue->push(new AcknowledgeMessage($message->identity() ?? 0));
        $session->flash('status', 'Thank you. We will reply soon.');

        return new RedirectResponse($this->router->url('contact.show'), 303);
    }

    /** @param array<string, mixed> $data */
    private function page(Request $request, array $data): Response
    {
        $html = $this->templates->render('@Desk/contact', $data + [
            'action' => $this->router->url('contact.send'),
            'token' => $this->csrf->token($request),
            'home' => $request->basePath() . '/',
        ]);

        return (new Response($html))->withContentType('text/html');
    }
}
```

What happens, step by step:

1. **`show()`** displays the empty form. `$session->get('status')` picks up the
   "thank you" message if the visitor was just redirected here.
2. **`send()`** receives the submitted form. It collects the two fields and
   validates them with the schema.
3. **If anything is wrong**, it shows the form again with the errors and with
   what the visitor typed (`old`), and status `422` ("the input was refused").
4. **If it is valid**, `deserialize()` keeps only the fields the schema lists. A
   visitor cannot sneak in a field you did not put on the form.
5. It saves the message, and queues a job to answer it later. See
   [Background work](background-work.md).
6. **`flash()`** stores the "thank you" for the next request only.
7. It **redirects** with status `303` to the form page. Reloading that page just
   reloads it, instead of sending the form again.

`page()` renders the template with the form's URL (`action`) and the CSRF
`token`. `$this->router->url('contact.send')` builds the URL from the route's
name, so it is always right.

> **`Session` is a parameter of the method, not of the constructor.** It is this
> request's session, and the framework passes it in per request. Never ask for it
> in a constructor.

### 3. The template: `Templates/contact.twig`

```twig
{% extends "layout.twig" %}

{% block title %}Contact us{% endblock %}

{% block content %}
<h1>Contact us</h1>

{% if status %}<p class="status">{{ status }}</p>{% endif %}

<form method="post" action="{{ action }}">
    <input type="hidden" name="_token" value="{{ token }}">

    <label>Email <input name="email" value="{{ old.email|default('') }}"></label>
    {% for message in errors.email|default([]) %}<p class="error">Email {{ message }}</p>{% endfor %}

    <label>Message <textarea name="body">{{ old.body|default('') }}</textarea></label>
    {% for message in errors.body|default([]) %}<p class="error">Message {{ message }}</p>{% endfor %}

    <button type="submit">Send</button>
</form>
{% endblock %}
```

- The hidden `_token` field carries the CSRF token.
- `old.email|default('')` refills the field after an error, or leaves it empty.
- `errors.email` holds every message for that field, so all problems show at
  once.

### 4. The routes, in `module.php`

```php
$routes->get('/contact', [ContactForm::class, 'show'])->name('contact.show');
$routes->post('/contact', [ContactForm::class, 'send'])
    ->name('contact.send')
    ->meta(['rate_limit' => '5/1m']);
```

The same URL, `/contact`, shows the form on `GET` and receives it on `POST`.
`'rate_limit' => '5/1m'` accepts at most five messages a minute from one IP
address; the sixth is refused with `429`.

### How CSRF protection works

CSRF is an attack where another website makes your visitor's browser submit a
form to your site. The framework blocks it **automatically**:

- Every `POST`, `PUT`, `PATCH` and `DELETE` request is refused with `403`, unless
  it sends back the right token as a `_token` field (or an `X-CSRF-TOKEN`
  header).
- `Csrf::token()` gives you the value to put in the form, as above.

You do not switch it on. See [CSRF is opt-out](../reference/security.md#csrf-is-opt-out)
and [Flash data is ordinary data](../reference/sessions.md#flash-data-is-ordinary-data).

## Accept a file upload

```php
$policy = UploadPolicy::images();
$problems = $policy->check($request->file('avatar'));

if ($problems === []) {
    $path = $policy->store($request->file('avatar'), $directory);
}
```

- **`UploadPolicy::images()`** accepts images only, up to a size limit and 25
  megapixels, and the file must really decode as an image.
- **`check()`** returns a list of every problem, or an empty list when the file
  is fine. For several files use `checkAll()`.
- **`store()`** checks the file again, refuses it with a `SecurityException` if
  it fails, saves it under a new, safe name that it generates, and returns the
  path.

Nothing is checked until you call `check()` or `store()`, because only your code
knows what a form should accept. It needs PHP's `fileinfo` extension; without
it the content check fails rather than passes. To show the client's file name,
use `displayName()`. Store uploads outside
`public/`, for example under `system/`, so nobody can open them by URL. See
[Uploads](../reference/security.md#uploads).

## Change a page you did not write

**A template of another module.** Copy it into `templates/`, into a folder named
after the module's template namespace, and edit your copy:

```
modules/Billing/Templates/invoice.twig   the module's own
templates/Billing/invoice.twig            your copy, which is used instead
```

You never edit the other module. `php laika template:list` shows the order in
which folders are searched. See
[Resolution, and how overriding works](../reference/templates.md#resolution-and-how-overriding-works).

**The home page.** Declare a `/` route in your module, with any route name except
`home`. Your module loads after `Shared`, so your route wins.

**The error pages.** `templates/errors/404.twig` is shown for pages that do not
exist, and `templates/errors/error.twig` for every other error. To change one
status only, add a file named after it, such as `templates/errors/503.twig`. Each
error page receives `error` (with `status`, `title` and `message`) and `home`.

In **debug mode** (`APP_DEBUG=true`) the framework always shows its own detailed
error page instead, so you can see what went wrong. Turn debug off to see yours.
See [The application's own error page](../reference/errors.md#the-applications-own-error-page).

**The whole look.** Edit `templates/layout.twig`, which every default page
extends, and the stylesheet `templates/assets/css/theme.css`.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| `403` when a form is submitted | The CSRF token is missing or wrong. | Print `Csrf::token()` into a hidden `_token` field, as in the form above. |
| A template "was not found" | Wrong name, or the extension was written. | Use `@<Name>/path` without `.twig`; check with `php laika template:list`. |
| HTML shows as text, with `<` visible | Twig escaped it, as it should for data. | If the HTML is yours and trusted, print it with `\|raw`. |
| The stylesheet is a 404 | The file is not in the module's `assets/` folder, or the path is wrong. | Check the file exists; `php laika asset:list` shows what is published. |
| Your error page is not shown | Debug mode is on. | Set `APP_DEBUG=false`. |
| `422` with no errors on the page | The template does not print `errors`. | Loop over `errors.<field>` next to each field, as above. |

More in [Troubleshooting](../troubleshooting.md).

## Read more

- [Templates](../reference/templates.md): how templates are found, and everything
  a template can use.
- [Assets](../reference/assets.md): URLs, versions and caching.
- [Security](../reference/security.md): CSRF, rate limits, uploads.

<!-- {% endraw %} -->
