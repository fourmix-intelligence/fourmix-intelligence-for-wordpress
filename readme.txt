=== Fourmix Intelligence AI ===
Contributors: fourmix
Tags: ai, agent, woocommerce, search, customer-support
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bring Fourmix Intelligence customer assistance, site guidance, and content synchronization to WordPress and WooCommerce.

== Description ==

Add an AI concierge, site search, related-content guidance, product recommendations, frequently-bought-together suggestions, and a cart assistant with native blocks. Product features remain inactive when WooCommerce is unavailable.

Connection credentials remain on the WordPress server and are never exposed to site visitors. Published content and stable product descriptions are synchronized incrementally. Fast-changing inventory is checked in WooCommerce when a result is displayed instead of being repeatedly added to the search index.

The administration screens and customer-facing interface are provided in Japanese.

== Installation ==

1. Upload the release ZIP from Plugins > Add New Plugin > Upload Plugin.
2. Activate the plugin.
3. Open Settings > Fourmix Intelligence and enter the endpoint, connection token, and AI identifier.
4. Add the required blocks to posts, pages, product templates, or cart pages.

== Privacy ==

When visitors use AI guidance, their input, conversation identifier, current page URL and title, and related product identifiers may be sent to Fourmix Intelligence. When content synchronization is enabled, published content from the selected post types is sent after it changes. Site operators must explain the purpose, retention period, and contact details in their privacy policy.

== External services ==

This plugin connects to the Fourmix Intelligence API to provide the features configured by the site operator. Editing settings or blocks alone does not send content.

Fourmix Intelligence is provided by Fourmix Co., Ltd.

* Service provider: https://www.fourmix.co.jp/
* Privacy policy: https://techblog.fourmix.co.jp/pages/privacy-policy

Terms and retention periods depend on the site operator's Fourmix Intelligence agreement.

== Frequently Asked Questions ==

= Can I use it without WooCommerce? =

Yes. The AI concierge, site search, and related-content blocks remain available. Product-specific blocks are hidden.

= Is inventory continuously synchronized? =

No. Frequently changing inventory is not stored in the knowledge index. WordPress verifies the latest product state before rendering a product card.

= Can visitors see the connection token? =

No. WordPress communicates with Fourmix Intelligence on the server. For stricter management, credentials can be defined as constants in wp-config.php.
