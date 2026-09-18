# Security review — 1.2.0

Reviewed the PHP source and admin assets from upstream commit `959a137` (version 1.1.0). This is a source review with isolated regression testing, not a penetration test or a guarantee that no other vulnerabilities exist. No production site or SMTP credentials were accessed.

## Findings addressed

| Severity | Finding in 1.1.0 | Change |
| --- | --- | --- |
| High | `manage_options` alone allowed storing executable PHP and raw scripts. Multisite site administrators, or administrators on sites with file editing disabled, could bypass core code-editing boundaries. | Require `edit_plugins` for PHP and `unfiltered_html` for scripts on save and editor rendering. Main admin actions still require `manage_options` and a valid nonce. |
| High | PHP cache capture did not exclude password-cookie requests or password-protected posts. A visitor with the password could cause protected HTML to be stored in the public static cache. | Exclude cookie requests and all protected/unpublished singular content; align rewrite cookie bypass. Do not cache private/no-store/no-cache, Set-Cookie, non-200 or DONOTCACHEPAGE responses. |
| High | The cache invalidator returned early when a post became private/draft and used `deleted_post` after the original permalink could disappear. Old public files could continue exposing withdrawn content. | Purge cache on non-revision saves and before deletion, including previous URLs/archive copies. Queued post builds recheck publication/password status. Clear legacy cache on the first administrator visit after upgrading. |
| Medium | The prebuilder wrote any successful HTML response even if the capture layer rejected it; redirects or filtered external URLs could fetch unwanted destinations and forward the build URL token. | Restrict origins, use `wp_safe_remote_get` without redirects and require a file written by the checked capture path. |
| Medium | Multisite site administrators could enumerate and optimize tables across the shared database. | Restrict database-wide listing, size and optimization to `manage_network_options`; per-site cleanup remains unchanged. |
| Hardening | Plugin updates were forced on even when WordPress's update preference was off. Cache writes reported success without checking write/rename failures. | Respect WordPress auto-update preference; check writes and use unique temporary files. |

Core authorization semantics were checked against [map_meta_cap](https://developer.wordpress.org/reference/functions/map_meta_cap/). Password-cookie behavior is documented by [post_password_required](https://developer.wordpress.org/reference/functions/post_password_required/). The cache excludes the presence of a post password, not just whether the current visitor has supplied it.

The new checklist requires `manage_options`, a POST request and a verified nonce for dismissal, escapes output, never renders the SMTP password and stores dismissal in a separate non-autoloaded site option. Its revision query is bounded at 11 records.

## Remaining risks and recommended upgrades

1. **SMTP secret storage:** passwords remain plaintext in the WordPress options table for compatibility. A database reader or database backup leak can expose them. Add an environment/wp-config secret provider or encryption with a key stored outside the database; hashing is unsuitable because SMTP needs the original secret. TLS/SSL is preferable to the existing None option.
2. **PHP snippets remain trusted executable code:** capability checks do not sandbox snippets. Runtime errors, infinite loops and delayed callback failures can still break a site. The recovery constant is included; a future upgrade should lint before enabling, keep previous versions and support automatic rollback.
3. **Static caching needs application-specific exclusions:** cookie bypass and common commerce exclusions do not cover every membership/personalization plugin, custom authorization scheme, concurrent generation/invalidation race, reverse proxy or server configuration. Cache purges can fail if filesystem permissions are wrong. Verify deletion and direct-file access on staging; do not enable static caching for sensitive dynamic content without integration tests. Adding cache-generation locking, explicit URL exclusions and surfaced purge failures is recommended.
4. **Update supply chain:** the updater can still fall back to a mutable GitHub branch ZIP. Prefer immutable versioned release packages, pinned commits and release checksums. Auto-update opt-out is now honored.
5. **Database maintenance at scale:** cleanup loops run within one HTTP request and can time out on large sites. Add resumable batches, progress reporting and a dry-run count. Table optimization should inspect per-table SQL status messages rather than rely only on the query return value.
6. **Build credentials:** the existing static-build token remains a long-lived query parameter and can appear in HTTP logs. A short-lived signed request header would reduce exposure.

## Validation

- PHP 8.3 syntax validation for all plugin and test PHP files.
- `php tests/regression.php`: 98 checks covering SMTP completeness/password retention/removal, 0/10/11 revision thresholds, notice visibility, dismissal authorization/nonce/persistence, code permissions, multisite database boundaries, update metadata, static privacy guards, invalidation and generation guards.
- `git diff --check`.
- Installed and activated on the provided local WordPress 7.1.1 / PHP 8.3.30 instance. HTTP integration checks passed for dashboard/plugin/post/page-list visibility; post/page/Site Editor exclusions; SMTP save, disable, password retention and removal; real total revision counts at 10 and 11; cleanup of the created fixture; and missing/invalid dismissal nonces. Browser inspection verified the dashboard layout and dismissal across reload and plugin deactivation/reactivation. Dummy SMTP values were removed and test content was deleted; the plugin remains active and the notice dismissed on this test site.
- Real mail delivery, Apache/LiteSpeed cache rewrite execution, actual multisite capability mapping, and PHP 7.4 runtime verification were not performed. Static privacy and permission regression tests use WordPress API doubles. See the remaining staging checklist in README.md.
