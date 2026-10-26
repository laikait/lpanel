# Storage

`Storage\Storage` holds the application's disks: places files live. One
interface, `Disk`, whether the files are in a directory on this machine, an S3
bucket, or memory in a test.

```php
use App\Engine\Storage\Storage;

public function __construct(private readonly Storage $storage) {}

$disk = $this->storage->disk('s3');          // or disk() for STORAGE_DISK

$disk->put('invoices/2026/0042.pdf', $pdf);   // a string or a readable stream
$pdf  = $disk->get('invoices/2026/0042.pdf');
$link = $disk->temporaryUrl('invoices/2026/0042.pdf', time() + 600);
```

## The interface

| | |
|---|---|
| `put($path, $contents)` | writes, replacing; never half-written. `$contents` is a string or a stream |
| `get($path)`, `readStream($path)` | the file, or a `StorageException` when there is none |
| `exists($path)`, `delete($path)` | `delete()` is false when there was nothing to delete |
| `size($path)`, `lastModified($path)` | bytes, and a Unix timestamp |
| `files($prefix)` | every file under a prefix, at any depth, sorted |
| `url($path)` | a permanent public URL, or `null` when the disk has none configured |
| `temporaryUrl($path, $expiresAt)` | a URL that stops working at `$expiresAt` |

**Paths are relative, with forward slashes**: `a/b.txt`. A leading `/`, a `..`
or `.` segment, `a//b`, a backslash or a control character is refused with a
`StorageException` on every disk, so a path that works on one works on all
of them, and none reaches outside its disk.

**The local disk also refuses what Windows reads as something else**, on every
operating system, so a path that works on a laptop cannot misbehave on a
Windows server: a `:` anywhere (`report.txt:hidden` is a hidden data stream), a
segment ending in `.` or a space (`report.php.` is `report.php`), and the
device names `CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9` and `LPT1`–`LPT9`, with
or without an extension. S3 and memory keys are not files and keep the shorter
rule, where `:` is an ordinary character.

**Directories are not things.** A path implies them: `put()` creates what it
needs, and `files('a')` lists `a/b.txt` but never `ab.txt`. That is what object
storage offers, and a local disk behaves the same.

## Disks

```php
// config/storage.php
return [
    'default' => 'local',
    'disks' => [
        'local'   => ['driver' => 'local', 'root' => 'system/Storage'],
        'uploads' => [
            'driver' => 's3',
            'bucket' => 'shop-uploads',
            'region' => 'eu-central-1',
            'key' => Env::string('UPLOADS_KEY'),
            'secret' => Env::string('UPLOADS_SECRET'),
        ],
        'r2' => [
            'driver' => 's3',
            'bucket' => 'media',
            'region' => 'auto',
            'endpoint' => 'https://<account>.r2.cloudflarestorage.com',
            'key' => …, 'secret' => …,
        ],
    ],
];
```

Two disks are configured out of the box: `local`, and `s3` from the `S3_*`
variables (see [Default settings](defaults.md#storage)). Disks are built on
first use, so an unused one costs nothing and a missing bucket is an error only
where it is used.

### local

A directory, relative to the application unless absolute; created on the first
write. `system/Storage` is ignored by git.

- **Nothing gets out of the root**, a symbolic link included: the directory a
  file is in is resolved and must still be inside the root, and that is checked
  before any directory is created. `files()` neither lists nor follows links.
- **Writes are atomic**: to a temporary file beside the target, then renamed.
- **`temporaryUrl()`** is a [signed link](routing.md#signed-links) to the Shared
  module's `storage.file` route, `GET /files/{disk}?path=…`, which streams the
  file. A changed link gets 403 and an expired one 410 before the handler runs.
  Images, PDFs, plain text, MP3 and MP4 are shown in the browser; everything
  else — HTML and SVG above all, which can run script — downloads.
- **`url()`** is `null` unless `url` is set, because the root is not served:
  only `public/` is.

### s3

Amazon S3 and anything that speaks its API: Cloudflare R2, DigitalOcean
Spaces, Backblaze B2, MinIO, Wasabi.

| Setting | |
|---|---|
| `bucket`, `region`, `key`, `secret` | required |
| `endpoint` | empty for AWS; the service's URL otherwise, `http://127.0.0.1:9000` for a local MinIO |
| `path_style` | `true` puts the bucket in the path (`/bucket/key`) rather than the host name; MinIO wants it |
| `url` | a public base URL, such as a CDN in front of the bucket, for `url()` |
| `root` | a prefix inside the bucket, so several applications can share one |

- **No SDK.** Requests go through the [HTTP client](http-client.md), signed with
  AWS Signature Version 4 by `Storage\S3\SignatureV4`, which is tested
  against AWS's published examples. It signs only `host` and the `x-amz-*`
  headers, so an `http.client.request` filter adding a header cannot break it.
- **Large files go up in parts**: over 16 MB, `put()` makes a multipart upload,
  16 MB at a time, read from the stream you gave it. A failed upload is aborted,
  so the bucket is not left holding parts.
- **`temporaryUrl()`** is a presigned GET, good for at most seven days (S3's
  own limit).
- **An object is stored with its real type only when that type cannot run
  script** (`Storage::INLINE_TYPES`: images, PDF, plain text, MP3, MP4), and as
  `application/octet-stream` otherwise. The bucket serves an object with the
  type it was stored under to anyone holding its URL, so an uploaded `.html`
  or `.svg` stored as itself would be a page running script on the bucket's
  origin; as `octet-stream` it downloads.
- **`delete()` costs a HEAD first**, because S3 answers the same to deleting a
  file and to deleting nothing.
- **`readStream()` buffers** the object (in memory up to 2 MB, a temporary file
  beyond), because the HTTP client reads whole responses.
- Needs PHP's `simplexml` extension, present in almost every build.

### memory

For tests. Replace the whole thing:

```php
$container->instance(Storage::class, Storage::fake(['local', 'uploads']));
```

## Uploads

```php
$path = UploadPolicy::images()->storeOn($request->file('avatar'), $this->storage->disk('s3'), 'avatars');
// "avatars/3f9c0a…e1.jpg", on the disk
```

The same checks (it throws `SecurityException` if the file fails them) and the
same generated name as `store()` (see
[Uploads](security.md#uploads)), streamed from PHP's temporary file to any disk.

## FTP and SFTP

Not yet. They are planned as further drivers on PHP's `ftp` and `ssh2`
extensions.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| `"…" is not a storage path` | An absolute path, `..`, `a//b` or a backslash | Use `a/b.txt`; build paths with `/` |
| `HTTP 403 (SignatureDoesNotMatch)` | Wrong secret, or the machine's clock is more than 15 minutes out | Check `S3_SECRET`; run NTP |
| `HTTP 301` or `HTTP 400 (AuthorizationHeaderMalformed)` | The region is wrong for the bucket | Set the bucket's region |
| `HTTP 404 (NoSuchBucket)` | Bucket name, or path style on a server that needs it | Check `S3_BUCKET`; try `S3_PATH_STYLE=true` |
| `cannot make temporary URLs` on a local disk | The `storage.file` route is gone, or there is no `APP_KEY` | Keep the Shared module's route; set `APP_KEY` |
| `There is no "uploads" disk` | Not under `storage.disks` | Add it in `config/storage.php` |
