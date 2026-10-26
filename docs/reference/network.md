# IP addresses

`App\Engine\Network` reads, checks, compares and does arithmetic on IPv4 and
IPv6 addresses and CIDR blocks. It is plain PHP on the packed form
`inet_pton()` produces, so IPv6 needs neither GMP nor BCMath.

| Class | For |
|---|---|
| `Ip` | one-line answers about an address given as a string |
| `IpAddress` | one address: its version, what kind it is, its other forms, masking and arithmetic |
| `Cidr` | a block such as `192.168.1.0/24`: its bounds, membership, listing and splitting |
| `Netmask` | prefix length ⇄ `255.255.255.0` ⇄ wildcard `0.0.0.255` |
| `IpSet` | a list of addresses and blocks asked "is this address in it?" |
| `IpVersion` | `V4` or `V6`, with `bits()` |
| `IpException` | anything unreadable, or arithmetic that leaves the address space |

## The client address behind a proxy

```php
$request->ip();          // "203.0.113.9", as a string, or null
$request->ipAddress();   // the same, as an IpAddress (a dual-stack ::ffff:a.b.c.d comes back as IPv4)
$request->ips();         // every hop: client first, REMOTE_ADDR last
```

`X-Forwarded-For` is believed only when the request came from a proxy listed in
`http.trusted_proxies`. Entries are addresses or CIDR blocks, of either version:

```php
// config/http.php
return ['trusted_proxies' => ['10.0.0.0/8', '173.245.48.0/20', '2400:cb00::/32']];
```

or `TRUSTED_PROXIES=10.0.0.0/8,173.245.48.0/20` read with `Env::list()`.

**The header is read from the right.** Each proxy appends the address it
received the request from, so the right-hand end was written by proxies this
application trusts and the left-hand end by the client, who can write anything.
The client address is the first one, reading leftwards, that is not a trusted
proxy; if every entry is trusted it is the leftmost. Reading stops at an entry
that is not an address, and a port (`203.0.113.9:51234`, `[2001:db8::1]:443`)
is dropped.

An entry in `trusted_proxies` that cannot be read is ignored at request time —
failing every request over a typo would be worse — and `php laika
security:check` reports it as a failure, as it does `0.0.0.0/0` or `::/0`,
which would let any client choose its own address.

## One-liners: `Ip`

```php
use App\Engine\Network\Ip;

Ip::isValid('10.0.0.1');                          // true
Ip::isV4('10.0.0.1');  Ip::isV6('::1');           // true, true
Ip::version('::1');                               // IpVersion::V6, or null
Ip::isPublic('8.8.8.8');                          // true
Ip::isPrivate('192.168.1.10');                    // true
Ip::isLoopback('::1');                            // true
Ip::inRange('10.1.2.3', '10.0.0.0/8');            // true
Ip::inRange('10.1.2.3', ['::1', '10.0.0.0/8']);   // true: any of a list
Ip::normalize('0:0:0:0:0:0:0:1');                 // "::1"
Ip::mask('192.168.1.77', 24);                     // "192.168.1.0"
Ip::anonymize('203.0.113.77');                    // "203.0.113.0"  (/24 for IPv4, /48 for IPv6)
Ip::range('192.168.1.0/30');                      // ['192.168.1.0', '192.168.1.1', '192.168.1.2', '192.168.1.3']
Ip::range('192.168.1.0/30', hostsOnly: true);     // ['192.168.1.1', '192.168.1.2']
Ip::toBinary('::1');                              // 16 bytes, for a VARBINARY(16) column
```

A predicate given something that is not an address answers `false`. A function
that returns an address throws `IpException` instead, as does `inRange()` for a
range it cannot read: a typo in an allowlist is a bug, not a "no".

## One address: `IpAddress`

```php
use App\Engine\Network\IpAddress;

$ip = IpAddress::parse('2001:db8::1');   // throws IpException; tryParse() returns null
IpAddress::isValid('10.0.0.1', IpVersion::V4);
```

Reading is strict: `01.2.3.4`, `1.2.3` and `" 1.2.3.4"` are refused rather than
guessed at, because libraries guess differently and an address two layers read
differently is how an allowlist is bypassed. A bracketed IPv6 address
(`[::1]`) and a zone id (`fe80::1%eth0`, dropped) are accepted.

| Method | Answer |
|---|---|
| `version()`, `isV4()`, `isV6()` | the family |
| `isPublic()` | routable on the internet: none of the kinds below |
| `isPrivate()` | 10/8, 172.16/12, 192.168/16, fc00::/7 |
| `isLoopback()` | 127/8, ::1 |
| `isLinkLocal()` | 169.254/16, fe80::/10 |
| `isMulticast()` | 224/4, ff00::/8 |
| `isReserved()` | unspecified, "this network", carrier-grade NAT (100.64/10), documentation (192.0.2/24, 198.51.100/24, 203.0.113/24, 2001:db8::/32, 3fff::/20), benchmarking, 240/4 and broadcast |
| `isV4Mapped()`, `toV4()`, `toV6Mapped()` | `::ffff:10.0.0.1` ⇄ `10.0.0.1`; a mapped address is classified as the IPv4 address it carries |
| `toString()` | canonical: dotted quad, or RFC 5952 compressed IPv6 |
| `toExpanded()` | `2001:0db8:0000:0000:0000:0000:0000:0001` |
| `toBinary()`, `fromBinary()` | the packed 4 or 16 bytes |
| `toLong()`, `fromLong()` | IPv4 as an integer, as `ip2long()` |
| `toReversePointer()` | `1.2.0.192.in-addr.arpa`, or the nibbles under `ip6.arpa` |
| `mask($prefix)` | host bits zeroed |
| `anonymize($v4 = 24, $v6 = 48)` | the same, with the usual privacy prefixes |
| `next()`, `previous()`, `add($offset)` | arithmetic across byte boundaries, in both families; never wraps |
| `compare()`, `equals()` | ordering (IPv4 before IPv6) and equality |

## Blocks: `Cidr`

```php
use App\Engine\Network\Cidr;

$net = Cidr::parse('192.168.1.0/24');   // host bits forgiven: 192.168.1.77/24 is the same block
Cidr::fromAddressAndMask('10.1.2.3', '255.255.0.0');   // 10.1.0.0/16
Cidr::fromAddress('2001:db8::1', 48);                  // 2001:db8::/48
```

A bare address is a block of one: `/32` or `/128`.

| Method | For `192.168.1.0/24` |
|---|---|
| `network()`, `first()` | `192.168.1.0` |
| `last()`, `broadcast()` | `192.168.1.255` (`broadcast()` is null for IPv6) |
| `firstHost()`, `lastHost()` | `192.168.1.1`, `192.168.1.254` — IPv4 /31 and /32, and all of IPv6, skip nothing |
| `netmask()`, `wildcard()` | `255.255.255.0`, `0.0.0.255` |
| `prefix()`, `version()` | `24`, `IpVersion::V4` |
| `size()`, `hostCount()` | `"256"`, `"254"` — decimal strings, since `::/0` holds 2¹²⁸ |
| `contains($ip or $cidr)` | membership; `::ffff:192.168.1.5` is inside |
| `overlaps($cidr)`, `equals($cidr)` | |

### Listing the addresses in a block

```php
foreach (Cidr::parse('10.0.0.0/16')->addresses() as $address) { … }   // lazy: any size
Cidr::parse('10.0.0.0/29')->toArray();                                 // list<string>
Cidr::parse('10.0.0.0/29')->toArray(hostsOnly: true);
```

`toArray()` refuses a block of more than 65 536 addresses (`limit:` changes
that) because the mistake is easy: a /64, the smallest ordinary IPv6 subnet,
holds 18 quintillion. `addresses()` builds nothing until it is asked.

### Splitting and summarizing

```php
Cidr::parse('10.0.0.0/24')->subnets(26);          // 10.0.0.0/26, 10.0.0.64/26, 10.0.0.128/26, 10.0.0.192/26 (a generator)
Cidr::fromRange('10.0.0.0', '10.0.0.10');         // [10.0.0.0/29, 10.0.0.8/31, 10.0.0.10/32]
```

## Masks: `Netmask`

```php
use App\Engine\Network\Netmask;

Netmask::fromPrefix(20);                  // 255.255.240.0
Netmask::fromPrefix(64, IpVersion::V6);   // ffff:ffff:ffff:ffff::
Netmask::toPrefix('255.255.240.0');       // 20; throws for 255.0.255.0
Netmask::wildcard(24);                    // 0.0.0.255
Netmask::isValid('255.255.255.128');      // true
```

## Allowlists and blocklists: `IpSet`

```php
use App\Engine\Network\IpSet;

$office = new IpSet(['203.0.113.0/24', '198.51.100.7', '2001:db8:abcd::/48']);

if (!$office->contains($request->ip())) {
    throw new HttpException(403);
}
```

Entries are read when the set is built, so a typo throws there instead of
quietly matching nothing on every request. `IpSet::lenient()` drops what it
cannot read instead, for input that has already been checked elsewhere.
`contains(null)` is `false`, so a request with no address matches nothing.

## If it doesn't work

| Symptom | Cause | Fix |
|---|---|---|
| Every visitor has the same address | The app sees the proxy, which is not trusted | Add it, or its block, to `http.trusted_proxies` |
| `ip()` returns an address the client made up | `trusted_proxies` includes something the client controls, or `0.0.0.0/0` | List only the proxies in front of the application; run `security:check` |
| A trusted proxy is ignored | Its entry cannot be read | `security:check` names it |
| `toArray()` throws | The block is larger than the limit | Iterate `addresses()`, or pass `limit:` |
