# IDEA89: AI Shopping Assistant for WooCommerce

Turn your WooCommerce storefront into a conversion machine. IDEA89 adds an AI-powered shopping assistant that answers product questions, recommends what to buy from your real catalogue, and adds it straight to the basket, in your brand voice.

**5-minute install. No theme changes. No dev work.**

[![WordPress](https://img.shields.io/badge/WordPress-6.4+-blue)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-96588a)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%E2%80%938.4-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)](LICENSE)

---

## What it does

| Feature | Description |
|---|---|
| **Smart product recommendations** | Shoppers ask in natural language, "something waterproof under £50", and the assistant finds it in your real catalogue |
| **Real-time catalogue sync** | Products, variations, attributes, prices, stock and reviews sync automatically via Action Scheduler. Out-of-stock items are never recommended |
| **Retrieved content, not prompt-stuffing** | Pages, categories, coupons and store info sync through the existing IDEA89 content lanes. Blog posts and any public custom post type sync through a dedicated, embedded document lane, so a large blog is answerable without blowing out the prompt |
| **Auto-detected FAQs** | Reads schema.org `FAQPage` markup (Yoast, Rank Math), native `<details>`/`<summary>` blocks and known FAQ plugin post types, with a settings screen showing exactly what was found |
| **WooCommerce-native add-to-cart** | Adds products (including variable-product variations) to the real WooCommerce cart via the Store API, with no custom cart and no theme override |
| **HPOS-compatible** | Declares compatibility explicitly, so it works whether High-Performance Order Storage is on or off |

## How it works

1. Install the plugin and activate it (WooCommerce 8.0+ must already be active).
2. Go to **IDEA89** in the WordPress admin menu and paste your API key from the [IDEA89 dashboard](https://app.idea89.com).
3. Tick **Enable assistant**, choose what to sync under Content Sync, and save.
4. Click **Sync now**, or let the background sync pick your catalogue up on its own schedule.
5. The chat widget appears on your storefront. Products sync automatically from then on.

Your catalogue is embedded for hybrid search on the IDEA89 side. When a shopper asks a question, the assistant searches products, checks live stock, and replies with product cards, prices and add-to-cart buttons, or answers from a page, coupon, or blog post when that's what the question needs.

**No data leaves your site until an API key is saved.** See the [external services disclosure in readme.txt](readme.txt) for exactly what's sent, when, and to which service. This is the wordpress.org-mandated disclosure and it is accurate, not boilerplate.

---

## Requirements

- WordPress 6.4 or later
- WooCommerce 8.0 or later (tested up to 11.0)
- PHP 7.4, 8.0, 8.1, 8.2, 8.3, or 8.4
- An IDEA89 account: [start your free trial](https://app.idea89.com/sign-up)

## Installation

Upload and activate like any WordPress plugin:

```
wp-content/plugins/idea89-assistant/
```

or via **Plugins > Add New > Upload Plugin** in wp-admin, using the release zip. No Composer step, no build step: the shipped zip is ready to run as-is.

If WooCommerce is missing or below the minimum version, the plugin deactivates itself with an admin notice rather than fatalling.

## Configuration

Everything lives under **IDEA89** in the WordPress admin menu, built on the WordPress Settings API.

### Connection

| Setting | Description |
|---|---|
| **Enable assistant** | Turn the storefront widget on/off |
| **API key** | Your key from the IDEA89 dashboard. Stored in `wp_options` with `autoload` off, gated behind `manage_options`, never exposed to REST or to any front-end script |
| **API URL** | Override for self-hosted or enterprise deployments. Leave blank for the default |
| **Test connection** | Verifies the key against the live API (not the unauthenticated health check, so a wrong key genuinely fails here) |
| **Sync now** | Queues a full catalogue and content sync immediately |

### Appearance

| Setting | Description |
|---|---|
| **Assistant name** | Shown in the widget header |
| **Position** | Bottom-right or bottom-left |
| **Brand colour** | Six-digit hex code for the widget header |
| **Store context** | Free text describing what the store sells, for general questions |

### Content sync

Every public post type on the site is listed with its own checkbox: posts, pages, and any custom post type the site happens to have. Products are excluded from this list; they always travel the catalogue lane. Toggles for categories, pages, store details and FAQs sit alongside it.

Unticking a type stops it being sent on **future** syncs. It does not retroactively remove content already indexed. See the FAQ in [readme.txt](readme.txt) for the full, honest answer on this.

## How syncing works

| Trigger | What happens |
|---|---|
| Product saved / created | Product is queued and synced in the background |
| Stock changed | A lightweight stock-only update is queued |
| Coupon saved | Active coupons resync |
| Post / page / custom post type saved | Queued for the document lane (or withdrawn, if it's no longer publicly eligible) |
| Post trashed / force-deleted | Withdrawn from the catalogue or document index |
| Daily reconcile | Full re-sync of catalogue, content, documents, FAQs and coupons, the safety net |

All of it runs through **Action Scheduler** (bundled with WooCommerce), never WP-Cron: WP-Cron is request-triggered, so a "daily" job on a low-traffic store might not run for days, and long batch jobs risk a PHP timeout mid-request. Action Scheduler gives a real queue with retries and progress visible under **WooCommerce > Status > Scheduled Actions**. For a catalogue paging 100 products at a time, that's the difference between "sync is broken" and "sync is on batch 14 of 60".

Every sync is idempotent: receiving the same product or document twice in a minute is normal and never duplicates or corrupts anything.

## The widget

A floating chat widget, loaded asynchronously so it never blocks the rest of the page:

- Understands your products, categories, coupons, pages and, for stores that opt in, blog content
- Product cards with images, prices, ratings and add-to-cart buttons
- Adds to the real WooCommerce cart via the Store API, including variable-product variations
- Mobile-responsive, with no theme or checkout changes

The widget script is served from the IDEA89 CDN, so no static widget assets are bundled into this plugin.

## Error handling

The plugin never breaks the storefront:

- Every API call goes through a single client that catches WordPress HTTP errors, checks the response code, and returns a plain boolean or shaped array. Failures are logged (behind `WP_DEBUG`) and swallowed, so nothing propagates to a page render.
- One bad product in a sync batch is skipped and logged; the rest of the batch still goes through.
- A scheduled job that throws is caught, logged unconditionally (not gated on `WP_DEBUG`, since that's off on every production store), and reported via `WooCommerce > Status > Scheduled Actions` as a failed action rather than silently vanishing.
- With no API key configured, every sync path is a no-op, and the plugin makes zero external HTTP calls.

## Uninstalling

Deactivating stops the widget and all syncing immediately. Deleting the plugin through wp-admin runs `uninstall.php`, which removes every option the plugin created and unschedules any pending Action Scheduler jobs. No custom database tables are created by this plugin. Everything else lives on the IDEA89 platform, and deleting it from your WordPress site does not delete it from your IDEA89 account.

## Support

- **Documentation:** [idea89.com](https://idea89.com)
- **Email:** support@idea89.com
- **Dashboard:** [app.idea89.com](https://app.idea89.com)

## License

Licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

Copyright 2026 4K Technologies Ltd.

---

Built by [4K Technologies](https://4ktechnologies.co.uk) in the UK.
