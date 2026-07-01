<div align="center">

# Shake Connect

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/shake-connect?style=for-the-badge&label=WordPress.org&color=21759B)](https://wordpress.org/plugins/shake-connect/)
[![License](https://img.shields.io/badge/LICENSE-GPLv2%2B-5C9E31?style=for-the-badge)](LICENSE)
[![Built by](https://img.shields.io/badge/BUILT%20BY-REBELCODE-8A2BE2?style=for-the-badge)](https://rebelcode.com)

**The site-side companion for the WPShake dashboard. Install it, connect the site, and let the dashboard run daily checks across your fleet without logging into each one.**

</div>

## What it does

Shake Connect exposes a bearer-token REST API under `/wp-json/wpshake/v1/*` that the [WPShake](https://wpshake.com) dashboard calls to read site state and run safe maintenance. It never phones home: the site only responds to authenticated dashboard requests, and only returns the data each endpoint is asked for.

Read endpoints cover plugin/theme/core inventory with available updates, WP checksums, database health, recent WooCommerce orders, recent PHP errors from the debug log, backup-plugin detection, activity-log integration, a malware signature scanner, and a broken-link checker. Maintenance endpoints are scoped to safe operations only: cache flush, run-due cron, expired-transient cleanup, and permalink flush. A `wp wpshake` WP-CLI subcommand handles token rotation and status.

It does not auto-update anything, and it collects no analytics.

## Connecting a site

1. Install and activate the plugin.
2. Open Settings → Shake Connect and generate a connection token (shown once).
3. At [wpshake.com](https://wpshake.com), click "Add a site", then paste the token and site URL.

The token is stored as a SHA-256 hash on the site. The dashboard sends the plain token in an `Authorization: Bearer` header on every request, and each authenticated endpoint validates it before doing anything. Multisite is supported: network-activate, and pass `?blog_id=N` to per-site read endpoints.

## Releasing

Tag a version to ship it to WordPress.org automatically:

```bash
# bump Version + Stable tag first, then:
git tag v0.5.2 && git push origin v0.5.2
```

The `Deploy to WordPress.org` workflow publishes that tag to SVN trunk and `tags/`. Pushes to `main` that touch `readme.txt` or `.wordpress-org/` sync the readme and store assets to trunk between releases. Both need the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets.

## License

GPLv2 or later. The WPShake dashboard is a separate paid service; this connector is free and open source.
