# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-18

### Added
- **Order tracking.** Four endpoints under `/idea89` answer the chat widget's
  order card: `customer/me`, `orders/recent`, `orders/detail` and a guest
  `orders/lookup`. The widget calls them same-origin with the shopper's own
  cookies, so order data goes browser to merchant and never reaches IDEA89 or
  a model provider. `Idea89_Order_Sanitizer` is an allow-list, so a future
  WooCommerce field cannot leak by default. Both lookup paths return an
  identical 404 for "no such order" and "not yours", the guest path compares
  emails with `hash_equals` and is capped at four attempts per hour per IP
  keyed by a salted hash, and the email is never logged. Tracking numbers are
  read from WooCommerce Shipment Tracking and AfterShip, with the
  `idea89_order_tracking` filter for anything else; an unknown carrier yields
  no URL rather than a guessed one. Off by default.
- **Store finder page.** A virtual page at a configurable slug, rendered
  inside the active theme so it carries the merchant's header and footer, with
  editable hero and help copy, a page title and meta description, two layouts,
  and per-store JSON-LD. A real page at the same slug always wins and the
  settings screen warns about the collision; reserved slugs such as `cart` and
  `checkout` fall back to the default. Off by default.
- **Shopper personalization.** Mints the per-store HMAC identity token the
  widget forwards to IDEA89, carrying only a customer id, a group id and a
  signed-in flag, expiring after an hour. Verified against the API's own
  verifier rather than assumed. Adds `POST /idea89/products/live`, authorised
  with the same secret and capped at 25 SKUs, so prices can be confirmed
  before they are quoted. Off by default, and treated as off unless both the
  toggle and a secret are set.
- **Dashboard-managed settings.** Map provider, map key, brand colour and the
  locator plan gate are read from the IDEA89 dashboard and cached for fifteen
  minutes. Fails closed: a timeout, a 500 or an unparsable body all leave the
  locator disabled.

### Fixed
- Store locator: read location fields from the shape `/widget/v1/locations`
  actually returns, where city and country sit under `address` and the
  coordinates under `geo` as `lat`/`lng`. The first cut read flat `city`,
  `country_code` and `latitude` keys the endpoint has never sent, which would
  have rendered the hero counts as zero and emitted JSON-LD with no address on
  every store. The flat shape is still accepted as a fallback.
- Store locator: the map host now contains a plain store list until the web
  component upgrades and replaces it. Previously a bundle that failed to run,
  for any reason, left a tall blank block with no explanation.

## [1.0.3] - 2026-08-18

### Fixed
- The text domain is now `idea89-ai-shopping-assistant`, matching the plugin
  slug. It was `idea89-assistant`, which meant WordPress' automatic translation
  loading (slug-based since 4.6) would never have found the plugin's strings.
  Flagged by Plugin Check as 55 `WordPress.WP.I18n.TextDomainMismatch` errors.
- Dropped the `load_plugin_textdomain()` call, discouraged since WordPress 4.6
  for directory-hosted plugins and a source of the
  `_load_textdomain_just_in_time` notice on 6.7+.

### Internal
- Annotated the three `apply_filters( 'the_content', ... )` call sites: the
  sniff reads them as unprefixed hooks, but they apply a core filter to render
  post content rather than defining a hook.

## [1.0.2] - 2026-08-18

### Fixed
- `Plugin URI` and `Author URI` were both `https://idea89.com`. WordPress
  requires them to differ: the plugin URI describes this specific plugin, the
  author URI describes who wrote it. `Plugin URI` now points at the plugin's
  public repository; `Author URI` stays on idea89.com.

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
