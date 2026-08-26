=== Who Published – Post Publisher Column ===
Contributors: fkdarven
Tags: publisher, editorial workflow, audit trail, multi author, post author
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows who actually clicked Publish beside the credited post author—without opening a separate activity log.

== Description ==

The author wrote it. The publisher sent it live.

Who Published records the user who first publishes each post or page and displays that person directly beside the native Author column.

In an editorial workflow, the person credited as Author is not always the person who sent a post live. Who Published makes that narrow, useful distinction visible in the WordPress admin without turning your site into a full activity log. It records the first authenticated publisher and preserves that confirmed record through later edits and republishes.

= Clear publisher states =

* Confirmed: the authenticated user who first published the post or page. This value is immutable once recorded.
* Estimated: an explicitly marked historical estimate, based on legacy Who Published data, the last editor, the latest revision, or the credited post author. An estimate is never presented as proof.
* Unknown: there is no confirmed publisher, or historical estimation is disabled.

Historical estimation is off by default. WordPress cannot prove who published content created before plugin activation, so enabling estimation only permits clearly labelled estimates. Turning it off hides estimates without deleting confirmed publisher data.

= Focused editorial accountability =

* Shows a Published by column immediately after Author on Posts and Pages.
* Lets you filter list tables by confirmed publisher; when historical estimation is enabled, estimated publishers are included as clearly identified matches.
* Adds a Published by metabox in the editor with the same confirmed, estimated, or unknown state.
* Captures first publication in wp-admin, REST API, and supported programmatic publication flows.
* Keeps compatibility with existing confirmed metadata and reads legacy 1.0.0 guessed values only as optional estimates.

Who Published is not a full activity log. It does not record every change, create audit-event timelines, or display publishers on the public site. It answers one focused editorial question: who actually clicked Publish?

== Installation ==

1. Upload the `darven-who-published` folder to `/wp-content/plugins/`, or install the plugin from the WordPress Plugins screen.
2. Activate Who Published through the Plugins screen in WordPress.
3. Open Posts or Pages to find Published by directly after Author. Optionally enable historical estimation in Settings → Who Published.

== Frequently Asked Questions ==

= Does this change the credited post author? =

No. WordPress keeps the credited Author unchanged. Who Published stores and shows the separate user who first published the post or page.

= Why is a publisher estimated or unknown? =

Posts published after activation receive a confirmed publisher when an authenticated user first publishes them. Older content cannot be proven. Historical estimation is disabled by default; when enabled, estimates are visibly labelled with their evidence source. Otherwise, the state is Unknown.

= Does it support the REST API? =

Yes. First-publication capture works for supported wp-admin, REST API, and programmatic publication paths. Confirmed publisher metadata is available through REST to users who can edit the relevant content.

= Is this an activity log? =

No. This plugin deliberately does not maintain a general activity log or a record of every edit. It preserves the first publisher for a focused editorial-accountability view.

== Screenshots ==

1. Posts table: João is the credited Author and Maria is the confirmed Published by user in the adjacent column.
2. Settings → Who Published: historical publisher estimation is disabled by default, with the integrity explanation visible.
3. Editor metabox: Maria is shown as the confirmed publisher.

== Changelog ==

= 1.1.0 =
* Records the first authenticated publisher for posts and pages across wp-admin, REST API, and supported programmatic publication contexts, and keeps confirmed records immutable.
* Registers publisher metadata for both posts and pages, with safe REST visibility for confirmed publisher data.
* Adds explicit confirmed, estimated, and unknown publisher states. Historical estimation is opt-in, disabled by default, and never replaces confirmed data.
* Preserves migration compatibility: existing confirmed metadata remains authoritative, while legacy 1.0.0 guessed IDs are read only as optional historical estimates.
* Adds a Published by column directly beside Author, list filtering, an editor metabox, and Settings → Who Published.
* Renders publisher badges, links, filter values, settings controls, and metabox markup safely.
* Updates compatibility declarations for WordPress 5.6 through 7.1 and PHP 8.0 or later.
* Refreshes the directory identity, listing copy, screenshots, and versioned admin assets for the editorial-team relaunch.

= 1.0.0 =
* Initial stable release.

== Upgrade Notice ==

= 1.1.0 =
The relaunch preserves confirmed publisher records, adds support for pages and modern publication contexts, and keeps unproven historical data clearly optional.
