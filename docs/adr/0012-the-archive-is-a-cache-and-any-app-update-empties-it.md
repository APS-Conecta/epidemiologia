# The ISP archive is a cache, and any app update on the instance empties it

**Status: accepted, 2026-08-06.** Recorded because the trigger is not the one the code assumed,
nothing in the app could have shown it, and the alternative is a bigger change than the problem.

## The measurement

Two prefixes held 24 category keys each on the running instance, one of them orphaned with a
29,9-day TTL and 308 items nobody would read again:

```
26447bb2c2187a1409288eb6e387aad2/epidemiologiafeed-isp-alerts-…   ← orphaned
5ec6f7020672f1cc68336eb956bb6a35/epidemiologiafeed-isp-alerts-…   ← live
```

`Memcache\Factory::getGlobalPrefix()` (NC34, `lib/private/Memcache/Factory.php:115-135`):

```php
$versions = $appConfig->getAppInstalledVersions(true);   // every ENABLED app
$installedApps = implode(',', array_keys($versions)) . implode(',', array_values($versions));
$this->globalPrefix = $customprefix . hash('xxh128', $instanceid . $installedApps);
```

**Every enabled app's name and version is in the distributed cache's namespace.** So the archive is
abandoned by a release of this app — it happened twice on 2026-08-05, at 0.5.0 and 0.6.0 — and by
any other app on the instance being installed, enabled, disabled or updated.

`backfill()`'s docblock says a bookmark must not outlive the book. That holds: the cursor lives in
the entry, so both go together. The assumption it did not state is that the trigger would be rare.

## Decision

**The archive stays in the distributed cache, and the walk is what repairs it.**

A refresh that finds nothing logs one `warning` line, because the loss was otherwise silent: the
pane understates the archive and the Año menu offers fewer years, with nothing anywhere saying why.

## What was rejected, and what it would have cost

**`memcache_customprefix` does not help.** It is *prepended* to the hash, which still varies —
`$customprefix . hash(...)`.

**`withServerVersionPrefix()` is version-stable but marked "should only be used internally for
bootstrapping purpose".** Reaching for it means depending on private behaviour to hold the app's
central data.

**Moving the archive to `IAppData`** would make it durable across both app updates and Redis
flushes. It also turns a read-through cache into a store: 24 file reads on the hot path, against a
warm page measured at 0,121 s today; serialisation; and its own corruption, eviction and repair
questions. `README.md` describes this app as "a read-through proxy with a cache, and no database",
and that is a property worth more than the three days of walking it would save.

**A faster rebuild** was rejected on #45: 255 requests got a machine TLS-blocked by the ISP, and two
pages an hour is the rate that block bought.

## The cost, stated plainly

After any app update the ISP pane holds what 24 feed windows carry — about 168 alerts — instead of
the full archive, and reaches ~1.423 again after roughly **three days** of walking. ADR-0009's
~126 KB gzipped projection is the correct *upper bound*; it is not the everyday state.

## Reopen when

- **an instance updates apps more often than the walk completes** — roughly, more than once every
  three days — because then the archive never converges and the feature does not work at all;
- **Nextcloud offers a distributed cache whose namespace is not keyed by app versions**, which makes
  this a one-line change instead of a store;
- **the ISP publishes a bulk endpoint**, so a rebuild is one request rather than 144.
