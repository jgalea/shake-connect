=== Shake Connect ===
Contributors: jeangalea
Tags: backup, manage multiple sites, updates, monitoring, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Securely connects this WordPress site to your WPShake dashboard for cross-site backup, update, monitoring, security, and reporting.

== Description ==

Shake Connect is the site-side companion to the WPShake dashboard at [wpshake.com](https://wpshake.com). Install it on each WordPress site you want to monitor, then add the site to your WPShake account using the connection token shown on the plugin's settings page. The dashboard then runs daily checks across every connected site without you logging into each one.

= What Shake Connect adds to this site =

* A bearer-token-authenticated REST API under `/wp-json/wpshake/v1/*` that the WPShake dashboard calls to read site state.
* Endpoints for: plugin and theme inventory with available updates, core info, WP checksums, database health, recent WooCommerce orders if WooCommerce is installed, recent PHP errors from `WP_DEBUG_LOG`, backup-plugin detection (UpdraftPlus, BlogVault, BackWPup, Solid Backups, WPvivid, Duplicator), activity log integration (reads the WP Activity Log plugin's table when present), a built-in malware signature scanner, and a broken-link checker.
* Maintenance endpoints scoped to safe operations: cache flush across popular caching plugins, run-due WP-Cron, expired-transient cleanup, permalinks flush.
* A WP-CLI subcommand `wp wpshake` for token rotation and status checks.

= What Shake Connect does NOT do =

* It does not auto-update plugins, themes, or WordPress core. Update commands are issued from the dashboard side as separate releases of this connector. This release is read-only plus safe maintenance only.
* It does not collect personal information or analytics. The dashboard reads your site's existing data on demand.
* It does not communicate with any third party except the WPShake dashboard configured on the plugin's settings page, and only when the dashboard calls this site's REST endpoints with a valid bearer token.

= How the connection works =

1. Install and activate Shake Connect.
2. Open Settings → Shake Connect in the WordPress admin to generate a connection token. The token is shown once.
3. Sign up at [wpshake.com](https://wpshake.com), click "Add a site", paste the token and your site URL.
4. The dashboard performs an initial handshake and pulls site state.

The token is stored as a SHA-256 hash on this site. The dashboard sends the plain token in an `Authorization: Bearer` header on every request. Every authenticated endpoint validates the header before doing anything.

= Built for agencies =

If you maintain more than a handful of WordPress sites, you already know the work. Shake Connect is the receiver for an agent that handles the daily checks across your fleet: backup verification, plugin and theme update visibility, vulnerability scanning, performance probes, PHP error monitoring, broken link checks, and per-site recommendations on what to update and what to hold.

The WPShake dashboard is a separate paid service. This connector plugin is free and open source under GPL.

= Multisite =

Shake Connect supports WordPress multisite. Network-activate the plugin and the connection token is stored at the network level so every subsite shares the same credentials. Enumerate subsites via `/network/sites` and pass `?blog_id=N` to per-site read endpoints (for example `/site/info?blog_id=3` or `/plugins?blog_id=3`) to inspect each subsite. Without `blog_id`, requests run against the main site. Enumeration is capped at 500 subsites per call.

== Installation ==

1. Upload the `shake-connect` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Settings → Shake Connect to generate a connection token.
4. Sign up at [wpshake.com](https://wpshake.com) (free trial available) and add the site using the token.

== Frequently Asked Questions ==

= Is this plugin free? =

Yes, this connector plugin is free and GPL-licensed. The WPShake dashboard is a paid service with a free trial. You can use the connector with any WPShake plan.

= Does Shake Connect send my data anywhere on its own? =

No. The plugin only responds to requests from the WPShake dashboard that include a valid bearer token. It does not initiate any outbound requests on a schedule.

= What happens if I deactivate the plugin? =

The site stops responding to dashboard requests immediately. The connection token remains stored as a hash so that re-activating the plugin restores the connection. Deleting the plugin removes the stored token hash.

= Can I rotate the connection token? =

Yes. Either click "Reset token" on the plugin's settings page, or run `wp wpshake reset-token` from WP-CLI. The previous token is invalidated immediately.

= Which backup plugins does it detect? =

UpdraftPlus, BlogVault, BackWPup, Solid Backups (formerly BackupBuddy), WPvivid, and Duplicator. For each, it surfaces the last backup time and status when the backup plugin exposes them.

= Does it require WooCommerce? =

No. The WooCommerce-related endpoint returns `wc_active: false` and an empty list when WooCommerce is not installed. The rest of the plugin works the same on any WordPress site.

= Is there a WP-CLI command? =

Yes. `wp wpshake` exposes `status`, `reset-token`, and `revoke` subcommands for ops scripts.

== Screenshots ==

1. The plugin's settings page in the WordPress admin where you generate a connection token.

== Changelog ==

= 0.5.1 =
* Removed two unnecessary core-file `require_once` calls in the REST controller (`wp-admin/includes/file.php` and `wp-admin/includes/cron.php`) — the functions used were already available without them.

= 0.5.0 =
* Multisite support: `Network: true` header, network-level token storage, new `/network/sites` endpoint for subsite enumeration (capped at 500), and `?blog_id=N` parameter on read endpoints to query individual subsites.

= 0.1.0 =
* Initial release on WordPress.org.
* Read endpoints: plugins, themes, core/info, checksums, db/health, orders/recent, php-errors/recent, php-errors/stats, backups/state, activity-log/source, activity-log/recent, security/malware-scan, links/broken.
* Maintenance endpoints: cache/flush, cron/run-due, transients/delete-expired, permalinks/flush.
* WP-CLI command `wp wpshake` for token management.

== Upgrade Notice ==

= 0.5.0 =
Adds WordPress multisite support: network activation, network-level token, subsite enumeration via /network/sites, and per-subsite queries via ?blog_id=N.

= 0.1.0 =
First public release.
