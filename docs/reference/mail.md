# Mail

`Mail\Mailer` sends email. Build a `Message`, hand it over:

```php
use App\Engine\Mail\Mailer;
use App\Engine\Mail\Message;

public function __construct(private readonly Mailer $mailer) {}

$this->mailer->send(Message::create()
    ->to($user->email, $user->name)
    ->subject('Your receipt')
    ->view('emails/receipt', ['order' => $order->id, 'total' => $order->total])
    ->attach($pdfPath));
```

**Nothing is sent until you choose a transport.** The default, `log`, writes each
message to the `mail` log channel instead, so a development machine never mails
a real customer. `security:check` warns while that is still the case in
production. Logging itself writes nowhere until `logging.writers` names a writer
(see [Logging](logging.md)) — set `['file']` to read the messages in
`system/Logs/`.

## Building a message

| | |
|---|---|
| `to()`, `cc()`, `bcc()`, `replyTo()` | an address and an optional name; call again to add another |
| `from()` | otherwise `mail.from.address` / `MAIL_FROM_ADDRESS` |
| `subject()` | |
| `text()`, `html()` | the body; give both and clients choose |
| `view('emails/receipt', $data)` | renders the template `emails/receipt` as the HTML, and `emails/receipt.text` as the plain-text alternative when that template exists |
| `attach($path)`, `attachData($bytes, $name, $type)` | the file is read when it is attached |
| `header('List-Unsubscribe', '<…>')` | a header of your own |

A message is **immutable**: each method returns a new one, so a base message can
be built once and sent to many.

**Line breaks in a subject, address, name or header are refused** as they are
set. That is what stops a subject taken from a form from adding a `Bcc:` header
of its own — the classic mail header injection.

Bcc recipients receive the message but are never written into it.

**An email never uses a [theme](templates.md#themes).** `view('emails/…')`
always renders from `templates/emails/` (or a module's templates), even when the
page sending it has a theme active. A queued message is rendered by a worker,
which has no request and so no theme; if the sending page's theme applied, the
same email would look different on the `sync` queue than on a real one. The
page's theme is given back as soon as the email is rendered.

## Sending later

```php
$this->mailer->queue($message);           // a queue worker sends it
$this->mailer->queue($message, 'mail');   // on its own queue
```

Talking to a mail server takes hundreds of milliseconds and can time out; a
request that is waiting should not. `queue()` puts a `SendMessageJob` on the
[queue](queue.md) with the message — **including the view's data, unrendered**,
so the worker renders it. Give a view plain values (ids, strings, arrays), not
objects holding a database connection. With `QUEUE_STORE=sync`, the default,
`queue()` sends immediately.

## Transports

| `MAIL_TRANSPORT` | |
|---|---|
| `log` | the default: nothing sent; recipients and subject logged at info, the whole message at debug |
| `smtp` | a mail server, `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` |
| `sendmail` | this machine's own (Postfix, Exim, msmtp…) at `mail.sendmail.path` |
| `array` | kept in memory, for tests |

**SMTP:** `MAIL_ENCRYPTION=tls` (the default) upgrades with STARTTLS — usually
port 587 — and **refuses a server that does not offer it** rather than sending
the password in clear. `ssl` is TLS from the first byte, port 465. `none` is for
a relay on the same machine. Certificates are verified. One connection per
message.

**sendmail** runs through the system layer's command executor, so
`system.commands` and the audit log apply, and recipients are passed as
arguments, never through a shell.

## Extension points

| | |
|---|---|
| `mail.message` (filter) | the `Message` about to be sent; return a changed one — a Bcc to an archive, a prefixed subject |
| `mail.allowed` (filter) | `true`, and the `Message`; return `false` to not send it — a staging server, an unsubscribed address |
| `mail.sent` (hook) | the `Envelope` once the transport accepted it: sender, recipients, Message-ID, subject |

## Testing

```php
use App\Engine\Mail\Transport;
use App\Engine\Mail\Transports\ArrayTransport;

$mail = new ArrayTransport();
$app->container()->instance(Transport::class, $mail);

// ... exercise the code ...

self::assertSame(['ana@example.com'], $mail->sent()[0]->recipients);
self::assertStringContainsString('Your receipt', $mail->sent()[0]->raw);
```

Or set `mail.transport` to `array` in the test's configuration.

## Settings

See [`mail`](defaults.md#mail) in the defaults.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Nothing arrives, and the log has "Mail not sent" | `MAIL_TRANSPORT` is `log` | Set `smtp` or `sendmail` |
| Nothing arrives, and nothing is logged either | `log` transport, and no log writer | Set a transport, or `logging.writers` to read them |
| "does not offer STARTTLS" | the server cannot encrypt on that port | `MAIL_ENCRYPTION=ssl` with port 465, or the server's TLS port |
| "refused AUTH PLAIN: 535 …" | wrong user name or password | Check `MAIL_USERNAME` / `MAIL_PASSWORD`; some providers need an app password |
| "has no From address" | neither `from()` nor `MAIL_FROM_ADDRESS` | Set `MAIL_FROM_ADDRESS` |
| A queued message renders differently | its view ran in the worker, with the data given to `view()` | Pass plain values, not objects |
| Messages land in spam | the sending domain has no SPF, DKIM or DMARC records | DNS for your domain, not the framework |
