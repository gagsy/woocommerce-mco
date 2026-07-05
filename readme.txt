=== WooCommerce Ops MCP ===
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later

Expose WooCommerce operations (orders, inventory, products, reports) as MCP tools
so an AI agent can run day-to-day store ops.

== Description ==

WooCommerce Ops MCP turns your store into an MCP server. Connect any MCP-compatible
AI client (Claude, etc.) using the endpoint URL and API key from WooCommerce > Ops MCP,
and let it:

* List and inspect orders
* Update order status, add notes
* Issue full/partial refunds (with a configurable max-amount guardrail)
* Check stock levels and bulk-update stock by SKU
* Search products and bulk-update prices by % or fixed amount (with a configurable max-change guardrail)
* Toggle product visibility
* Pull daily sales summaries and top-selling products

== Safety ==

All write operations support "dry run" mode (on by default) which returns a
preview instead of applying the change. Every write action is logged to a
dedicated audit table, viewable under WooCommerce > Ops MCP > Recent Activity.

== Installation ==

1. Upload and activate the plugin (requires WooCommerce active).
2. Go to WooCommerce > Ops MCP.
3. Click "Generate Key" to create your API key.
4. Add the endpoint URL + API key (as a Bearer token) to your MCP client config.

== Endpoint ==

POST {site_url}/wp-json/wc-ops-mcp/v1/mcp
Authorization: Bearer <your_api_key>
Content-Type: application/json

Implements JSON-RPC 2.0 methods: initialize, tools/list, tools/call
