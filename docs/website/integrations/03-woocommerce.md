Gate Jetonomy spaces by WooCommerce product purchase or active WooCommerce Subscription - so customers unlock discussion areas the moment they buy.

![Jetonomy admin settings for WooCommerce integration setup](../images/admin-settings.png)

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- Which WooCommerce products Jetonomy Pro supports as access gates
- How to set up an Access Rule tied to a product or subscription
- How access activates on purchase and revokes on refund or subscription expiry
- How to combine product gates with membership-level gates

## Supported Product Types

| Product Type | Supported | Notes |
|---|---|---|
| Simple product | Yes | Access granted on order complete, permanent |
| Variable product | Yes | Gate by parent product - any variation grants access |
| WooCommerce Subscriptions | Yes | Access active while subscription is active; revoked on pause, cancel, or expiry |
| Grouped product | No | Gate individual products within the group instead |

> **Note:** WooCommerce Subscriptions (the WooCommerce.com extension) is required for subscription-based gating. Simple product gating works with WooCommerce alone.

## Setting Up an Access Rule

1. Install and activate **Jetonomy Pro** and ensure WooCommerce is active.
2. Go to **Jetonomy → Spaces** and open the space you want to gate.
3. Click the **Access Rules** tab.
4. Click **Add Rule**.
5. Set **Rule Type** to **WooCommerce Product**.
6. Search for and select the product by name.
7. Set the action to **Grant**.
8. Save the space.

The Access Rule takes effect immediately. Members who have already purchased the product are granted access in the background within a few seconds of saving.

> **Tip:** You can add multiple product rules to a single space. Access is granted if the member has purchased any one of the listed products.

## Auto-Activate on Purchase

When a customer's order status reaches **Completed**, Jetonomy Pro adds them to any spaces that grant access on that product. They receive a Jetonomy notification welcoming them to the space.

If you use WooCommerce Subscriptions, Jetonomy Pro also listens to subscription status changes:

| Subscription Status | Access |
|---|---|
| Active | Granted |
| On-hold | Revoked |
| Cancelled | Revoked |
| Expired | Revoked |
| Pending-cancel | Retained until expiry date |

## Auto-Revoke on Refund

When an order is refunded, Jetonomy Pro removes the customer from any spaces gated to that product. The order status transition to **Refunded** triggers the revocation.

> **Note:** A partial refund does not revoke access. Only a full refund (entire order) triggers the remove. If you need partial-refund gating, use WooCommerce Subscriptions and cancel the subscription manually.

## Combining with Other Rules

WooCommerce rules stack with MemberPress, PMPro, and trust-level rules in the same space. A member gains access if they satisfy any Grant rule - regardless of which rule type it is.

Example: gate a "Premium VIP" space to either MemberPress VIP level OR a specific WooCommerce product purchase. Members who qualify through either path are both added automatically.

## Troubleshooting

**Rule Type dropdown does not show WooCommerce Product** - Confirm Jetonomy Pro is active and WooCommerce is active. Navigate to **Jetonomy → Extensions** and check that the WooCommerce integration is listed.

**Subscription product not gating correctly** - Confirm WooCommerce Subscriptions (by WooCommerce.com) is installed and active. WooCommerce Memberships (a separate product) is not required.

**Member not removed after refund** - Verify the order status actually moved to Refunded, not just to Cancelled or On-Hold. Only a full Refunded status triggers access revocation.

## What's Next?

Gate spaces by LearnDash course or group enrollment - so students get discussion access automatically when they enroll.

[LearnDash Integration →](04-learndash.md)
