=== IDEA89 AI Shopping Assistant ===
Contributors: idea89hq
Tags: ai, chatbot, woocommerce, product recommendations, customer support
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.3.0
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

= Order tracking (optional) =

Switch this on and shoppers can ask about an order in the chat instead of emailing you. They see status, items, delivery method and a tracking link where one exists.

Order details never reach IDEA89 or any AI provider. The chat panel asks your own site for them, from the shopper's browser, using the login they already have. Signed-in shoppers see their recent orders. Guests can look up a single order with its number and the email address used to place it, limited to four attempts an hour so the form cannot be used to probe for which orders exist. Tracking numbers are read from the official WooCommerce Shipment Tracking extension or AfterShip if you use either.

= Store finder (optional) =

Publishes a store finder page at a URL you choose, with a searchable map and your own header and footer around it. Headings, help text and the meta description are all editable, and each store is published as structured data so search engines can read its address and opening details. Your store list is managed in your IDEA89 dashboard. Availability depends on your plan.

= Personalization (optional) =

Lets the assistant recognise a returning customer so it can pick up where a conversation left off. Your site signs a short-lived token holding only a customer reference, a group reference and whether the shopper is signed in. No name, email address or order history is sent, the token expires after an hour, and it cannot be forged in the browser because the signing secret never leaves your server.

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

= 1.3.0 =
* **Global attributes are sent by name.** A global attribute (one created under Products > Attributes, such as Colour) was sent as its term ids, so the assistant read "12, 15" where your page shows "Sage, Rust". It now sends the term names.
* **Attributes with their labels and settings.** Each attribute is sent with the label your shop shows, its values, whether it is visible on the product page, and whether it is a global attribute your layered-navigation filters use, so the assistant can filter on it and confirm a shopper's requirement from it. Weight and dimensions go with your shop's units.
* **Tax basis, short description and category paths.** Whether your prices are entered with tax, the tax rate for the product's tax class at your shop's base location, the short description on its own, and each category with its parent categories.
* **Variations carry their own data.** Each variation's stock quantity, its own values (options, weight and dimensions where it sets its own), and a price on the same tax basis as the parent (previously the displayed price, which follows the shop's tax display setting). A variation's stock change is also sent as the variation's own stock.
* **Compatibility.** These are additions to the catalogue sync; an IDEA89 API that predates them ignores them. WooCommerce itself has no quantity (bulk) pricing, so none is sent.

= 1.2.5 =
* **The catalogue sync key is now part of setup.** Stores created in IDEA89 from 1 October 2026 need it before their catalogue will sync, so the setting is no longer marked optional. Create it in your IDEA89 dashboard under API & Domains and paste it under IDEA89 > Settings > Connection.
* **A refused sync is reported on the settings page.** Catalogue sync runs in the background, so when IDEA89 turns it away because the sync key is missing or out of date, the IDEA89 settings page now shows IDEA89's explanation. The sync stops after the first refused page instead of working through the whole catalogue, and the message clears on the next successful sync.
* **Test connection checks the sync key too.** It now asks IDEA89 whether a catalogue sync from this site would be accepted, with nothing written, and reports a missing or replaced sync key straight away.

= 1.2.4 =
* **Failed payments are never shown as a confirmed order.** In the in-chat native checkout, the payment gateway's result is now checked. If the gateway declines or fails the payment, the shopper sees the gateway's own message and no order confirmation.
* **The in-chat checkout panel confirms only paid orders.** It now reports success only for orders that are processing, completed or on hold. A failed or cancelled order is reported as failed, and a pending payment leaves the shopper on your order page.
* **Draft orders are hidden from order lookup.** Checkout drafts, auto-drafts and trashed orders can no longer be found through the assistant's order lookup.
* **Catalogue sync key.** New optional field under IDEA89 > Settings > Connection. Create the key in your IDEA89 dashboard under API & Domains and paste it here. Once a sync arrives with the key, IDEA89 accepts catalogue, price, offer and FAQ updates for your store only when they carry it. Leaving it empty keeps syncing exactly as before.
* **Agentic Commerce feed protection.** New optional Feed access key: when set, AI agents must send it to read your product feed. The feed now loads one page of products at a time instead of the whole catalogue, is limited to 60 requests a minute per visitor, and a protected feed is never cached publicly.

= 1.2.3 =
* **Storefront globals are JSON-encoded.** The __IDEA89_WC block (Store API URL, nonce, basket URL) that the assistant reads on boot is now written with wp_json_encode and the same hex-escaping as every other inline value, in place of esc_js, which is meant for attribute context. Same keys, same values; nothing changes for shoppers.
* **In-chat checkout page is printed, not buffered.** The stripped checkout page is now written the way a page template is: wp_head and wp_footer print themselves and every script element is printed by WordPress's own inline-script function, instead of the whole page being assembled into one string and echoed. Same page, same headers, same bridge messages.

= 1.2.2 =
* **Widget loader is now enqueued.** The storefront loader goes through wp_register_script / wp_enqueue_script with its configuration attached via wp_add_inline_script, instead of a script tag printed in the footer. Same script, same globals, same async behaviour; other plugins and themes can now see and filter it like any other enqueued script.
* **Hardened inline JSON.** Every value the plugin writes into an inline script (checkout configuration, store finder analytics settings, store JSON-LD, checkout bridge messages) is now JSON-encoded with the characters <, >, &, ' and " hex-escaped, so no configured or merchant-entered value can close the script element early. Structured data and scripts are printed through WordPress's own inline-script functions.

= 1.2.1 =
* **Assistant name now sets the name shoppers see.** The Assistant name field under Appearance used to save a value nothing read; the name above the conversation came from your IDEA89 account. It is now that same name, so setting it here sets what shoppers see and how the assistant refers to itself when asked. Stored in your IDEA89 account rather than in WordPress, so it is the same in both places.
* **Checkout display, shared with your IDEA89 dashboard.** New field under IDEA89 > Checkout experience. Full window (the default, and how the assistant has behaved so far) gives checkout the whole screen; In the chat keeps checkout inside the assistant panel alongside the conversation. Changing it in WordPress or in the dashboard changes it in both.
* **Personalization status is spelled out.** Personalization needs two switches on, one in WordPress and one in your IDEA89 dashboard. The settings screen now says which half is on and which is off, so a store where only one is on is no longer told nothing.

= 1.2.0 =
* **Checkout experience.** New setting under IDEA89 > Checkout experience, matching the Magento 2 module. Express handoff is the default: after adding to basket, the assistant shows a basket summary card in chat with one button straight to your checkout. Checkout in chat opens your real WooCommerce checkout inside the assistant panel, with your site's header, footer and navigation stripped away so it fits the panel cleanly. Your payment methods, your shipping rules, your extensions all run exactly as they do on your normal checkout page; IDEA89 never sees a card number. Works whether your checkout page uses the classic checkout shortcode or the WooCommerce Checkout block; a settings screen status line beside the mode select tells you which one your store uses. If your checkout page uses neither, the assistant sends shoppers to it directly instead of showing it in the chat. Native checkout (beta) is also a real, working rung: the assistant collects delivery details in the conversation and places the order itself, on payment methods you choose from an allowlist that is empty by default, so a freshly switched-on store places no orders at all until you explicitly select one. Offline methods (cash on delivery, bank transfer, cheque) are recommended. Existing stores keep today's behaviour unless this setting is changed.
* **Agentic Commerce Protocol product feed.** New setting under IDEA89 > Agentic Commerce, off by default. Publishes your catalogue (names, descriptions, prices, stock and page links) at a public web address in the Agentic Commerce Protocol shape, so assistants such as ChatGPT can find and recommend your products. Works alongside any checkout experience setting. Does not give anyone access to your orders, customers or payment details, and nothing is published until you turn it on.
* **Pinned checkout bar.** New "Pinned checkout bar" setting under IDEA89 > Checkout experience, on by default. Shows a full-width bar above the assistant's message box reading "Checkout, N items, total" whenever the shopper's basket has items. Tapping it goes through the same checkout mode you already chose above; it is simply a second, always-visible way to reach checkout.

= 1.1.1 =
* A WordPress install in a subfolder is now recognised as its own IDEA89 account, separate from a shop at the domain root. Test Connection names the site an API key belongs to, so a key pasted from the wrong site on the same domain is caught during setup rather than after a sync.

= 1.1.0 =
* **Order tracking.** Shoppers can ask "where is my order?" in the chat and see their own order status, delivery progress and tracking links. Order details are read by the shopper's browser directly from your site and are never sent to IDEA89 or to any AI provider. Signed-in shoppers see their recent orders; guests can look up one order with its number and the email used to place it. Off by default.
* **Store finder page.** Publishes a searchable map of your stores at a URL you choose, styled to your brand and using your own theme's header and footer, with structured data so search engines can read your store details. Off by default.
* **Shopper personalization.** Lets the assistant recognise a returning customer, using a short-lived signed token that carries no name, email address or order history. Off by default.
* Dashboard settings such as map provider and brand colour are now read from your IDEA89 account, so they are set once for every storefront you run.

= 1.0.3 =
* Text domain now matches the plugin slug, so WordPress can load translations for the plugin.
* Removed the redundant load_plugin_textdomain() call.

= 1.0.2 =
* The plugin and author links in the plugin header now point to different pages, as WordPress requires: the plugin link goes to the plugin's own repository, the author link to idea89.com.

= 1.0.1 =
* The "WooCommerce required" notice now appears only on the dashboard and plugins screens, and can be dismissed, instead of showing on every admin page.
* Declares its WooCommerce dependency through the Requires Plugins header on WordPress 6.5 and later.
* Documents the assistant's footer label and why the widget is served from IDEA89 rather than bundled.

= 1.0.0 =
* Initial release. Catalogue, category, page, coupon, FAQ and content sync; storefront chat widget with WooCommerce Store API add-to-cart; admin settings with Test Connection and Sync Now.

== Upgrade Notice ==

= 1.2.5 =
Test connection now checks your catalogue sync key, and a sync refused because of the key is reported on the settings page instead of failing silently. New IDEA89 stores need the sync key before their catalogue will sync.

= 1.2.4 =
Checkout safety and security update: failed payments are never shown as confirmed orders, draft orders are hidden from order lookup, and new optional Catalogue sync key and Feed access key settings protect your catalogue and product feed. Nothing changes until you set the new keys.

= 1.2.3 =
Security hardening only: the storefront globals are JSON-encoded and the in-chat checkout page is printed through WordPress's own functions. No settings or behaviour change.

= 1.2.2 =
Security hardening: the widget loader is enqueued through WordPress and every value written into an inline script is hex-escaped. No settings or behaviour change.

= 1.2.1 =
The Assistant name field now sets the name shoppers see, a new Checkout display setting is shared with your IDEA89 dashboard (default: Full window, unchanged), and the settings screen shows whether both halves of personalization are on. Nothing changes on upgrade unless you edit one of these.

= 1.2.0 =
Adds the Assistant checkout mode setting (default: Express handoff), an Agentic Commerce product feed setting (default: off), and a pinned checkout bar above the message box (default: on, only when the basket has items). Only the bar changes on upgrade unless you switch the others on.

= 1.1.1 =
Recognises a subfolder install as its own account and names the connected site when you test your key. No change if your shop is at the domain root.

= 1.1.0 =
Adds order tracking, a store finder page and shopper personalization. All three are off until you switch them on, so nothing changes on upgrade.

= 1.0.3 =
Translation loading fix. No changes to syncing or to the storefront widget.

= 1.0.2 =
Corrects the plugin header links. No functional change.

= 1.0.1 =
Admin housekeeping and clearer documentation. No changes to syncing or to the storefront widget.

= 1.0.0 =
Initial release.
