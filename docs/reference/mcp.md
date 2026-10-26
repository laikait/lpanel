# MCP

The [Model Context Protocol](https://modelcontextprotocol.io) is how an AI
client — a desktop assistant, an editor, an agent — asks an application to do
something. It offers three kinds of thing:

| Kind | Is | Example |
|---|---|---|
| **Tool** | something the client can call | "void invoice INV-42" |
| **Resource** | something the client can read, addressed by a URI | `invoice://INV-42` |
| **Prompt** | a ready-made instruction the client can fill in | "draft a reminder for…" |

Your modules declare them, the same way they declare routes and commands:

```php
$module->mcp(static function (McpCollector $mcp): void {
    $mcp->tool('invoice.void', VoidInvoice::class, 'Void an unpaid invoice.', permission: 'invoice.void');
});
```

**MCP is an interface to what your modules already do.** It holds no business
logic and exposes nothing a module has not named: there is no generic query
tool, no filesystem browser, no shell, and no way to reach a class by name.

The framework serves it over STDIO for a local client and over HTTP for a remote
one.

Module-facing classes are **Experimental**; the server, registry and transports
are Internal. See [`STABILITY.md`](../../STABILITY.md).

## How it fits together

```text
module.php ──$module->mcp()──► McpRegistry      (name → handler class, owning module, permission)

stdin/stdout ─► StdioTransport ┐
POST /mcp ────► HttpTransport ─┴► McpServer ─► ToolRunner / ResourceReader / PromptProvider
                                                 │  authorize → mcp.tool.input → validate
                                                 │  → mcp.tool.before → your class → output filter
                                                 └► your module's service, which does the work
```

- **A transport** decides who is calling. The STDIO user is chosen by whoever
  starts the process; over HTTP it is the bearer token's owner. It also turns
  bytes into messages. Nothing a client sends can change the identity.
- **The server** speaks the protocol: `initialize`, `ping`, `tools/*`,
  `resources/*` and `prompts/*`.
- **The runners** resolve a registered name to its class, authorize it,
  validate the input, call it, and shape the result.
- **Your class** is thin. It asks a service your module already has — the same
  service a route or a job would call.

## A complete example

One module offering one read-only tool, one resource and one prompt.

```php
// modules/Invoices/module.php
return static function (ModuleContext $module): void {
    $module->name('Invoices');

    $module->access(static function (AccessCollector $access): void {
        $access->capability('invoice.read', 'See invoices.');
        $access->role('accountant', ['invoice.read']);
    });

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('invoice.find', FindInvoice::class, 'Find an invoice by its number.', permission: 'invoice.read')
            ->resource('invoice://{number}', InvoiceResource::class, 'One invoice, as JSON.', permission: 'invoice.read')
            ->prompt('invoice.chase', ChaseLetter::class, 'Draft a reminder for an overdue invoice.');
    });
};
```

```php
final class FindInvoice implements Tool
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['number' => ['type' => 'string', 'pattern' => '^INV-[0-9]{1,10}$']],
            'required' => ['number'],
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $invoice = $this->invoices->find($arguments['number'], $context->identity);

        return $invoice === null
            ? ToolResult::error('No invoice has that number.')
            : ToolResult::structured(['number' => $invoice->number, 'total' => $invoice->total, 'status' => $invoice->status]);
    }
}
```

Working code to copy: `tests/Fixtures/Modules/McpDemo/Plugins/McpDemo/` is a
module with one of each kind, plus a hook and a filter, and
`tests/Feature/McpDemoModuleTest` drives it over both transports.
`tests/Fixtures/Modules/System/Plugins/Server/` shows
[system operations](system.md#who-may-ask) as MCP tools.

## Declaring capabilities

Declare them inside `$module->mcp()`, while `module.php` loads. A capability
cannot be added later.

| Kind | Declared as | Name must be |
|---|---|---|
| Tool | `->tool(name, Handler::class, description, permission: ?)` | `a-z`, digits, `.`, `_`, `-`; up to 128 characters, starting with a letter |
| Resource | `->resource(uri, Handler::class, description, permission: ?)` | an absolute URI such as `status://app`, or a template such as `invoice://{number}` |
| Prompt | `->prompt(name, Handler::class, description, permission: ?)` | as for a tool |

- The handler is built by the container, so it takes its services in its
  constructor.
- A name is claimed by one module only. A duplicate stops the boot, and the
  message names both modules.
- A **disabled module offers nothing**: its capabilities are never registered.
- `php laika mcp:list [--module=Invoices]` shows every capability, its
  module, what a caller needs, and how each transport is configured.

## Permissions

A permission is an ordinary [auth capability](auth.md), declared by the module
that checks it and granted through roles.

- **Anything that reads private data or changes something must name one.** A
  capability with no permission is open to any signed-in user, and `mcp:list`
  shows it as "any user".
- **A caller without the permission cannot tell the capability exists.** It is
  not listed, and calling it answers exactly as calling a name that does not
  exist. The refusal is logged as a warning for you, not reported to the client.
- **Guests are refused everything** unless `mcp.allow_guests` is true — and even
  then, a guest can use only capabilities that name no permission.
- **The permission is the first check, not the only one.** "May read invoices"
  is not "may read *this* invoice", so pass `$context->identity` through to your
  service, as `FindInvoice` does.

## Writing a tool

`Tool` has two methods: `inputSchema(): array` and
`call(array $arguments, McpContext $context): ToolResult`.

```php
ToolResult::text('Invoice INV-42 was voided.');
ToolResult::structured(['number' => 'INV-42', 'status' => 'void'], 'Voided INV-42.');
ToolResult::error('INV-42 is already paid and cannot be voided.');
```

- `structured()` takes **plain data only**: scalars, null, and arrays of them.
  A model or any other object is refused, because what an object serialises to
  is whatever its properties happen to be. Build the array you mean to send.
- `error()` is for a failure worth telling the model about, which it may work
  around. Throw an `McpException` (such as `ToolException`) only to refuse in
  protocol terms.
- **Any other exception** goes to the error log with the request id, and the
  client gets "The tool failed." Its message never reaches the client, because
  that is exactly where a SQL fragment or a path would be.
- The arguments you receive have already been checked against your schema and
  normalised: defaults are filled in, and `7.0` becomes `7`.

### Input schemas

A strict subset of JSON Schema, and it **fails closed**: a keyword it does not
support is an error when the tool is used, not something ignored.

| Supported | |
|---|---|
| `type` | `object`, `string`, `integer`, `number`, `boolean`, `array`, `null` |
| objects | `properties`, `required`, `additionalProperties` (boolean) |
| arrays | `items`, `minItems`, `maxItems` |
| values | `enum`, `const`, `minLength`, `maxLength`, `pattern` (PCRE, UTF-8), `minimum`, `maximum` |
| annotations | `default`, `description`, `title` |

A property you did not list is **refused** unless `additionalProperties` is
`true`, and a tool whose schema is just `{"type": "object"}` accepts no arguments
at all. Arguments are limited to 256 KiB and 32 levels of nesting. A validation
failure answers `InvalidParams` with up to ten `{path, message}` problems, and
never echoes the offending value.

For an operation that changes something important, ask the model to confirm in
the call itself:

```php
'confirm' => ['type' => 'boolean', 'const' => true]
```

That is not security — the permission is — but a client that shows tool calls
shows the confirmation.

## Writing a resource

```php
final class InvoiceResource implements Resource
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function read(string $uri, array $parameters, McpContext $context): ResourceContents
    {
        $invoice = $this->invoices->find($parameters['number'], $context->identity)
            ?? throw ResourceException::notFound($uri);

        return ResourceContents::json($uri, ['number' => $invoice->number, 'total' => $invoice->total]);
    }
}
```

Three ways to answer: `ResourceContents::text($uri, $text, $mimeType)`,
`::json($uri, $data)` and `::blob($uri, $bytes, $mimeType)`.

A URI without placeholders is listed by `resources/list`; one with placeholders
is listed by `resources/templates/list`.

**A placeholder matches one path segment of printable text.** Before your class
runs it is URL-decoded, then refused if it contains a `/` or a control
character. So `invoice://..%2F..%2Fetc` never reaches you — but it is still the
client's text: look it up, never use it as a path.

## Writing a prompt

```php
final class ChaseLetter implements Prompt
{
    public function arguments(): array
    {
        return [new PromptArgument('number', 'The invoice number.', required: true)];
    }

    public function get(array $arguments, McpContext $context): PromptResult
    {
        return new PromptResult([
            PromptMessage::user(\sprintf('Draft a polite reminder for invoice %s.', $arguments['number'])),
        ], 'Reminder for an overdue invoice');
    }
}
```

Arguments are strings. A missing required argument, or one the prompt does not
declare, is refused before `get()` runs.

## Settings

`config/mcp.php` returns the `mcp` block. Every key is optional:

```php
return [
    'enabled' => true,
    'server' => ['name' => 'Acme ERP', 'version' => '4.2.0'],
    'transports' => ['stdio' => true, 'http' => true],
    'http' => ['path' => '/mcp'],
    'allow_guests' => false,
];
```

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | The switch for all of MCP. Off, `mcp:stdio` refuses to start and the HTTP path returns 404 |
| `server.name`, `server.version` | `lphp`, the framework version | What `initialize` reports to a client. One line, 1–100 characters |
| `transports.stdio` | `true` | Whether `mcp:stdio` will serve. On its own this opens nothing |
| `transports.http` | `false` | Whether the HTTP endpoint exists |
| `http.path` | `/mcp` | The endpoint's exact path. Surrounding slashes are ignored. A query, fragment, `..`, percent-escape or bare `/` is refused |
| `allow_guests` | `false` | Whether a caller with no credentials is served as a guest, or refused |
| `log.enabled` | `true` | Whether the `mcp` log channel records MCP traffic — see [Logging](#logging) |

Every value is checked when MCP is first used, and a wrong one raises a
`ConfigurationException` naming the key. The HTTP endpoint needs its settings on
every request, so an invalid `mcp` block fails every request until it is fixed,
just as a malformed security key does.

## STDIO

```sh
php laika mcp:stdio --user=ada
```

The MCP client starts this command and talks to it over the pipe. Most clients
take the command as JSON:

```json
{ "mcpServers": { "acme": { "command": "php", "args": ["/srv/app/laika", "mcp:stdio", "--user=ada"] } } }
```

- **The session acts as the named user for its whole life.** The user is looked
  up by login, then by id. An unknown or inactive account is refused with a
  message that does not say which. Without `--user`, the session is a guest.
  Whoever can run the console chooses the user — the same trust as running any
  other command.
- **stdout carries only protocol messages.** Anything a handler prints is
  discarded and reported on stderr as a byte count.
- A line longer than 1 MiB is skipped whole and answered as an invalid request.
  The process exits when the client closes stdin.

## HTTP

Once `mcp.transports.http` is on, the endpoint takes one JSON-RPC message per
`POST` to `mcp.http.path`.

**It is not a route.** The kernel answers it before routing, as it does for
assets, so route meta, CSRF and the route guard do not apply. The request size
limit, the security headers and `response.instance` still run.

- **Bearer tokens only.** The session cookie is never consulted, because the
  browser attaches it on its own. Tokens come from the application's
  `TokenProvider`, as [for REST](auth.md). A request with no valid token gets
  `401` with `WWW-Authenticate: Bearer realm="mcp"`. A token that is sent and
  rejected is never downgraded to a guest, and a token in the query string is
  ignored.
- **Same-origin browsers only.** A request whose `Origin` header names another
  scheme, host or port gets `403`, which blocks DNS rebinding. Clients that are
  not browsers send no `Origin` and are unaffected.
- **Stateless.** There is no `Mcp-Session-Id` and no event stream. Each request
  stands alone, at the version in `MCP-Protocol-Version` (2025-03-26 when the
  header is absent). `initialize` is still answered.

| Status | When |
|---|---|
| 200 | A request was answered. This includes a JSON-RPC error about the request, such as an unknown tool |
| 202 | A notification: accepted, with no body |
| 400 | Unreadable JSON, an invalid message or batch, a message over 1 MiB, or an unsupported protocol version |
| 401 | No valid bearer token, and guests are not allowed |
| 403 | The `Origin` header names another site |
| 405 | Any method but `POST` (`Allow: POST`) |
| 406 | `Accept` excludes `application/json` |
| 415 | The body is not `application/json` |

Every refusal still carries a JSON-RPC error, with a null `id`.

Apache does not pass the `Authorization` header to PHP on its own.
`public/.htaccess` has the rule that does, so keep it if you replace that file.

## Logging

MCP traffic goes to the `mcp` log channel, so you can route it somewhere of its
own by channel name. Every record carries the MCP request id, transport, client,
principal (a user id, or null for a guest), capability, kind and owning module.

| Level | Record |
|---|---|
| warning | Access denied: authorization refused a capability. The client was told it does not exist |
| warning | A capability failed. The record holds the exception class only; the exception itself goes to the error log |
| notice | A capability refused in protocol terms |
| info | A tool called (with `ms`), a resource read (with its URI), a prompt loaded, a request that failed (with the JSON-RPC code) |
| debug | Every request, with its method |

**Arguments, results, resource contents and prompt text are never logged**,
because that is where a customer's data or a secret would be. The logging
layer's own redaction (`logging.redact`) still applies to what is logged.

Set `mcp.log.enabled` to `false` to stop these records; the hooks keep firing
for any other listener.

## Hooks and filters

The `mcp.*` hooks and filters are listed in the
[lifecycle table](hooks-and-filters.md#lifecycle-extension-points). Register
them from `onBoot`, as for any other hook:

```php
$module->onBoot(static function (HookEngine $hooks): void {
    $hooks->add('mcp.tool.after', static function (Capability $tool, ToolResult $result, McpContext $context): void {
        // count, audit, notify
    }, 10, 'Invoices');
});
```

They never run ahead of the security checks. A capability the caller may not use
reaches no listener. `mcp.tool.input` runs **before** schema validation, so a
filter cannot add input the schema forbids. An output filter must return the
same kind of value it was given, or the call fails and is reported. A listener
can refuse a call by throwing an MCP exception, but it cannot grant access to
anything.

## Security

These hold for every capability, and each has a test
(`tests/Feature/McpSecurityTest` and the architecture tests):

- **Only registered names resolve.** Class names, `Class::method` strings,
  function names and near misses are all "Unknown tool.", and nothing is
  attempted.
- **MCP itself reaches no infrastructure.** `engine/MCP` imports no database,
  model or system code, and calls no file or process function. What a tool can
  do is exactly what your service does.
- **Every refusal is indistinguishable from absence.** The log tells you the
  truth; the client learns nothing.
- **Nothing a handler throws reaches the client**, even with `app.debug` on.
- **Input is bounded.** Messages are limited to 1 MiB, JSON nesting to 64
  levels, arguments to 256 KiB and 32 levels, and an echoed unknown name to 128
  characters.

What remains yours: choose permissions, check the specific record in your
service, give tools names that say what they do, and prefer read-only tools. A
tool that changes something deserves a `confirm` argument and a narrow
permission.

## In production

There is **no manifest to build**. Capabilities are declared in `module.php`,
which runs on every boot anyway, as routes are. The module discovery cache that
`cache:warm` writes already removes the filesystem scan, and registering a
hundred capabilities takes about 0.15 ms (`composer bench`). What `mcp:list`
shows is exactly what is served.

An MCP call over HTTP costs less than an ordinary JSON request, and a page
request with MCP enabled builds nothing of MCP.

## Testing

Call the server the application built, as a client would:

```php
$app = $this->application(['modules' => ['paths' => ['modules']]])->boot();

$session = new McpSession(new Identity('7', 'ada', ['accountant']), 'stdio');
$session->initialize('2025-06-18', 'test', '1');

$answer = $app->container()->get(McpServer::class)->handle(
    '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"invoice.find","arguments":{"number":"INV-42"}}}',
    $session,
);
```

Over HTTP, switch the transport on in the test's configuration and `handle()` a
`POST /mcp` with a bearer token, as `tests/Feature/McpHttpSliceTest` does.

Test the refusals as well as the answers: a caller without the permission, a
guest, and input the schema refuses.

## Troubleshooting

| Symptom | Cause |
|---|---|
| The client sees no tools | The user lacks every permission, or is a guest. `mcp:list` shows what each tool needs |
| "Unknown tool." for a tool that exists | The caller lacks its permission, or its module is disabled. Look for a `denied` warning in the `mcp` log |
| `POST /mcp` is 404 | `mcp.transports.http` is off, or `mcp.enabled` is false, or the path differs from `mcp.http.path` |
| Every request is 401 | No token was sent, or Apache stripped the `Authorization` header, or the application's user provider does not implement `TokenProvider` |
| 403 from a browser-based client | Its `Origin` is not this server's scheme, host and port |
| The STDIO client says it received invalid JSON | Something wrote to stdout outside a handler, such as a `module.php` that echoes. Handler output is caught and reported on stderr |
| "The tool failed." | The handler threw. The exception is in the error log, and the `mcp` log's warning at the same moment names the tool and the exception class |
| A `ConfigurationException` on every request | The `mcp` block is invalid; the message names the key |
