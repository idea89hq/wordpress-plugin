=== IDEA89 AI Shopping Assistant ===
Contributors: idea89hq
Tags: ai, chatbot, woocommerce, product recommendations, customer support
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI shopping assistant for WooCommerce. Answers product and policy questions, recommends products from your catalogue, and adds them to the basket.

== Description ==

IDEA89 adds a floating chat widget to your WooCommerce storefront. Shoppers ask it questions in plain language, "what's waterproof under £50?", "do you ship to Ireland?", "what's your return policy?", and it answers from your actual catalogue and site content, then adds the right product straight to the basket when asked.

The plugin's job is entirely on the WordPress side: it reads your store and keeps a copy of it in sync with the IDEA89 service, and it prints a small loader script that renders the chat widget on the storefront. All of the AI itself (understanding the question, searching your catalogue, generating the reply) runs on IDEA89's servers, not on your WordPress install. See **External services** below for exactly what that means for your data.

= What it syncs =

* **Products**: name, description, price, images, categories, attributes, variations, stock levels, and review excerpts
* **Categories**: so the assistant understands how your catalogue is organised
* **Pages**: About, shipping, returns, and other policy pages, so the assistant can answer from them
* **Coupons**: active, unexpired discount codes, so the assistant can mention live promotions
* **Store details**: store name, currency, and the context you provide in settings
* **FAQs**: auto-detected from your content (see Limitations below), with a settings screen to review what was found
* **Posts and other public content types**: blog posts and any custom post type you opt in, indexed and searched rather than dumped into every reply, so a large blog doesn't overwhelm the assistant

Sync runs in the background via Action Scheduler (bundled with WooCommerce), triggered by product, stock, coupon and content saves, plus a daily full reconcile. Nothing runs until you enter an API key, and nothing blocks a page save waiting on a network call. Every sync is queued and processed asynchronously.

= What it adds to the storefront =

A single, asynchronously-loaded script tag that renders the chat widget. It does not touch your theme, does not add page weight to the initial render, and adds products to the WooCommerce cart through the standard Store API, the same cart your theme already shows.

The chat panel carries a small "idea89" label in its footer, linking to idea89.com. This is part of the hosted assistant's own interface, the same way an embedded video player carries its provider's mark, and is rendered by the IDEA89 service, not injected into your theme or content. It appears only inside the chat panel, and only when a shopper opens it.

= What it does not do =

* It does not store your product catalogue, page content, or chat transcripts anywhere in your WordPress database. That data lives on the IDEA89 service (see External services).
* It does not modify your theme, checkout flow, or existing cart behaviour.
* It does not require a WordPress.com account, and it does not phone home for anything beyond what is documented below.

== External services ==

This plugin connects your store to the IDEA89 SaaS API at **api.idea89.com**, a third-party service operated by 4K Technologies Ltd, in order to power the chat assistant. This is a paid service; a plan and an API key from your [IDEA89 dashboard](https://app.idea89.com) are required for the plugin to do anything.

**Nothing is transmitted until you enter an API key and save it in Settings.** With no key configured, every sync is a no-op and the storefront widget does not load.

Once a key is configured, the following data is sent to api.idea89.com:

* **Catalogue data**: product names, descriptions, prices, images (URLs), categories, attributes, variations, stock levels, and review excerpts, sent when a product is saved, when stock changes, and on a daily reconcile.
* **Page and post content**: the text of WordPress pages and (if you opt in under Content Sync) posts and other public content types, sent when that content is saved or on the daily reconcile, so the assistant can answer questions from it.
* **Coupon codes**: active, unexpired coupon codes and their terms, so the assistant can mention live promotions.
* **Store details**: your store name, currency, and any store-context text you enter in Settings.
* **Shopper chat messages**: when a visitor uses the widget, their messages are sent directly from their browser to api.idea89.com to generate a reply. This traffic does not pass through your WordPress server.
* **Your site's domain and your API key**, sent with every request, so IDEA89 can identify which store the data belongs to.

The widget itself is served from api.idea89.com rather than bundled into the plugin, because the assistant's interface is part of the hosted service and is updated centrally. The plugin prints only the loader tag and your public configuration.

No data is sent for training third-party AI models on other customers' behalf, and the plugin never transmits WordPress user accounts, passwords, or payment details.

Full terms and privacy policy for the IDEA89 service: [Terms of Service](https://idea89.com/terms) and [Privacy Policy](https://idea89.com/privacy).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install it through the WordPress admin under Plugins > Add New > Upload Plugin.
2. Activate the plugin. WooCommerce 8.0 or later must already be installed and active. If it isn't, the plugin shows an admin notice and stays inactive rather than causing errors.
3. Go to **IDEA89** in the admin menu.
4. Paste the API key from your [IDEA89 dashboard](https://app.idea89.com) and save.
5. Tick **Enable assistant**, choose what to sync under Content Sync, and save again.
6. Click **Sync now** to push your catalogue immediately, or wait for the background sync to pick it up.
7. Visit your storefront. The chat widget appears in the corner you configured.

== Frequently Asked Questions ==

= Which PHP versions are supported? =

PHP 7.4 through 8.4. The plugin source is parsed under both a real PHP 7.4 and a real PHP 8.4 runtime before every release, and the test suite is run with deprecations, notices and warnings all treated as failures, so it stays clean on the newest PHP as well as the oldest supported one.


= Does this cost anything? =

The plugin itself is free. It connects to the IDEA89 SaaS service, which is a paid product with a free trial. See the **External services** section for what's sent and to which service, and https://idea89.com for current pricing.

= Will this slow down my site? =

The widget loads asynchronously in the footer, so it does not block the rest of the page from rendering. Catalogue and content sync run entirely in the background through Action Scheduler, and never run inline with a page request or an admin save.

= Does it work with variable products? =

Yes. Variations sync with their attributes, and the widget renders a variant picker before adding the selected variation to the cart via the WooCommerce Store API.

= Will it find every FAQ on my site? =

Not automatically. FAQ detection looks for three things, in order: schema.org `FAQPage` structured data (what Yoast SEO and Rank Math's FAQ blocks emit), native WordPress `<details>`/`<summary>` blocks, and a handful of known FAQ plugin post types. It does **not** read theme-specific accordions built from plain `<div>` markup with no structured data behind them, because guessing at arbitrary markup risks mangling a question or answer, which is worse than missing it. The Content Sync screen shows exactly what was detected, so you can see whether your FAQs were picked up, and add a small FAQ block or plugin if they weren't.

= What happens if I untick a content type? =

Unticking a content type (a post type, categories, pages, store details, or FAQs) under Content Sync stops it being sent on future syncs. It does **not** retroactively remove content that was already sent and indexed. That content can still be quoted to shoppers until you remove it directly (for example, by unpublishing or deleting the underlying page or post, which does withdraw it).

= Can I remove the "idea89" label from the chat widget? =

The label sits in the chat panel footer and is part of the hosted assistant's interface. Removing it is a white-label option, so ask us at support@idea89.com about your plan. The plugin itself adds no links, badges or credits anywhere else on your site, and nothing at all outside the chat panel.

= Does it store anything in my WordPress database? =

Only its own settings (your API key, enabled/disabled state, appearance and content-sync choices) as WordPress options, and nothing else. Your catalogue, page content and chat transcripts live on the IDEA89 service, not in your WordPress database. See **External services**.

= What happens if I deactivate or delete the plugin? =

Deactivating stops the widget and all syncing immediately. Deleting the plugin (via `uninstall.php`) removes every option it created and unschedules any pending Action Scheduler jobs. It does not delete data already synced to the IDEA89 service. Do that from your IDEA89 dashboard.

= Does it support HPOS (High-Performance Order Storage)? =

Yes, compatibility is declared explicitly, so the plugin works correctly with WooCommerce order tables enabled or disabled.

== Screenshots ==

1. Chat widget on the storefront, answering a product question and offering to add it to the basket.
2. IDEA89 admin settings: connection, appearance and content sync options.
3. Content Sync screen showing auto-detected FAQ sources.

== Changelog ==

= 1.0.2 =
* The plugin and author links in the plugin header now point to different pages, as WordPress requires: the plugin link goes to the plugin's own repository, the author link to idea89.com.

= 1.0.1 =
* The "WooCommerce required" notice now appears only on the dashboard and plugins screens, and can be dismissed, instead of showing on every admin page.
* Declares its WooCommerce dependency through the Requires Plugins header on WordPress 6.5 and later.
* Documents the assistant's footer label and why the widget is served from IDEA89 rather than bundled.

= 1.0.0 =
* Initial release. Catalogue, category, page, coupon, FAQ and content sync; storefront chat widget with WooCommerce Store API add-to-cart; admin settings with Test Connection and Sync Now.

== Upgrade Notice ==

= 1.0.2 =
Corrects the plugin header links. No functional change.

= 1.0.1 =
Admin housekeeping and clearer documentation. No changes to syncing or to the storefront widget.

= 1.0.0 =
Initial release.
