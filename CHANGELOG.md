# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-08-18

### Changed
- The "WooCommerce required" admin notice is now limited to the dashboard and
  plugins screens and carries `is-dismissible`, rather than rendering on every
  admin page with no way to close it (wordpress.org guideline 11). The gate is
  `Idea89_Plugin::should_show_requirements_notice()`, covered by tests that
  fail if it becomes sitewide again.
- Declares the WooCommerce dependency via the `Requires Plugins` header
  (WordPress 6.5+; older versions ignore it harmlessly).

### Documentation
- readme.txt now states that the chat panel carries an "idea89" footer label,
  why the widget is served from api.idea89.com rather than bundled, and how to
  ask about removing the label.

## [1.0.0] - 2026-08-18

### Added
- AI shopping assistant widget (floating chat, mobile-responsive, asynchronous
  loader that never blocks page render).
- Full WooCommerce catalogue sync: products, variations, attributes, prices,
  stock, categories, and review excerpts, via a paged background job
  (`Idea89_Catalog_Syncer`, 100 products per batch).
- Real-time sync triggers: product save/create, stock change, coupon save,
  post/page/custom-post-type save — every one queued through Action
  Scheduler rather than run inline, so nothing blocks an admin request.
- Daily full reconcile: catalogue, content, documents, FAQs and coupons.
- Coupon (promotion) sync — active, unexpired codes only.
- Category, page and store-info sync via the existing IDEA89 content lanes.
- Document sync for posts and any public custom post type, embedded and
  retrieved per chat turn rather than prompt-stuffed, so a large blog stays
  answerable. Deleted, trashed, or unpublished content is withdrawn, not
  left to rot in the index.
- FAQ auto-detection, in priority order: schema.org `FAQPage` JSON-LD
  (Yoast, Rank Math), native `<details>`/`<summary>` blocks, and known FAQ
  plugin post types (`ufaq`, `faq`, `faqs`, `helpie_faq`, `sp_faq`,
  `epkb_post_type_1`). Detection results are shown on the settings screen
  so a merchant can see exactly what was found.
- WooCommerce Store API add-to-cart, including variable-product variations
  — no custom cart, no theme override.
- Admin settings screen (Settings API) under a top-level **IDEA89** menu:
  connection, appearance, and content-sync sections.
- Test Connection and Sync Now admin actions, both nonce-protected and
  `manage_options`-gated.
- Configurable widget position (bottom-left / bottom-right) and brand
  colour.
- Configurable assistant name and store context.
- API URL override for self-hosted or enterprise deployments.
- API key stored in `wp_options` with `autoload` disabled, never exposed to
  REST or to any front-end script.
- HPOS (High-Performance Order Storage) compatibility declared explicitly.
- `uninstall.php` that removes every option the plugin created and
  unschedules any pending Action Scheduler jobs.
- Every scheduled job failure caught and logged unconditionally (not gated
  on `WP_DEBUG`) and reported through `WooCommerce > Status > Scheduled
  Actions`, so a failure is a visible signal rather than a silently
  vanished action.
