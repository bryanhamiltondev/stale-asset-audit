# stale-asset-audit

Find pages serving a cached asset under an **old version key**.

Multi-page sites version CSS/JS with `?v=N` cache busters. Bump passes tend to be
page-local: the page you edited gets `?v=48`, the other 83 pages keep `?v=33`.
Every stale page then loads the *current* asset file - but returning browsers hold
their cached copy under the old key, so your deployed code never actually runs there.
No error, no warning. Just old JavaScript, confidently.

This was found in production the hard way: one site, **84 pages drifted** across
three months of page-local bump passes.

## Usage

```bash
php audit.php /var/www/site              # scan a directory tree
php audit.php . --exclude=fixt,backups   # skip directories
php audit.php . --json                   # machine-readable report
```

Exit codes: `0` no drift, `1` drift found (CI-friendly), `2` usage error.

## Sample output

```
stale-asset-audit: 2 local assets checked, 1 with drift:

  js/rack.js
    versions seen: 33, 45, 47 (latest: 47)
    STALE 33: blog/post.html
    STALE 45: blog/current.html
    STALE : bare.html
```

## What it checks

- `<script src="...">` and `<link ... href="...">` refs to **local** `.js`/`.css` files
- any `?v=` (or `&v=`) token, plus versionless (bare) refs - a bare ref mixed with
  keyed refs is drift too
- remote/protocol-relative refs are skipped

## What it does not do

- No fetching, no crawling, no network. It reads the file tree you point it at.
- It does not bump versions for you - it tells you exactly which pages lie.

## Requirements

PHP 7.2+, zero dependencies, one file. Runs fine on shared cPanel hosting.

## Provenance

Extracted from a production incident on a ~300-page PHP portfolio site
(Nov 2026). The audit that found the drift is documented in the site's
[deploy log](https://github.com/bryanhamiltondev/deploy-log).

MIT license.
