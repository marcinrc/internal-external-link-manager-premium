=== Internal & External Link Manager - Premium (BeeClear) ===
Contributors: beeclear
Tags: internal links, external links, autolinks, seo, keywords, link manager, content, editor
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.7.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn keywords into smart, automatic internal and external links with fine‑grained control and quick rebuild tools.

== Description ==

**Internal & External Link Manager - Premium (BeeClear)** helps you build and maintain a healthy internal linking structure and optionally insert controlled external links automatically.

Main features:

* **Keyword-based auto-linking** – map words/phrases to internal destinations and insert links automatically.
* **External link rules** – define keyword → URL rules for external links (useful for partners, references, affiliates, etc.).
* **Smart limits & counters** – cap how many links can be added per post / per keyword to prevent over-linking.
* **Post type support** – enable/disable processing per post type.
* **Fast maintenance** – one-click **Rebuild index** and summary after rebuilding.
* **Works with modern WordPress** – follows WP sanitization/escaping conventions and stores settings in options.

Notes:

* This is a **premium** plugin. It may not be intended for publishing in the wordpress.org repository.
* Update the “Tested up to” value above to the latest WordPress version you’ve verified in your environment.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to the plugin settings page in the WordPress admin panel.
4. Configure internal rules and (optionally) external link rules.
5. Use **Rebuild index** after major rule changes (or when prompted).

== Frequently Asked Questions ==

= Does it change my content permanently? =
Typically the plugin inserts links at render time (front-end output). If your configuration includes rebuilding/indexing, it maintains an index in options. Your original post content should remain unchanged unless you use a feature explicitly designed to write back to content.

= Can I limit how many links are inserted? =
Yes. Use per-keyword and per-post limits (where available in the settings) to prevent over-linking.

= Which post types are supported? =
You can enable the plugin for selected post types (e.g., posts, pages, custom post types) from the settings.

= Will it link inside headings, shortcodes, or code blocks? =
Behavior depends on your settings and how the plugin matches allowed HTML tags. If you see unwanted links, adjust the allowed tags / exclusions in plugin settings.

== Screenshots ==

1. Settings page – general options and rebuild tools.
2. Internal rules – manage keyword → internal destination rules.
3. External rules – manage keyword → external URL rules.
4. Summary – rebuild/index summary after maintenance.

== Changelog ==

= 1.7.5 =
* Current release (see plugin package for full change history).

== Upgrade Notice ==

= 1.7.5 =
No special upgrade steps required. After updating, run **Rebuild index** if you changed rules or notice missing links.
