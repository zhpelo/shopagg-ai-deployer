=== SHOPAGG AI Deployer ===
Contributors: zhpelo
Tags: ai, deployment, backup, recovery, developer-tools
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure AI-assisted WordPress development with authenticated APIs, automatic backups, health checks, audit history, and an independent recovery channel.

== Description ==

SHOPAGG AI Deployer provides an authenticated REST API for reading and deploying plugin or theme code, managing backups, checking site health, and recovering a broken WordPress site through a standalone endpoint.

Main features:

* Authenticated REST API
* Atomic file deployment
* Automatic snapshots before changes
* Health checks and automatic rollback
* Safe single-plugin and bulk plugin updates
* GitHub-backed updates for SHOPAGG AI Deployer
* Plugin and theme management
* Post management
* Cache clearing
* Activity audit history
* Standalone emergency recovery
* Built-in AI prompt document

== Installation ==

1. Upload the `shopagg-ai-deployer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Open AI Deployer in the WordPress admin.
4. Copy the generated AI prompt and keep the embedded API Key private.

== Security ==

Never commit `includes/config.php` or the `backups` directory. Regenerate the API Key immediately if it is exposed.

== Changelog ==

= 1.3.0 =

* Added REST endpoints to check, update, and bulk-update installed plugins.
* Added full plugin snapshots, compatibility checks, health validation, and automatic rollback around plugin updates.
* Added GitHub-backed update metadata for SHOPAGG AI Deployer with commit-pinned packages.
* Expanded the built-in AI prompt with explicit plugin update safety rules and examples.

= 1.2.1 =

* Renamed the REST namespace to `shopagg-ai-deployer/v1`.
* Renamed the authentication header to `X-ShopAgg-AI-Deployer-Key`.
* Updated the admin UI and generated AI prompt.

= 1.2.0 =

* Added stricter path and sensitive-file protection.
* Added atomic writes, unique snapshots, deployment rollback, audit history, status API, plugin deletion, theme activation, and expanded post management.
* Prevented authenticated REST responses from being cached.

= 1.1.0 =

* Added REST deployment, backups, health checks, and Standalone recovery.
