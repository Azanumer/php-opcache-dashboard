# OPcache Dashboard

A single-file OPcache status page for PHP servers — drop it in your web root, open it in a browser, and see memory usage, hit rate, cached scripts and restart counters at a glance. No framework, no database, no install.

## Features

- **Status cards** — hit rate, memory used / free / wasted, cached scripts and keys, interned strings, manual/OOM/hash restart counts.
- **Memory bar** — used vs wasted memory at a glance, with a warning when the cache is full or a restart is pending.
- **Cached-scripts table** — every cached script with hits, memory and last-used time; filter by path, sort by hits / memory / path.
- **Token-protected actions** — reset the whole cache or invalidate a single script, only with a `?token=` that matches the constant in the file.
- **JSON mode** — append `?format=json` for a machine-readable status summary (handy for monitoring scripts); the same IP allow-list applies.
- **Safe by default** — only localhost IPs may open the page, and write actions are disabled entirely until you set a token.

## Usage

1. Copy `opcache-dashboard.php` to your web root (e.g. `/opcache-dashboard.php`).
2. Edit the two constants at the top of the file:
   - `OPCACHE_DASHBOARD_ALLOWED_IPS` — the IPs allowed to open the page.
   - `OPCACHE_DASHBOARD_TOKEN` — a long random string to enable Reset / Invalidate (leave empty to disable them).
3. Open `https://your-server/opcache-dashboard.php` in a browser.
4. Delete the file (or move it out of the web root) when you no longer need it.

## Requirements

PHP 7.4+ with the Zend OPcache extension loaded and enabled (`opcache.enable=1`).

## License

MIT — see [LICENSE](LICENSE).
