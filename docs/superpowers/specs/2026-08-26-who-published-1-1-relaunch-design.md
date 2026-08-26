# Who Published 1.1 Relaunch Design

**Status:** Approved in conversation on 2026-08-26

**Product:** `darven-who-published`

**Release:** 1.1.0

**Canonical repository:** `https://github.com/fkdarven/darven-who-published`

**WordPress.org slug:** `darven-who-published`

## Context

The existing WordPress.org plugin records the user who first publishes a post and exposes that user in the WordPress administration interface. Its useful distinction is not the general activity-log question “who changed what?” It is the narrower editorial question “who actually clicked Publish?” The credited post author and the publishing user may be different people in a newsroom or other multi-author workflow.

The 1.0.0 listing obscures that distinction by repeatedly calling the publisher the “original author.” Its title and tags do not match high-intent searches, its banner has the wrong aspect ratio, its screenshots bury the publisher column after unrelated columns, and WordPress.org warns that the plugin has not been tested with the latest three major WordPress releases. The source currently lives only in WordPress.org SVN and has no canonical GitHub repository or automated test suite.

The code audit also identified correctness gaps: the plugin boots only during `is_admin()` requests despite claiming REST compatibility; post metadata is registered only for posts despite advertised page support; the post-list column is appended rather than inserted after Author; metabox badge markup is escaped incorrectly; and historical publisher guesses are written automatically even though the underlying evidence cannot prove who published the post.

## Product promise

The release will be presented as:

> The author wrote it. The publisher sent it live.

The plugin records the authenticated user who first publishes each post or page and displays that person directly beside the native Author column. It remains a focused editorial-accountability utility, not a general activity-log product.

## Goals

- Establish a public GitHub repository as the canonical source.
- Preserve the existing WordPress.org slug, text domain, and confirmed-publisher metadata.
- Correct publisher capture across admin, REST, and programmatic publication flows.
- Put a clear Published by column immediately after Author.
- Make historical estimation optional, disabled by default, and visibly unconfirmed.
- Replace misleading author terminology throughout code-facing descriptions and user-facing copy.
- Add automated regression, integration, coding-standard, and package checks.
- Relaunch the listing with a WordPress-native visual identity and precise positioning.
- Publish a verified 1.1.0 artifact to GitHub and WordPress.org.

## Non-goals

- General user activity logging or audit-event history.
- Front-end publisher display.
- Reports, exports, analytics, or dashboards.
- Custom post type support beyond posts and pages in 1.1.0.
- Reconstructing a confirmed publisher for content created before activation.
- Changing the WordPress.org slug or existing confirmed metadata key.

## Source and release architecture

Create the public repository `fkdarven/darven-who-published`. Import the current WordPress.org trunk and assets as the baseline source snapshot. GitHub becomes the authority for branches, pull requests, tests, changelog, tags, and distributable artifacts. WordPress.org SVN is deployment-only and must receive files built from a reviewed GitHub release tag.

Development occurs on `codex/1.1.0-relaunch`. The pull request must pass automated checks and a release-artifact inspection before merge. After merge, tag `1.1.0`, create a GitHub release, and verify the exact ZIP. WordPress.org deployment is a separate human gate. No automatic SVN deployment secret or unattended publishing workflow is introduced in this release.

The 1.0.0 SVN tag remains available for rollback. If a critical live defect is discovered, restore the WordPress.org stable tag to 1.0.0 while fixing forward on GitHub.

## Publisher data model

The existing confirmed metadata key, `_darven_who_published_author`, remains authoritative. A confirmed value is an integer WordPress user ID and is immutable after first successful capture.

Historical estimation is separate from confirmed data. The implementation must not copy an estimated ID into the confirmed key. Existing 1.0.0 values stored in `_darven_who_published_author_was_guessed` are interpreted as legacy estimated user IDs, even though 1.0.0 registered that key inconsistently as a boolean. New estimates use `_darven_who_published_estimated_author` for the integer user ID and `_darven_who_published_estimation_source` for one of `legacy`, `edit_last`, `latest_revision`, or `post_author`. Compatibility reads preserve existing legacy estimates without promoting them.

The option `darven_who_published_enable_estimation` defaults to `false`. When disabled, a published historical item without confirmed metadata displays Unknown and does not run or persist estimation logic. When enabled, the estimator uses the first available source in this order: a legacy 1.0.0 estimated ID, `_edit_last`, the latest revision author, then `post_author`. A newly calculated estimate is persisted in the new estimate and source keys so later edits do not silently change it. Its output is always labeled Estimated, includes its evidence source in a tooltip, and never claims certainty.

The boolean filter `darven_who_published_estimation_enabled` may override the saved setting. The filter `darven_who_published_estimated_publisher` receives the estimated user ID, source, and post object and may override or suppress the estimate. Neither filter can overwrite confirmed metadata.

## Capture flow

Core tracking must initialize for all relevant request contexts; only the administration UI components remain conditional on `is_admin()`.

When a post or page transitions from a non-published status to `publish`, the tracker reads the current authenticated user. If the confirmed metadata key is empty and the user ID is valid, it stores that user ID. Subsequent edits, republishes, REST updates, imports, or status transitions must not replace the stored ID.

REST and programmatic publication hooks may call the same idempotent capture method. Duplicate WordPress hooks during one publication request are acceptable because the first successful write makes subsequent calls no-ops. Scheduled publication without an authenticated user remains Unknown rather than assigning an invented publisher.

Register both confirmed and estimated metadata for `post` and `page` with appropriate integer types, sanitization, authorization, and REST visibility. Confirmed publisher metadata is exposed through REST to users allowed to edit the relevant content. Estimation-internal metadata is not exposed by default.

## Administration experience

The Posts and Pages list tables receive a Published by column inserted immediately after the native Author column. A confirmed publisher appears in a blue badge with a check indicator. An estimated publisher appears in an amber badge prefixed with Estimated. Missing information appears as neutral Unknown text.

The list-table filter uses publisher terminology. Confirmed publishers are filterable. When estimation is enabled, the dropdown includes the union of confirmed and estimated users without duplicate options, and the selected user matches either metadata key. The badge still distinguishes the source of each matched row. Output, URLs, attributes, nonces, and query inputs follow WordPress escaping and sanitization conventions.

The post-editor side metabox is titled Published by. It shows the same confirmed, estimated, or unknown state as the list table and renders permitted badge markup safely instead of escaping the entire HTML fragment.

Settings → Who Published contains one primary control: Estimate publishers for historical posts. It is disabled by default and explains that WordPress cannot prove who published content created before plugin activation. Enabling it permits clearly marked estimates; disabling it hides estimates without deleting confirmed data. A settings link is available from the Plugins screen.

## Visual identity

Use the approved WordPress-native direction: admin blue, white, WordPress-neutral grays, and native system typography. Do not use the WordPress logo or imply official WordPress affiliation. Avoid gradients, decorative browser chrome, unrelated plugins, and personal production content in promotional assets.

The directory banner uses the exact 772 × 250 composition, with a 1544 × 500 retina counterpart. Its message is:

> The author wrote it. The publisher sent it live.

The supporting visual shows a compact post row with different Author and Published by values. The icon uses a two-role card—Author and Publisher—on an admin-blue field and must remain legible at 128 × 128 and 256 × 256.

The listing contains three controlled screenshots:

1. A clean Posts table showing Published by immediately beside Author with different sample users.
2. The historical-estimation setting disabled by default with its integrity explanation.
3. The editor metabox showing a confirmed publisher and its meaning.

## WordPress.org positioning

The visible plugin name becomes **Who Published – Post Publisher Column**. The WordPress.org slug remains `darven-who-published`.

The short description is:

> Shows who actually clicked Publish beside the credited post author—without opening a separate activity log.

The description opens with the approved product promise and explains the author/publisher distinction before listing features. It distinguishes the plugin from general activity logs, documents confirmed versus estimated states, explains the default-off estimation option, and describes list filtering, metabox display, and REST support.

Use these five tags: `publisher`, `editorial workflow`, `audit trail`, `multi author`, and `post author`. Remove misleading references to “original author.” The 1.1.0 changelog must enumerate behavioral fixes, settings, compatibility, presentation, and migration handling rather than compressing the release into generic bullets.

Set `Tested up to` to WordPress 7.1, the current stable release as of 2026-08-26. Preserve the existing WordPress 5.6 minimum and PHP 8.0 minimum unless test evidence requires a documented change.

## Testing and quality gates

Automated tests must cover:

- Initial publisher capture on a publish transition.
- Idempotency and immutability across subsequent updates and republishes.
- Admin, REST, and programmatic publication paths.
- Unknown behavior when no authenticated publisher exists.
- Metadata registration for posts and pages.
- Estimation disabled by default.
- Estimated display and evidence labeling when enabled.
- Compatibility reads for legacy 1.0.0 guessed IDs.
- Confirmed data taking precedence over estimates.
- Column insertion immediately after Author.
- Settings persistence, authorization, and nonce handling.
- Safe list-table, filter, and metabox output.
- Upgrade preservation of existing confirmed metadata.

The repository runs PHPUnit with WordPress integration coverage, PHP syntax checks, WordPress Coding Standards, and WordPress Plugin Check. The build process creates a production ZIP without tests, development configuration, temporary files, or promotional source material. Test installation and upgrade from 1.0.0 on WordPress 7.1 and PHP 8.0 or newer.

## Publication and verification

After the pull request passes review and automated checks, merge it, tag 1.1.0, and build the GitHub release artifact. Verify its checksum, file inventory, plugin headers, readme stable tag, compatibility declarations, and clean installation before requesting WordPress.org publication approval.

After explicit human approval, deploy the verified artifact and approved assets to WordPress.org SVN. Confirm that the live listing shows the new title, copy, tags, compatibility, banner, icon, screenshots, and changelog. Download the public ZIP from WordPress.org, compare its contents with the approved artifact, activate it on a clean WordPress 7.1 installation, publish a post as a user different from the credited author, and verify the adjacent Published by column.

## Acceptance criteria

- GitHub publicly hosts the canonical reviewed source and 1.1.0 release.
- Existing confirmed publisher metadata remains intact and immutable.
- Publishing through supported request contexts captures the correct authenticated user.
- Historical estimation is opt-in and cannot be mistaken for confirmation.
- Published by appears immediately beside Author on Posts and Pages screens.
- The WordPress.org listing communicates the author-versus-publisher distinction above the fold.
- All automated checks and installation/upgrade smoke tests pass.
- The live WordPress.org ZIP matches the reviewed release artifact and activates cleanly.
