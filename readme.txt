=== Darven - Who Published ===
Contributors: fkdarven
Tags: authorship, meta, post author, publisher, editorial
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preserves and displays the original user who published a post, even after edits or updates.

== Description ==

Darven - Who Published ensures editorial integrity by saving and displaying the original author who published a WordPress post or page. Even if a post is edited later by other users, the plugin preserves the original publisher information and displays it in the admin interface.

Features include:

1. A new column "Who Published" in post and page listings.
2. A visual badge system to highlight confirmed and guessed authors.
3. A metabox in the post editor showing the original author.
4. Admin filtering by original publisher.
5. REST API compatibility.
6. Translations included: pt_BR, pt_PT, es_ES.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/darven-who-published`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. The "Who Published" column and metabox will appear automatically.

== Frequently Asked Questions ==

= What happens if the author can't be determined? =
A fallback system tries to guess using the last editor or revision author.

= Can I disable the guessing logic? =
Not yet, but a filter hook will be available in a future version.

== Screenshots ==

1. "Who Published" column with badge indicators.
2. Metabox showing original publisher.
3. Admin post list filter for original author.

== Changelog ==

= 1.0.0 =
* Initial stable release.
* Includes metabox, column, author filter and fallback logic.
* Supports translation and REST API.

== Upgrade Notice ==

= 1.0.0 =
First stable release. Recommended for editorial teams and multi-author blogs.

