# Who Published – Post Publisher Column

Canonical source for the WordPress plugin that records the authenticated user who first publishes a post or page, separately from the credited WordPress author. GitHub is the source of truth for development, pull requests, tests, tags, and release artifacts. The WordPress.org SVN repository is deployment-only and must receive files built from a reviewed GitHub release.

## Install and develop

Requirements: PHP 8.0 or later, Composer, and a WordPress test database. Install development dependencies with:

```bash
composer install --no-interaction --prefer-dist
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 7.1
```

Run the suite and coding-standard checks with:

```bash
composer test
composer phpcs
```

Activate the plugin from the `darven-who-published` directory in a WordPress installation. It supports WordPress 5.6 or later and PHP 8.0 or later.

## Publisher data model

`_darven_who_published_author` is the authoritative confirmed publisher ID. It is written once when an authenticated user first publishes a post or page and is never overwritten.

Historical data remains separate from confirmation. Estimation is disabled by default through `darven_who_published_enable_estimation`. When enabled, the plugin may read legacy `_darven_who_published_author_was_guessed` data or persist an estimate in `_darven_who_published_estimated_author` together with its source in `_darven_who_published_estimation_source`. Estimated data must never be copied into the confirmed key.

`PublisherRetriever` returns a `PublisherIdentity` in one of three states: `confirmed`, `estimated`, or `unknown`. The admin column and editor metabox render those states distinctly.

## Extension points

* `darven_who_published_estimation_enabled` filters whether optional historical estimation is active.
* `darven_who_published_estimated_publisher` filters or suppresses an estimated publisher ID. It receives the user ID, evidence source, and post object.

Neither filter can replace confirmed publisher metadata.

## Contributing

Keep the plugin focused on first-publication accountability: it is not a general activity log, front-end display system, analytics product, or custom-post-type framework. Add or update integration coverage for behavioural changes, keep user-facing strings in the `darven-who-published` text domain, regenerate the POT and translated MO files, and run `composer test` before proposing a change. Do not publish to WordPress.org SVN outside the reviewed-release process.
