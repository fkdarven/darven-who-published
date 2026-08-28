# Who Published 1.1 Relaunch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Relaunch the existing WordPress.org plugin as Who Published – Post Publisher Column 1.1.0 with correct publisher tracking, opt-in historical estimation, a tested canonical GitHub repository, new WordPress-native assets, and a verified WordPress.org release.

**Architecture:** GitHub becomes the canonical source and WordPress.org SVN becomes deployment-only. Core tracking boots in every request context while admin components boot only in `wp-admin`; confirmed, estimated, and unknown publisher states are represented explicitly and never conflated. A test-first implementation and reproducible ZIP build precede both GitHub and SVN publication.

**Tech Stack:** PHP 8.0+, WordPress 5.6–7.1, Composer, PHPUnit with the WordPress integration test suite, WordPress Coding Standards, WordPress Plugin Check, WP-CLI, GitHub Actions, GitHub CLI, WordPress.org SVN, image generation for directory identity assets, and browser-driven WordPress screenshots.

**Spec:** `docs/superpowers/specs/2026-08-26-who-published-1-1-relaunch-design.md`

## Global Constraints

- Public canonical repository: `https://github.com/fkdarven/darven-who-published`.
- Working directory: `/home/darven/Documentos/pessoal/projects/darven-who-published`.
- Development branch: `codex/1.1.0-relaunch`.
- WordPress.org slug and text domain remain `darven-who-published`.
- Release version and stable tag are `1.1.0`.
- WordPress minimum remains 5.6; `Tested up to` is 7.1.
- PHP minimum remains 8.0.
- Confirmed metadata key remains `_darven_who_published_author` and is immutable.
- Historical estimation is disabled by default and never writes the confirmed key.
- The display name is `Who Published – Post Publisher Column`.
- The short description is `Shows who actually clicked Publish beside the credited post author—without opening a separate activity log.`
- The plugin remains limited to posts and pages; it does not become a general activity log.
- WordPress.org publication is a human-gated external action.

---

### Task 1: Import 1.0.0 and establish the canonical GitHub repository

**Files:**
- Import: all production files from the current WordPress.org 1.0.0 ZIP
- Create: `docs/superpowers/specs/2026-08-26-who-published-1-1-relaunch-design.md`
- Create: `docs/superpowers/plans/2026-08-26-who-published-1-1-relaunch.md`
- Create: `.gitignore`

**Interfaces:**
- Consumes: WordPress.org slug `darven-who-published` and the approved design/plan in the planning workspace.
- Produces: public GitHub repository, immutable imported baseline tag `wordpress-org-1.0.0`, and branch `codex/1.1.0-relaunch`.

- [ ] **Step 1: Download and inspect the public 1.0.0 artifact**

Run:

```bash
release_tmp_dir=$(mktemp -d /tmp/who-published-import-XXXXXX)
curl --fail --location --output "$release_tmp_dir/darven-who-published.1.0.0.zip" https://downloads.wordpress.org/plugin/darven-who-published.1.0.0.zip
sha256sum "$release_tmp_dir/darven-who-published.1.0.0.zip"
unzip -q "$release_tmp_dir/darven-who-published.1.0.0.zip" -d "$release_tmp_dir/unpacked"
find "$release_tmp_dir/unpacked/darven-who-published" -type f -printf '%P\n' | sort
```

Expected: the package contains `darven-who-published.php`, `readme.txt`, `src/`, `assets/css/admin.css`, `languages/`, Composer autoload files, and no unexpected executable payload.

- [ ] **Step 2: Create the local source repository from the inspected artifact**

Run with approval to write the personal projects directory:

```bash
test ! -e /home/darven/Documentos/pessoal/projects/darven-who-published
mkdir -p /home/darven/Documentos/pessoal/projects/darven-who-published
cp -a "$release_tmp_dir/unpacked/darven-who-published/." /home/darven/Documentos/pessoal/projects/darven-who-published/
cd /home/darven/Documentos/pessoal/projects/darven-who-published
git init -b main
```

Use `apply_patch` to create:

```gitignore
/build/
/dist/
/.phpunit.result.cache
/.wp-env.json
/node_modules/
/vendor/
```

Keep the imported `vendor/` only in the baseline commit so the GitHub snapshot matches the published 1.0.0 artifact; subsequent source commits remove generated vendor files and the release build restores them.

- [ ] **Step 3: Commit and tag the exact imported baseline**

Run:

```bash
git add --all
git diff --cached --check
git commit -m "chore: import WordPress.org 1.0.0"
git tag -a wordpress-org-1.0.0 -m "Exact source imported from WordPress.org 1.0.0"
```

Expected: one root commit authored as `Darven <37351336+fkdarven@users.noreply.github.com>`.

- [ ] **Step 4: Create the public GitHub repository and push the baseline**

Run only after confirming `gh auth status` uses `fkdarven`:

```bash
gh repo create fkdarven/darven-who-published --public --source=. --remote=github --description "Shows who actually clicked Publish beside the credited WordPress post author."
git push -u github main
git push github wordpress-org-1.0.0
```

Expected: `github` points to `https://github.com/fkdarven/darven-who-published.git`, and `git config user.email` resolves to the `fkdarven` noreply address through the host-conditional configuration.

- [ ] **Step 5: Create the development branch and bring the approved documents into the canonical repository**

Run:

```bash
git switch -c codex/1.1.0-relaunch
mkdir -p docs/superpowers/specs docs/superpowers/plans
cp '/home/darven/Documentos/ChatGPT/Darven - EPI/docs/superpowers/specs/2026-08-26-who-published-1-1-relaunch-design.md' docs/superpowers/specs/
cp '/home/darven/Documentos/ChatGPT/Darven - EPI/docs/superpowers/plans/2026-08-26-who-published-1-1-relaunch.md' docs/superpowers/plans/
git add docs .gitignore
git commit -m "docs: add 1.1.0 relaunch design and plan"
```

---

### Task 2: Add the WordPress integration-test and quality toolchain

**Files:**
- Modify: `composer.json`
- Modify: `.gitignore`
- Create: `phpunit.xml.dist`
- Create: `phpcs.xml.dist`
- Create: `bin/install-wp-tests.sh`
- Create: `tests/bootstrap.php`
- Create: `tests/test-plugin-loads.php`

**Interfaces:**
- Consumes: imported 1.0.0 plugin entry point and Composer autoload namespace `Darven\WhoPublished\`.
- Produces: `composer test`, `composer phpcs`, and an installed WordPress 7.1 integration-test environment.

- [ ] **Step 1: Generate the standard WordPress plugin-test scaffolding**

Run:

```bash
wp scaffold plugin-tests darven-who-published --ci=github
composer require --dev phpunit/phpunit:^9.6 yoast/phpunit-polyfills:^3.1 squizlabs/php_codesniffer:^3.13 wp-coding-standards/wpcs:^3.2 dealerdirect/phpcodesniffer-composer-installer:^1.0
```

Keep the generated `bin/install-wp-tests.sh`, `phpunit.xml.dist`, and bootstrap logic. Remove any generated CI file because Task 8 creates the reviewed workflow.

- [ ] **Step 2: Define exact Composer scripts and runtime autoloading**

Update `composer.json` to retain the PSR-4 production mapping and add:

```json
{
  "autoload": {
    "psr-4": {
      "Darven\\WhoPublished\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Darven\\WhoPublished\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit --colors=always",
    "phpcs": "phpcs",
    "phpcbf": "phpcbf"
  }
}
```

Configure `phpcs.xml.dist` for the `WordPress`, `WordPress-Docs`, and `WordPress-Extra` standards, PHP 8.0 compatibility, the text domain `darven-who-published`, and exclusions for `vendor`, `build`, and `dist`.

- [ ] **Step 3: Add a real plugin-load smoke test**

Create `tests/test-plugin-loads.php`:

```php
<?php

class Test_Plugin_Loads extends WP_UnitTestCase {
    public function test_starter_class_is_loaded(): void {
        $this->assertTrue( class_exists( \Darven\WhoPublished\Core\Starter::class ) );
    }
}
```

- [ ] **Step 4: Install WordPress 7.1 tests and verify the harness**

Run:

```bash
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 7.1
composer dump-autoload
composer test
```

Expected: the smoke test passes. If the local machine lacks MySQL, run the same commands in the generated GitHub Actions service environment before proceeding with behavior work.

- [ ] **Step 5: Commit the toolchain**

Run:

```bash
git add composer.json composer.lock phpunit.xml.dist phpcs.xml.dist bin tests .gitignore
git commit -m "test: add WordPress integration harness"
```

---

### Task 3: Fix confirmed publisher capture and metadata registration

**Files:**
- Modify: `darven-who-published.php`
- Modify: `src/Core/Starter.php`
- Modify: `src/Publisher/PublisherTracker.php`
- Modify: `src/Tracker/RegisterMeta.php`
- Create: `tests/test-publisher-tracker.php`
- Create: `tests/test-register-meta.php`

**Interfaces:**
- Consumes: `_darven_who_published_author`, current authenticated WordPress user, `transition_post_status`, REST insertion hooks, and post/page metadata APIs.
- Produces: `PublisherTracker::capture( int $post_id ): void`, idempotent all-context tracking, and registered post/page metadata.

- [ ] **Step 1: Write failing capture and immutability tests**

Create `tests/test-publisher-tracker.php` with real posts and users:

```php
<?php

class Test_Publisher_Tracker extends WP_UnitTestCase {
    public function test_first_authenticated_publisher_is_recorded_and_never_overwritten(): void {
        $author_id    = self::factory()->user->create( [ 'role' => 'author' ] );
        $publisher_id = self::factory()->user->create( [ 'role' => 'editor' ] );
        $later_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $post_id      = self::factory()->post->create( [ 'post_author' => $author_id, 'post_status' => 'draft' ] );

        wp_set_current_user( $publisher_id );
        wp_publish_post( $post_id );
        $this->assertSame( $publisher_id, (int) get_post_meta( $post_id, '_darven_who_published_author', true ) );

        wp_set_current_user( $later_id );
        wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Edited later' ] );
        $this->assertSame( $publisher_id, (int) get_post_meta( $post_id, '_darven_who_published_author', true ) );
    }

    public function test_publish_without_authenticated_user_remains_unknown(): void {
        $post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
        wp_set_current_user( 0 );
        wp_publish_post( $post_id );
        $this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
    }
}
```

Add a REST publication test that dispatches the real posts controller:

```php
public function test_rest_publish_records_the_request_user(): void {
    $publisher_id = self::factory()->user->create( [ 'role' => 'editor' ] );
    $post_id      = self::factory()->post->create( [ 'post_status' => 'draft' ] );
    wp_set_current_user( $publisher_id );

    $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
    $request->set_param( 'status', 'publish' );
    $response = rest_do_request( $request );

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( $publisher_id, (int) get_post_meta( $post_id, '_darven_who_published_author', true ) );
}
```

- [ ] **Step 2: Write failing post-and-page metadata tests**

Create `tests/test-register-meta.php` and assert that `get_registered_meta_keys( 'post', 'post' )` and `get_registered_meta_keys( 'post', 'page' )` both contain `_darven_who_published_author` with integer, single-value schemas.

- [ ] **Step 3: Run the focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/test-publisher-tracker.php tests/test-register-meta.php
```

Expected: publication capture fails because the plugin currently exits outside admin; page metadata registration fails because only `post` is registered.

- [ ] **Step 4: Implement all-context core boot and idempotent capture**

Change the plugin entry point to start core components on `plugins_loaded` without an `is_admin()` early return. In `Starter::setup()`, always register metadata and `PublisherTracker`; register `ColumnManager`, `MetaBoxDisplay`, and settings only when `is_admin()` is true.

Implement the tracker’s single write path:

```php
public function capture( int $post_id ): void {
    if ( get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) ) {
        return;
    }

    $user_id = get_current_user_id();
    if ( $user_id <= 0 ) {
        return;
    }

    add_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $user_id, true );
}
```

All publish hooks call `capture()` only when the item is entering or already confirmed as published. Use `add_post_meta( ..., true )` to make concurrent first writes atomic.

Register the confirmed integer meta schema for both `post` and `page` by iterating `[ 'post', 'page' ]`.

- [ ] **Step 5: Verify GREEN and commit**

Run:

```bash
vendor/bin/phpunit tests/test-publisher-tracker.php tests/test-register-meta.php
composer test
git add darven-who-published.php src tests
git commit -m "fix: capture the first publisher in every context"
```

---

### Task 4: Introduce explicit confirmed, estimated, and unknown states

**Files:**
- Create: `src/Publisher/PublisherIdentity.php`
- Create: `src/Publisher/PublisherEstimator.php`
- Create: `src/Settings/SettingsRepository.php`
- Modify: `src/Publisher/PublisherRetriever.php`
- Remove: `src/Publisher/PublisherGuesser.php`
- Create: `tests/test-publisher-retriever.php`
- Create: `tests/test-publisher-estimator.php`

**Interfaces:**
- Consumes: confirmed and legacy metadata, setting `darven_who_published_enable_estimation`, `_edit_last`, revisions, `post_author`, and two public filters.
- Produces: `PublisherRetriever::get_publisher( WP_Post $post ): PublisherIdentity` and immutable status values `confirmed`, `estimated`, or `unknown`.

- [ ] **Step 1: Write failing retrieval-state tests**

Create tests proving these literal outcomes:

```php
$this->assertSame( 'unknown', $retriever->get_publisher( $post )->status() );
update_option( 'darven_who_published_enable_estimation', true );
update_post_meta( $post->ID, '_edit_last', $editor_id );
$identity = $retriever->get_publisher( $post );
$this->assertSame( 'estimated', $identity->status() );
$this->assertSame( $editor_id, $identity->user_id() );
$this->assertSame( 'edit_last', $identity->source() );
```

Add tests for confirmed precedence, legacy `_darven_who_published_author_was_guessed` compatibility, persistence of new estimates, the exact source order, and filter-based suppression.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/test-publisher-retriever.php tests/test-publisher-estimator.php
```

Expected: failures because the current retriever returns an integer, guesses unconditionally, and conflates the guessed flag with a user ID.

- [ ] **Step 3: Implement `PublisherIdentity`**

Provide named factories and accessors:

```php
final class PublisherIdentity {
    public const CONFIRMED = 'confirmed';
    public const ESTIMATED = 'estimated';
    public const UNKNOWN   = 'unknown';

    private string $status;
    private int $user_id;
    private string $source;

    public static function confirmed( int $user_id ): self;
    public static function estimated( int $user_id, string $source ): self;
    public static function unknown(): self;
    public function status(): string;
    public function user_id(): int;
    public function source(): string;
}
```

Reject zero IDs from confirmed/estimated factories with `InvalidArgumentException`; unknown uses user ID `0` and an empty source.

- [ ] **Step 4: Implement settings and estimation**

`SettingsRepository::estimation_enabled( WP_Post $post ): bool` reads the option as false by default and applies `darven_who_published_estimation_enabled`.

`PublisherEstimator::estimate( WP_Post $post ): PublisherIdentity` uses this order: legacy ID, persisted new estimate, `_edit_last`, latest revision author, then `post_author`. Persist new IDs in `_darven_who_published_estimated_author` and sources in `_darven_who_published_estimation_source`. Apply `darven_who_published_estimated_publisher` before persistence; an overridden ID of `0` returns unknown.

`PublisherRetriever` returns confirmed first, unknown for disallowed statuses or disabled estimation, and delegates to the estimator only when enabled.

- [ ] **Step 5: Verify GREEN, remove the old guesser, and commit**

Run:

```bash
composer dump-autoload
vendor/bin/phpunit tests/test-publisher-retriever.php tests/test-publisher-estimator.php
composer test
git add src tests
git commit -m "feat: make historical publisher estimation opt in"
```

---

### Task 5: Rebuild the admin column, metabox, filter, and settings UI

**Files:**
- Modify: `src/Admin/ColumnManager.php`
- Modify: `src/Admin/MetaBoxDisplay.php`
- Create: `src/Admin/SettingsPage.php`
- Modify: `src/Core/Starter.php`
- Modify: `assets/css/admin.css`
- Create: `tests/test-column-manager.php`
- Create: `tests/test-meta-box-display.php`
- Create: `tests/test-settings-page.php`

**Interfaces:**
- Consumes: `PublisherIdentity`, estimation setting, post/page list-table hooks, Settings API, and publisher meta keys.
- Produces: adjacent Published by column, honest state badges, publisher filtering, safe metabox markup, and a default-off setting.

- [ ] **Step 1: Write failing column-order and state-rendering tests**

Test the key order directly:

```php
$columns = [ 'cb' => '', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' ];
$actual  = ( new ColumnManager() )->add_post_column( $columns );
$this->assertSame( [ 'cb', 'title', 'author', 'darven_who_published', 'date' ], array_keys( $actual ) );
```

Capture `handle_column_data()` output for confirmed, estimated, and unknown posts. Assert the confirmed output links the user and has a confirmed class, the estimated output contains `Estimated`, and unknown output contains `Unknown`. Do not assert full HTML strings.

- [ ] **Step 2: Write failing settings and metabox tests**

Register settings, then assert `get_registered_settings()['darven_who_published_enable_estimation']['default']` is false and its sanitize callback returns booleans. Capture the metabox output and assert it contains an actual `<span` element rather than escaped `&lt;span` text.

Add a real query test with one confirmed post and one estimated post for the same user. Apply the filter request and assert that `WP_Query` returns both post IDs when estimation is enabled, but returns only the confirmed post when it is disabled.

- [ ] **Step 3: Run focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/test-column-manager.php tests/test-meta-box-display.php tests/test-settings-page.php
```

Expected: column order, explicit identity rendering, metabox markup, and settings registration fail against 1.0.0 behavior.

- [ ] **Step 4: Implement the adjacent column and shared identity rendering**

Insert after Author without losing associative keys:

```php
$position = array_search( 'author', array_keys( $columns ), true );
if ( false === $position ) {
    $columns['darven_who_published'] = __( 'Published by', 'darven-who-published' );
    return $columns;
}

return array_slice( $columns, 0, $position + 1, true )
    + [ 'darven_who_published' => __( 'Published by', 'darven-who-published' ) ]
    + array_slice( $columns, $position + 1, null, true );
```

Render confirmed blue, estimated amber, and unknown neutral states with `printf()`, `esc_url()`, `esc_attr()`, and `esc_html()`. The metabox uses the same identity terminology and `wp_kses()` with an explicit allowed `<span>`/`<a>` attribute map.

- [ ] **Step 5: Implement Settings → Who Published and filtering**

Register `darven_who_published_enable_estimation` as a boolean option with default false. Add an options page requiring `manage_options`, one checkbox, an integrity explanation, and a Plugins-screen settings link.

Build the publisher dropdown from the union of confirmed IDs and, only when enabled, estimated IDs. A selected user produces a `meta_query` with an `OR` relation across confirmed and estimated keys rather than replacing unrelated existing query clauses.

- [ ] **Step 6: Verify GREEN and commit**

Run:

```bash
vendor/bin/phpunit tests/test-column-manager.php tests/test-meta-box-display.php tests/test-settings-page.php
composer test
git add src assets/css tests
git commit -m "feat: show publisher beside the post author"
```

---

### Task 6: Update identity, compatibility, directory copy, and translations

**Files:**
- Modify: `darven-who-published.php`
- Modify: `readme.txt`
- Modify: `languages/darven-who-published-es_ES.po`
- Modify: `languages/darven-who-published-es_ES.mo`
- Modify: `languages/darven-who-published-pt_BR.po`
- Modify: `languages/darven-who-published-pt_BR.mo`
- Modify: `languages/darven-who-published-pt_PT.po`
- Modify: `languages/darven-who-published-pt_PT.mo`
- Create: `languages/darven-who-published.pot`
- Create: `README.md`

**Interfaces:**
- Consumes: approved name, short description, tags, version constraints, UI strings, and exact screenshot captions.
- Produces: synchronized 1.1.0 plugin headers/readme, source documentation, and refreshed translation catalogs.

- [ ] **Step 1: Update plugin headers and shared version constant**

Set:

```php
/**
 * Plugin Name: Who Published - Post Publisher Column
 * Description: Shows who actually clicked Publish beside the credited post author.
 * Version: 1.1.0
 * Requires at least: 5.6
 * Requires PHP: 8.0
 * Author: Darven
 * Text Domain: darven-who-published
 */
const DARVEN_WHO_PUBLISHED_VERSION = '1.1.0';
```

Use the version constant for admin stylesheet cache busting.

- [ ] **Step 2: Rewrite `readme.txt` with the approved positioning**

Use the title `Who Published - Post Publisher Column`, stable tag `1.1.0`, tested up to `7.1`, and tags `publisher, editorial workflow, audit trail, multi author, post author`. Keep the short description under 150 characters.

Open with:

```text
The author wrote it. The publisher sent it live.

Who Published records the user who first publishes each post or page and displays that person directly beside the native Author column.
```

Document confirmed, estimated, and unknown states; the default-off setting; REST support; filtering; the editor metabox; and the distinction from a full activity log. Add three exact screenshot captions matching Task 7. Add a detailed 1.1.0 changelog covering capture, metadata, settings, UI, compatibility, migration, and assets.

- [ ] **Step 3: Generate and merge translation catalogs**

Run:

```bash
wp i18n make-pot . languages/darven-who-published.pot --domain=darven-who-published --exclude=vendor,tests,build,dist
wp i18n update-po languages/darven-who-published.pot languages/darven-who-published-es_ES.po
wp i18n update-po languages/darven-who-published.pot languages/darven-who-published-pt_BR.po
wp i18n update-po languages/darven-who-published.pot languages/darven-who-published-pt_PT.po
wp i18n make-mo languages
```

Translate every new user-facing string before generating `.mo` files; leave translator comments for evidence-source labels.

- [ ] **Step 4: Validate metadata and commit**

Run:

```bash
wp i18n make-pot . /tmp/who-published-check.pot --domain=darven-who-published --exclude=vendor,tests,build,dist
php -l darven-who-published.php
composer test
git add darven-who-published.php readme.txt README.md languages
git commit -m "docs: relaunch Who Published for editorial teams"
```

---

### Task 7: Produce the approved WordPress-native identity and screenshots

**Files:**
- Create: `wordpress-org-assets/banner-772x250.png`
- Create: `wordpress-org-assets/banner-1544x500.png`
- Create: `wordpress-org-assets/icon-128x128.png`
- Create: `wordpress-org-assets/icon-256x256.png`
- Create: `wordpress-org-assets/screenshot-1.png`
- Create: `wordpress-org-assets/screenshot-2.png`
- Create: `wordpress-org-assets/screenshot-3.png`
- Modify: `readme.txt`

**Interfaces:**
- Consumes: approved WordPress-native direction, completed 1.1.0 UI, controlled sample users João and Maria, and WordPress.org asset dimensions.
- Produces: exact-dimension directory assets and screenshots whose captions match `readme.txt`.

- [ ] **Step 1: Generate the banner and icon through the image-generation skill**

Use the image-generation skill with the approved constraints: admin blue, white and neutral gray; native system feel; no gradients; no WordPress logo; a two-role Author/Publisher card; and the message “The author wrote it. The publisher sent it live.” Generate the banner in a 772:250 composition and the icon as a square. Inspect each result and iterate through image edits until all text, role labels, contrast, and composition are correct.

- [ ] **Step 2: Produce required sizes and verify them**

The final exported files must report exactly:

```text
banner-772x250.png   772x250
banner-1544x500.png 1544x500
icon-128x128.png    128x128
icon-256x256.png    256x256
```

Keep all header images below 4 MB and icons below 1 MB.

- [ ] **Step 3: Create a controlled WordPress 7.1 demonstration site**

Create users João (`author`) and Maria (`editor`). Create a draft credited to João, sign in as Maria, and publish it. Confirm that the Posts table shows Author `João` and Published by `Maria` in adjacent columns.

- [ ] **Step 4: Capture three clean screenshots**

Use browser automation against the demonstration site and capture only WordPress admin content—no browser chrome, unrelated plugin columns, personal content, or gradients:

1. Posts table with Author João and confirmed publisher Maria side by side.
2. Settings → Who Published with estimation disabled and its explanation visible.
3. Editor metabox showing confirmed publisher Maria.

Crop to readable landscape frames, retain native WordPress UI, and verify each image is below 10 MB.

- [ ] **Step 5: Inspect, caption, and commit the assets**

Run:

```bash
file wordpress-org-assets/*
git add wordpress-org-assets readme.txt
git commit -m "design: add WordPress-native directory identity"
```

Open every image at original detail before committing. The first screenshot must visibly prove the column is immediately after Author.

---

### Task 8: Add reproducible builds, CI, and Plugin Check

**Files:**
- Create: `.distignore`
- Create: `bin/build-plugin.sh`
- Create: `bin/verify-build.sh`
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: source tree, Composer lockfile, PHPUnit/PHPCS scripts, WordPress 7.1, and `wordpress-org-assets/`.
- Produces: `build/darven-who-published.1.1.0.zip`, CI evidence, Plugin Check results, and a normalized production tree.

- [ ] **Step 1: Define the production archive boundary**

Create `.distignore` excluding:

```text
/.git
/.github
/build
/dist
/docs
/tests
/wordpress-org-assets
/node_modules
/vendor
/.gitignore
/.distignore
/phpcs.xml.dist
/phpunit.xml.dist
/composer.json
/composer.lock
```

The build script installs Composer production autoload files into a temporary staging tree, then archives the plugin root as `darven-who-published/`. Its core flow is:

```bash
project_root=$(pwd)
build_stage_dir=$(mktemp -d /tmp/who-published-build-XXXXXX)
mkdir -p "$build_stage_dir/darven-who-published" build/plugin
rsync -a --exclude-from=.distignore ./ "$build_stage_dir/darven-who-published/"
cp composer.json composer.lock "$build_stage_dir/darven-who-published/"
composer install --working-dir="$build_stage_dir/darven-who-published" --no-dev --classmap-authoritative --no-interaction
rm "$build_stage_dir/darven-who-published/composer.json" "$build_stage_dir/darven-who-published/composer.lock"
cp -a "$build_stage_dir/darven-who-published" build/plugin/
( cd "$build_stage_dir" && zip -qr "$project_root/build/darven-who-published.1.1.0.zip" darven-who-published )
```

The two `rm` targets are explicit generated files inside the newly created temporary staging directory.

- [ ] **Step 2: Implement and run the build verifier**

`bin/verify-build.sh` must fail unless the ZIP contains `darven-who-published/darven-who-published.php`, `readme.txt`, `src/`, `assets/css/admin.css`, `languages/`, and `vendor/autoload.php`; it must also fail if the ZIP contains `.git`, `.github`, `tests`, `docs`, `wordpress-org-assets`, or development Composer files.

Run:

```bash
bash bin/build-plugin.sh
bash bin/verify-build.sh build/darven-who-published.1.1.0.zip
unzip -l build/darven-who-published.1.1.0.zip
sha256sum build/darven-who-published.1.1.0.zip
```

- [ ] **Step 3: Create the CI workflow**

Configure `.github/workflows/ci.yml` on pushes and pull requests to run:

```yaml
- run: composer install --no-interaction --prefer-dist
- run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 7.1
- run: composer test
- run: composer phpcs
- run: bash bin/build-plugin.sh
- run: bash bin/verify-build.sh build/darven-who-published.1.1.0.zip
- uses: WordPress/plugin-check-action@v1
  with:
    build-dir: build/plugin/darven-who-published
    wp-version: '7.1'
    slug: darven-who-published
```

Use a MySQL 8 service with database `wordpress_test`, root password `root`, and health checks. Upload the ZIP and Plugin Check results as workflow artifacts.

- [ ] **Step 4: Run all local gates and commit**

Run:

```bash
composer test
composer phpcs
bash bin/build-plugin.sh
bash bin/verify-build.sh build/darven-who-published.1.1.0.zip
git add .distignore bin .github
git commit -m "ci: verify tests and release artifacts"
```

---

### Task 9: Verify behavior end to end and open the release pull request

**Files:**
- Modify only files required by failures discovered during verification.

**Interfaces:**
- Consumes: completed branch, CI workflow, WordPress 7.1 environment, approved spec, and release ZIP.
- Produces: clean verification evidence and PR against `main`.

- [ ] **Step 1: Run the complete automated gate from a clean dependency install**

Run:

```bash
composer install --no-interaction --prefer-dist
composer test
composer phpcs
bash bin/build-plugin.sh
bash bin/verify-build.sh build/darven-who-published.1.1.0.zip
```

The explicit `vendor` removal is limited to the generated dependency directory and is recoverable through `composer install`.

- [ ] **Step 2: Test a clean install and a 1.0.0 upgrade**

On WordPress 7.1/PHP 8.0+, install the 1.0.0 public ZIP, create one confirmed and one guessed record, replace it with the built 1.1.0 ZIP, and activate. Verify confirmed data remains confirmed, legacy guessed data appears only when estimation is enabled, and no activation warning or PHP notice appears.

- [ ] **Step 3: Test the defining editorial workflow**

Create a draft credited to João, publish it as Maria through wp-admin, then repeat through REST. Verify Maria is captured exactly once; edit as a third user and verify Maria remains. Confirm Published by sits immediately after Author on Posts and Pages.

- [ ] **Step 4: Push and open the pull request**

Run:

```bash
git status --short
git log --format='%h %an <%ae> %s' main..HEAD
git push -u github codex/1.1.0-relaunch
gh pr create --repo fkdarven/darven-who-published --base main --head codex/1.1.0-relaunch --title "Release Who Published 1.1.0" --body-file docs/superpowers/specs/2026-08-26-who-published-1-1-relaunch-design.md
```

Expected: every commit maps to `fkdarven`, CI starts, and the PR links the complete design.

- [ ] **Step 5: Review CI and code feedback before merge**

Run:

```bash
gh pr checks --watch
gh pr view --comments
```

Address concrete failures or review findings with focused tests and commits. Do not merge while checks or actionable review threads remain unresolved.

---

### Task 10: Merge, tag, and publish the GitHub release artifact

**Files:**
- No source changes expected.

**Interfaces:**
- Consumes: approved PR, green CI, verified ZIP, and GitHub repository.
- Produces: merged `main`, annotated tag `1.1.0`, GitHub release, ZIP, and SHA-256 checksum.

- [ ] **Step 1: Stop for merge approval**

Present the PR URL, checks, review state, release notes, and ZIP checksum. Merge only after explicit user approval.

- [ ] **Step 2: Merge and rebuild from the exact merged commit**

Run:

```bash
gh pr merge --merge --delete-branch
git switch main
git pull --ff-only github main
composer install --no-interaction --prefer-dist
composer test
composer phpcs
bash bin/build-plugin.sh
bash bin/verify-build.sh build/darven-who-published.1.1.0.zip
sha256sum build/darven-who-published.1.1.0.zip > build/darven-who-published.1.1.0.zip.sha256
```

- [ ] **Step 3: Tag and create the GitHub release**

Run:

```bash
git tag -a 1.1.0 -m "Who Published 1.1.0"
git push github 1.1.0
gh release create 1.1.0 build/darven-who-published.1.1.0.zip build/darven-who-published.1.1.0.zip.sha256 --repo fkdarven/darven-who-published --title "Who Published 1.1.0" --notes-from-tag
gh release view 1.1.0 --repo fkdarven/darven-who-published
```

---

### Task 11: Deploy the verified release to WordPress.org SVN

**Files:**
- Deploy: production ZIP contents to SVN `/trunk`
- Create: SVN `/tags/1.1.0`
- Deploy: `wordpress-org-assets/*` to SVN `/assets`

**Interfaces:**
- Consumes: GitHub release ZIP/checksum, approved assets, WordPress.org credentials, and existing SVN history.
- Produces: live WordPress.org 1.1.0 code, stable tag, listing copy, and assets.

- [ ] **Step 1: Stop at the WordPress.org publication gate**

Show the GitHub tag, release checksum, file inventory, successful install/upgrade evidence, and final directory assets. Proceed only after explicit user approval.

- [ ] **Step 2: Install or locate an SVN client and create a temporary checkout**

If `svn` is unavailable, request approval before installing the `subversion` package. Then run:

```bash
svn_release_dir=$(mktemp -d /tmp/who-published-svn-XXXXXX)
svn checkout https://plugins.svn.wordpress.org/darven-who-published "$svn_release_dir/repository"
```

- [ ] **Step 3: Stage the exact GitHub artifact into trunk**

Unpack the GitHub release ZIP into a separate validated temporary directory. Synchronize only after confirming both explicit paths. Use these task-specific paths so deletion is confined to the temporary SVN checkout:

```bash
artifact_tree_dir="$svn_release_dir/artifact/darven-who-published"
svn_trunk_dir="$svn_release_dir/repository/trunk"
svn_assets_dir="$svn_release_dir/repository/assets"
mkdir -p "$svn_release_dir/artifact"
unzip -q build/darven-who-published.1.1.0.zip -d "$svn_release_dir/artifact"
test -f "$artifact_tree_dir/darven-who-published.php"
test "$svn_trunk_dir" = "$svn_release_dir/repository/trunk"
test "$svn_assets_dir" = "$svn_release_dir/repository/assets"
rsync -a --delete "$artifact_tree_dir/" "$svn_trunk_dir/"
rsync -a wordpress-org-assets/ "$svn_assets_dir/"
```

The `--delete` target is the exact `trunk` directory inside the newly created temporary SVN checkout; the GitHub artifact remains available for recovery and comparison.

Set PNG MIME types:

```bash
svn propset svn:mime-type image/png "$svn_release_dir/repository/assets/"*.png
```

- [ ] **Step 4: Create the 1.1.0 tag and inspect the full SVN diff**

Run:

```bash
test ! -e "$svn_release_dir/repository/tags/1.1.0"
svn copy "$svn_release_dir/repository/trunk" "$svn_release_dir/repository/tags/1.1.0"
svn status "$svn_release_dir/repository"
svn diff "$svn_release_dir/repository"
```

Verify that trunk and tag plugin headers both say 1.1.0, both readmes use stable tag 1.1.0, and only approved assets changed.

- [ ] **Step 5: Commit the SVN release after authentication**

Run:

```bash
svn commit "$svn_release_dir/repository" -m "Release Who Published 1.1.0"
```

Authenticate interactively with the WordPress.org `fkdarven` account. Record the resulting SVN revision.

---

### Task 12: Verify the live WordPress.org release and preserve rollback evidence

**Files:**
- No source changes expected unless live verification identifies a defect.

**Interfaces:**
- Consumes: live listing, public WordPress.org ZIP, GitHub release tree, and SVN revision.
- Produces: verified live release or an explicit rollback decision.

- [ ] **Step 1: Verify listing metadata and asset dimensions**

Confirm the live page displays the new name, short description, tags, WordPress 7.1 compatibility, 1.1.0 changelog, banner, icon, and three screenshots. CDN assets may take several minutes; verify the CDN file dimensions directly rather than relying only on browser cache.

- [ ] **Step 2: Compare the public WordPress.org ZIP with the approved build tree**

Run:

```bash
live_verify_dir=$(mktemp -d /tmp/who-published-live-XXXXXX)
curl --fail --location --output "$live_verify_dir/live.zip" https://downloads.wordpress.org/plugin/darven-who-published.1.1.0.zip
unzip -q "$live_verify_dir/live.zip" -d "$live_verify_dir/live"
unzip -q build/darven-who-published.1.1.0.zip -d "$live_verify_dir/github"
diff -qr "$live_verify_dir/github/darven-who-published" "$live_verify_dir/live/darven-who-published"
```

Expected: no content differences. ZIP byte checksums may differ because WordPress.org regenerates archive metadata.

- [ ] **Step 3: Smoke-test the live ZIP**

Install the public WordPress.org ZIP on a clean WordPress 7.1 site. Activate it, publish a João-authored post as Maria, and verify the adjacent confirmed publisher badge, settings default, editor metabox, and absence of PHP warnings.

- [ ] **Step 4: Roll back only if a release-blocking defect is confirmed**

If activation, data integrity, or publisher capture is broken, stop distribution by changing SVN trunk’s stable tag back to `1.0.0` and commit that single rollback change after explicit user approval. Keep the GitHub 1.1.0 tag immutable, open a corrective issue, and fix forward with a new patch version.

- [ ] **Step 5: Report final release evidence**

Provide the GitHub repository, PR, tag, release checksum, SVN revision, WordPress.org listing, live ZIP comparison result, test counts, and smoke-test result. Do not claim completion before every fresh verification command succeeds.
