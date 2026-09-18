# Site Settings

WordPress utilities for SMTP, header/footer scripts, PHP snippets, static HTML caching and database maintenance. Version 1.2.0 requires WordPress 6.0+ and PHP 7.4+.

## Move to Live Checklist

Activation makes the checklist available at the top of site administration pages for users with `manage_options`. It is excluded from post/page/custom-post-type editors, the Site Editor, widgets/customizer, term/user editors and plugin/theme code editors. Listing screens, including Posts and Pages, still show it. Network and user administration do not show site-specific status.

1. **SMTP Configuration (Active / Inactive):** Active means SMTP is enabled and the host, valid port, encryption selection, username, password, valid sender email and sender name are present. This is configuration readiness, not a delivery guarantee. Use the SMTP test-email action to check sending. Provider usernames such as `apikey` are supported.
2. **Clean and Optimize Database (Optimized / Not Optimized):** 0–10 revisions means Optimized; 11 or more means Not Optimized. A failed query does not report Optimized. This indicator measures revisions only, not fragmentation or the other cleanup tasks.

Both items link to their corresponding settings pages. The notice remains visible even when both checks pass, until **Dismiss this notification.** is clicked. Dismissal is a nonce-protected POST and is permanent for the current site and all its administrators. It survives settings resets, plugin updates and deactivation/reactivation. Each multisite site has its own dismissal state. No recurring reset or automatic cleanup is performed.

## Other changes in 1.2.0

- **Remove saved password** explicitly clears the SMTP secret; leaving the password field blank otherwise keeps it. Removal takes precedence over entering a replacement in the same save.
- SMTP tests require a complete, enabled configuration; ports are restricted to 1–65535.
- PHP snippet editing requires `edit_plugins`; raw script editing requires `unfiltered_html`. WordPress's file-editing restrictions and multisite capability restrictions apply. Existing snippets continue running when editing is disabled.
- Emergency recovery: add `define( 'AVINASH_SITE_SETTINGS_DISABLE_CUSTOM_FUNCTIONS', true );` to `wp-config.php` before WordPress loads to stop saved PHP snippets from executing. Remove it after correcting the snippet.
- Automatic plugin updates now follow the WordPress Plugins-screen preference.
- Shared database table listing/optimization on multisite requires network-administrator privileges. Per-site revision/comment cleanup remains available.
- Static caching excludes cookie-bearing requests, protected/unpublished posts, WooCommerce cart/checkout/account pages, redirects and responses that set cookies or prohibit caching. Saves and deletions purge stale cache; builds cannot bypass these exclusions.

The first site-admin visit after updating clears previously generated static files and refreshes rewrite rules. Cookie-based cache bypass reduces cache hits. Prebuild uses same-origin safe loopback HTTP without redirects; the site must resolve and serve its canonical URLs directly. Static caching still requires compatibility testing for membership, commerce and other personalized content.

## Install and verify

Upload the ZIP through **Plugins → Add New → Upload Plugin**, then activate or replace the existing plugin. Review [SECURITY_REVIEW.md](SECURITY_REVIEW.md) for findings and remaining risks.

Run the isolated regression suite with `php tests/regression.php`. These tests use WordPress API doubles; they do not substitute for WordPress/browser/server integration testing.

Before production use, verify on staging:

1. Fresh activation shows the checklist on Dashboard, Plugins, settings, Posts and Pages lists. Classic/block editors for posts, pages and a custom post type omit it.
2. Toggle SMTP, remove individual required values, save/reload, clear/replace the password and send a real test email. Check that saved secrets never appear in page source.
3. With 0, 10 and 11 revisions, check the exact threshold; delete revisions through the existing cleanup tool and reload.
4. Dismiss, visit another admin page, log in as another administrator and reactivate the plugin. It must remain hidden. Confirm lower-privilege and invalid-nonce requests cannot dismiss it.
5. On multisite, confirm site administrators cannot save PHP/scripts or operate on shared database tables; confirm eligible network administrators can.
6. With static caching enabled, generate a public page, then make it private/password-protected, change its slug or delete it. Verify the old URL and direct cache files no longer expose it. Check protected/session/WooCommerce pages and cache-control exclusions through both PHP and Apache/LiteSpeed.
7. Verify auto-update preferences and snippet recovery on the deployment PHP/WordPress versions.
