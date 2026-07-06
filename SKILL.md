---
name: woocommerce-ops-mcp
description: Guide for operating a WooCommerce store through the WooCommerce Ops MCP tool set (orders, inventory, products, reports, cart recovery, and sales/marketing tools). Use this skill whenever the user asks about their WooCommerce store's orders, refunds, stock levels, pricing, sales figures, abandoned carts, lapsed customers, flash sales, cross-sells, or advertising/traffic performance - even if they don't explicitly say "WooCommerce" or "MCP". Also use it when deciding which of the 22 available tools best fits a request, since many overlap (e.g. sales questions can be answered by daily_sales_summary, top_products_by_period, or find_pricing_opportunities depending on intent).
---

# WooCommerce Ops MCP

This skill helps an AI agent choose the right tool and follow the right safety
sequence when operating a WooCommerce store through the WooCommerce Ops MCP
server. The server exposes 22 tools across six groups: Orders, Inventory,
Products, Reports, Revenue Recovery, and Sales & Marketing.

## Core safety rule: dry-run first

Every tool that changes the store (refunds, price changes, stock updates,
order status changes, flash sales) supports a `dry_run` argument, defaulting
to `true` unless the store owner has changed that setting. When one of these
tools is called without an explicit `dry_run: false`:

1. It will NOT actually change anything.
2. It returns a preview of what *would* happen.
3. Always summarize that preview to the user in plain language and ask them
   to confirm before calling the tool again with `dry_run: false`.

Never set `dry_run: false` unless the user has clearly said yes/confirmed/go
ahead for that specific action in the current conversation. Do not carry a
confirmation forward to a different action.

## Tool groups and when to reach for them

**Orders** — `list_orders`, `get_order_details`, `update_order_status`,
`create_refund`, `add_order_note`. Use for anything about a specific order:
status checks, fulfillment, cancellations, refunds. `create_refund` is
blocked automatically if the amount exceeds the store's configured guardrail
- if blocked, tell the user plainly and suggest they raise the limit in
Ops MCP > Guardrails if they're sure.

**Inventory** — `check_stock_levels`, `bulk_update_stock`. Use for stock
questions or corrections. `check_stock_levels` accepts a `threshold` for
what counts as "low stock."

**Products** — `find_products`, `bulk_price_update`, `toggle_product_visibility`.
Use for catalog search, pricing changes, or publishing/hiding items.
`bulk_price_update` is blocked if the percentage change exceeds the
configured guardrail.

**Reports** — `daily_sales_summary`, `top_products_by_period`. Use for "how
much did I sell" or "what's selling well" questions. Prefer these over
guessing from memory - always call the tool rather than estimating numbers.

**Revenue Recovery** — `list_abandoned_carts`, `create_recovery_coupon`,
`send_cart_recovery_email`, `find_lapsed_customers`. Use when the user wants
to win back customers who didn't complete checkout or haven't ordered
recently. A typical flow: `list_abandoned_carts` → `create_recovery_coupon`
→ `send_cart_recovery_email` referencing the coupon code.

**Sales & Marketing** — `create_flash_sale`, `set_product_cross_sells`,
`find_pricing_opportunities`, `find_slow_moving_stock`,
`export_customer_audience`, `traffic_source_performance`. Use for growth
questions: running a sale, raising prices on proven bestsellers, clearing
slow stock, building an ad retargeting list, or evaluating which marketing
channels are working.

## Grounding rule

Tool results are always final and complete. Never say a result is "pending,"
"queued," or "will be available soon" - if a tool returns zero orders or
zero sales for a period, that IS the answer; state it plainly. Never invent
numbers that weren't in a tool's response.

## Picking between overlapping tools

- A vague "how am I doing?" question → `daily_sales_summary` first, then
  offer to check `check_stock_levels` or `find_pricing_opportunities` if the
  user wants more detail.
- "What should I put on sale?" → `find_slow_moving_stock` (clearance
  candidates) or `find_pricing_opportunities` (raise-price candidates,
  since these are proven bestsellers) depending on whether the user wants
  to move inventory or increase margin.
- "How do I get more sales?" or "how do I advertise?" → combine
  `traffic_source_performance` (what's already working) with
  `export_customer_audience` (retargeting existing customers) rather than
  giving generic marketing advice untethered from the store's real data.

## Sensitive data handling

`export_customer_audience` produces a CSV containing customer email
addresses. Always tell the user this file contains personal data, that it's
saved with a random filename, and that they should download it and delete
it from the server after uploading it to their ad platform.
