# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-10-06

### Fixed
- **Global (`pa_*`) attributes are sent as term names**, not term ids: the
  flat map carried "12, 15" for a colour.

### Added
- **Catalogue schema 2**: `schema_version` and `platform` on the batch, and
  per product an `attribute_list` (label from `wc_attribute_label()`, term
  names or typed text, `select`/`multiselect`/`text`/`number`, `visible` from
  "Visible on the product page", `filterable` for global attributes), weight
  and dimensions with the shop's units, `price_includes_tax`
  (`wc_prices_include_tax()`), `tax_rate` (base-location rates of the tax
  class), `short_description`, `category_paths`.
- **Variations**: `stock_qty`, `name`, their own `attribute_list` (options,
  weight, dimensions), and `price` read from the variation (same basis as
  the parent) instead of `display_price`.
- **Variation stock** is also sent as `{parent_external_id, sku}`, so the API
  updates the variation inside its parent; the parent line is unchanged.

### Notes
- Core WooCommerce has no bulk pricing; nothing is sent for it.
- An IDEA89 API that predates schema 2 ignores the new keys.

## [1.2.5] - 2026-10-01

### Changed
- **Catalogue sync key is part of setup**, no longer labelled optional: stores
  created in IDEA89 from 2026-10-01 do not sync without it.

### Fixed
- **A refused sync is reported, not swallowed.** A 401 with
  `sync_key_not_set`, `sync_key_required` or `invalid_sync_key` on a
  catalogue write is stored in `idea89_sync_key_rejection` (not autoloaded,
  removed on uninstall) and shown on the settings page; the next accepted
  catalogue write clears it. `sync_page` ends the page chain on such a
  refusal and does not record `idea89_last_full_sync_at`.
- **Test connection checks catalogue access.** After `/v1/catalog/stats` it
  posts to `/v1/catalog/verify` with the sync key, and reports the API's
  message when catalogue writes would be refused. A 404 (an older API) still
  passes.

## [1.2.4] - 2026-10-01

### Fixed
- **Native checkout honours the payment gateway's result.**
  `handle_place` ignored the value `process_payment()` returned, so a
  declined or failed payment still produced a 200 with an order id and the
  widget showed "Order confirmed". Anything other than `result: success`,
  or an order left failed or cancelled, now returns 402 `payment_failed`
  with the gateway's shopper-safe message and no order id. Success responses
  include the order status.
- **Embedded checkout bridge confirms only paid or on-hold orders.**
  Failed and cancelled orders post `order_failed`; pending and unknown
  statuses post `pending`, which the widget ignores.

### Security
- **Order lookup by id skips draft, auto-draft and trashed orders.**
- **ACP feed:** optional bearer secret (`idea89_acp_secret`, compared with
  `hash_equals`) and API-Version pin; paginated `wc_get_products` query
  instead of loading the whole catalogue; 60 requests per minute per IP;
  `Cache-Control: private, no-store` when a secret is set.

### Added
- **Catalogue sync key** (`idea89_sync_key`, not autoloaded, removed on
  uninstall), sent as `X-IDEA89-Sync-Key` on catalogue writes only.

## [1.2.3] - 2026-09-20

### Security
- **`__IDEA89_WC` is JSON-encoded like every other inline value.**
  `Idea89_Widget::config_js()` built the Store API URL, nonce and basket
  URL into a hand-written object literal through `esc_js()`. That function
  is for attribute context (`onclick="..."`): it turns `& < >` into HTML
  entities, which a `<script>` element does not decode, so a URL carrying
  a query string would have reached the widget corrupted, and the 1.2.2
  hex-escaping did not cover it. The object now goes through
  `wp_json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
  JSON_HEX_QUOT`, exactly as `__IDEA89_CHECKOUT` already did. Same three
  keys (`storeApi`, `nonce`, `cartUrl`), same values on the JS side.
  Second finding from the wordpress.org plugin review.

### Changed
- **The stripped checkout page is printed, not buffered and echoed.**
  `Idea89_Mini_Checkout::render()` used to assemble the whole document
  (buffered `wp_head()` and `wp_footer()`, the checkout markup, the bridge
  script) into one string that `maybe_render()` then echoed under a phpcs
  suppression. It now prints the way a page template does: literal
  markup, `wp_head()` and `wp_footer()` printing themselves, the handshake
  and error envelopes printed by `wp_print_inline_script_tag()`, the
  shortcode checkout by `do_shortcode()` and the block checkout by
  `do_blocks()`. The one remaining unescaped echo is the rendered Checkout
  block, which is WooCommerce's own form (the same HTML `the_content()`
  prints for the page) and cannot go through `wp_kses_post()` without
  breaking. Headers are sent before the first byte, as before.
  `Idea89_Checkout_Bridge` drops the string-returning `handshake_script()`,
  `success_script()` and `error_page()` wrappers; `error_page()` becomes
  `print_error_page()` and the `*_js()` builders are the only script API.
  Same page, same headers, same bridge messages.

## [1.2.2] - 2026-09-13

### Changed
- **Widget loader is enqueued, not printed.** `Idea89_Widget` now hooks
  `wp_enqueue_scripts`: the loader is registered with
  `wp_register_script()` (footer, `strategy => async`, no `?ver=` because
  the API versions it), the `__IDEA89_PLATFORM` / `__IDEA89_WC` /
  `__IDEA89_CHECKOUT` block is attached with
  `wp_add_inline_script( ..., 'before' )` so it still runs first, and the
  `data-key` / `data-position` / `data-color` attributes are added through
  the `wp_script_attributes` filter, scoped to our handle. The JavaScript
  the browser receives is unchanged; `__IDEA89_WC` is byte-identical.
- **Bridge scripts are built by core.** `Idea89_Checkout_Bridge` returns
  JavaScript from `handshake_js()`, `success_js()` and `error_js()`; the
  `<script>` element around each comes from `wp_get_inline_script_tag()` or
  `wp_print_inline_script_tag()`, never from a hand-written tag. The
  `*_script()` / `error_page()` methods remain and now delegate to those.
- **Store finder analytics hook and JSON-LD** are printed through
  `wp_print_inline_script_tag()`; `analytics_script()` is now public static.

### Security
- **Inline JSON can no longer close its own script element.** Every
  `wp_json_encode()` whose output lands inside a `<script>` now passes
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, so a
  configured API base URL or key, a store name in the JSON-LD, or an order
  number rewritten by another plugin cannot carry a `</script>` that
  terminates the block and injects markup. `\u003C` is still the same value
  to JavaScript and to every JSON-LD consumer. Found in the wordpress.org
  plugin review; the same pattern was fixed everywhere it appeared, not only
  at the two locations reported.

## [1.2.1] - 2026-09-13

### Added
- **Assistant name now sets the name shoppers see.** The field under
  Appearance used to save a value nothing read: the name above the
  conversation came from your IDEA89 account, and this box changed nothing.
  It is now that same name, so setting it here sets what shoppers see and how
  the assistant refers to itself when asked.

  Like Checkout display, it is stored in your IDEA89 account rather than in
  WordPress, so it is the same in both places. If IDEA89 cannot be reached
  when you save, nothing is changed and WordPress tells you why.

- **Checkout display, shared with your IDEA89 dashboard.** A new field under
  Checkout experience. **Full window** gives checkout the whole screen and
  hides the conversation behind it; **In the chat** keeps checkout inside the
  assistant panel alongside the conversation. Full window is the default and
  is how the assistant has behaved so far, so nothing changes on upgrade.

  This one setting lives in your IDEA89 account rather than in WordPress, and
  the dashboard has the same field. Changing it in either place changes it in
  both, so the two screens cannot show you different answers. If IDEA89 cannot
  be reached when you save, nothing is changed and WordPress tells you why,
  rather than storing a value your dashboard never learned about.

### Changed
- **Personalization status is spelled out on the settings screen.**
  Personalization needs two switches on, one in WordPress and one in the
  IDEA89 dashboard, and the settings screen now says which half is on and
  which is off, so a store where only one is on is no longer told nothing.
  If IDEA89 cannot be reached, it says the state of the other half is
  unknown rather than guessing.

## [1.2.0] - 2026-08-26

### Added
- **Checkout experience setting.** `Idea89_Checkout_Config` reads a new
  `idea89_checkout_mode` option (Off, Express handoff, Checkout in chat,
  Native checkout, default Express) under a new "Checkout experience"
  section on the settings screen, mirroring the Magento 2 module's ladder.
  The storefront embed now also publishes `window.__IDEA89_CHECKOUT`
  (platform, checkoutMode, cartPath, checkoutPath, miniCheckoutPath, formKey)
  alongside the existing `window.__IDEA89_WC`, which is left untouched.
  `cartPath`/`checkoutPath` are derived from `wc_get_cart_url()` /
  `wc_get_checkout_url()` reduced to a path with `wp_parse_url()`, so a store
  living in a subdirectory still resolves correctly against
  `window.location.origin`. `formKey` carries the Store API nonce, since Woo
  has no form-key equivalent.
- **WooCommerce cart summary arm.** The shared widget's `_cartSummary()`
  dispatch now resolves on WooCommerce via the Store API's own `/cart`
  route (no new endpoint), returning the same `{count, subtotal, items}`
  shape as the Magento arm. Formats the subtotal from
  `totals.total_items` and `totals.currency_minor_unit` rather than
  assuming two decimal places, so zero-decimal currencies (JPY) and
  three-decimal currencies (KWD) both render correctly.
- **Chrome-free checkout for the assistant panel.** `GET /?idea89-checkout=1`
  serves the merchant's real WooCommerce checkout with the theme's header,
  footer and navigation stripped out, for the chat widget to frame. Guards,
  in order: plugin enabled and checkout mode is Embedded, the cart has at
  least one item, and the checkout page renders through a recognised path.
  Both `wp_head()` and `wp_footer()` still run, so WooCommerce's own scripts
  and styles enqueue normally. `miniCheckoutPath` is now `/?idea89-checkout=1`
  rather than empty.
- **Block-versus-shortcode checkout detection.**
  `Idea89_Mini_Checkout::detect_checkout_type()` probes the store's actual
  checkout page for the WooCommerce Checkout block, then the classic
  `[woocommerce_checkout]` shortcode, then falls back to `unknown`, which
  the assistant panel treats as an immediate fallback rather than waiting
  out its handshake timeout. A status line next to the checkout mode select
  reports which one a store uses.
- **postMessage bridge**, matching Magento 2 and Magento 1's contract
  exactly: `ready`/`resize` on the stripped checkout, `success` (with the
  order number, total and currency) on the real thank-you page, and a
  same-origin error page carrying a machine-readable `code` for every guard
  failure. The success hook fires on `woocommerce_thankyou`, which every
  WooCommerce shopper reaches on every order, framed or not; the emitted
  script's first statement is always `if (window.parent === window) return`,
  so an ordinary unframed purchase is completely unaffected by it running.
- Checkout in chat is a real, working rung: selecting it opens the framed
  checkout above.
- **Native checkout (beta) is a real, working rung too.** Selecting it lets
  the assistant collect delivery details in the conversation and place the
  order itself, over four new REST routes under `wp-json/idea89/v1/checkout`.
  Payment methods are behind an allowlist under IDEA89 > Checkout experience
  that is empty by default, so a freshly switched-on store places no orders
  at all until you explicitly choose which methods the assistant may use.
  Offline methods (cash on delivery, bank transfer, cheque) are recommended;
  anything that needs a hosted payment page or a redirect cannot complete
  inside the chat. Existing stores keep today's behaviour unless this
  setting is changed.
- **Agentic Commerce Protocol product feed**, off by default. A new setting
  under IDEA89 > Agentic Commerce publishes your catalogue (names,
  descriptions, prices, stock and page links) at
  `wp-json/idea89/v1/acp/feed.json` in the Agentic Commerce Protocol shape,
  so assistants such as ChatGPT can find and recommend your products. This
  is separate from the checkout setting above and works alongside any of
  those options. It does not give anyone access to your orders, customers
  or payment details, and nothing is published until you turn it on.
- **Pinned checkout bar.** A new "Pinned checkout bar" checkbox under
  IDEA89 > Checkout experience, defaulted on. When on, the assistant shows
  a full-width bar above its message box reading "Checkout, N items,
  total" whenever the shopper's basket has items. It is new persistent
  chrome, so it can be switched off per store even though it only ever
  appears on a basket with something in it. Tapping it goes through the
  same checkout mode already configured above; it is a second,
  always-visible way to reach checkout, not a new checkout path.

### Fixed

- **Uninstall now removes the checkout settings it used to leave behind.**
  `uninstall.php` did not clear `idea89_checkout_mode`,
  `idea89_checkout_bar_enabled`, `idea89_checkout_native_methods` or
  `idea89_acp_enabled`, so those four survived removing the plugin. The one
  that mattered is `idea89_acp_enabled`: it controls whether catalog data is
  published for agentic commerce and it ships switched off, so a store that
  had turned it on, uninstalled, and later reinstalled would have started
  publishing again straight away, with no prompt and no notice, on an install
  the merchant had every reason to read as fresh. All four are now removed
  along with the rest of the plugin's settings.

## [1.1.1] - 2026-08-18

### Added
- Sends the site's base path as `X-IDEA89-Site-Path`, so a WordPress install
  in a subfolder is recognised as its own IDEA89 account rather than sharing
  an identity with a shop at the domain root. Testing the connection now names
  the site an API key belongs to, so a key pasted from the wrong site on the
  same host is caught at setup instead of after a sync.

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
