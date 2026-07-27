=== SHOPAGG AI Deployer ===
Contributors: zhpelo
Tags: ai, abilities, mcp, deployment, backup, recovery
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-key, full-control WordPress access for AI through Abilities, REST, transactional deployment, and independent recovery.

== Description ==

SHOPAGG AI Deployer gives an AI Agent complete administrator control of WordPress. It registers machine-discoverable Abilities for code inspection, transactional code deployment, any registered WordPress REST resource, extension management, health verification, backups, and recovery.

Activation automatically creates one master key. The AI Connection page generates a ready-to-copy prompt containing the site URLs and the key. No separate WordPress user, Application Password, role, capability assignment, or AI service account is required.

The same X-ShopAgg-AI-Deployer-Key header works for Abilities, the complete plugin REST API, and the standalone recovery endpoint.

== Installation ==

1. Upload and activate the plugin.
2. Open AI Deployer > AI Connection.
3. Click Copy Prompt.
4. Send the prompt to an AI client that can make HTTP/API requests.

== Changelog ==

= 2.0.0 =

* Rebuilt the AI interface on the WordPress 6.9 Abilities API.
* Added one automatically generated full-control key embedded in the connection prompt.
* Removed separate AI users, Application Passwords, custom roles, scoped capabilities, legacy credentials, and a separate recovery credential.
* Added automatic administrator identity for all master-key requests.
* Added code tree, search, batched reads, and compact hashes.
* Added deployment preview, PHP validation, locks, idempotency, SHA-256 preconditions, full backup, health verification, and rollback.
* Added administrator access to any registered WordPress REST route.
* Moved runtime credentials, backups, operation results, and audit logs outside the plugin directory.
* Unified the standalone recovery endpoint under the same master key.

= 1.3.0 =

* Added safe single and bulk plugin updates with backups and rollback.
