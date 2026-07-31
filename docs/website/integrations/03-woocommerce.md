Gate Jetonomy spaces by WooCommerce Membership plan or active WooCommerce Subscription - so customers get access to discussion areas as soon as their membership or subscription becomes active.

![The Jetonomy admin settings screen](../images/admin-settings.png)

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- Which WooCommerce levels Jetonomy Pro supports as access gates
- How to set up an Access Rule tied to a membership plan or subscription
- How access activates and revokes on membership and subscription status changes
- How to combine WooCommerce gates with membership-level gates

## Supported Gate Types

The WooCommerce adapter activates only when WooCommerce is active **and** either WooCommerce Memberships **or** WooCommerce Subscriptions is also active. What you can select as a gate is a Membership plan or a Subscription product - not a plain Simple or Variable product.

| Gate Type | Supported | Notes |
|---|---|---|
| WooCommerce Memberships plan | Yes | Access tracks the membership status (active/paused/cancelled) |
| WooCommerce Subscription product | Yes | Access active while the subscription is active; revoked on hold, cancel, or expiry |
| Variable-subscription product | Yes | Selectable like any other subscription product |
| Plain Simple product | No | Use a WooCommerce Memberships plan tied to that product instead |
| Plain Variable product | No | Use a WooCommerce Memberships plan instead |
| Grouped product | No | Gate via a Membership plan instead |

> **Note:** Either WooCommerce Memberships **or** WooCommerce Subscriptions (both WooCommerce.com extensions) is required. WooCommerce on its own does not enable space gating - the adapter stays inactive until one of the two is present.

## Setting Up an Access Rule

1. Install and activate **Jetonomy Pro**, ensure WooCommerce is active, and ensure WooCommerce Memberships or WooCommerce Subscriptions is active.
2. Go to **Jetonomy → Spaces** and open the space you want to gate.
3. Click the **Access Rules** tab.
4. Set **Rule Type** to **WooCommerce**.
5. Pick the Membership plan or Subscription product in the **Value** field.
6. Set the **Access level** to **Participate** - the default, and the right answer for a standard gated space.
7. Click **Add Rule**.

The Access Rule takes effect immediately. Members who already hold the membership or active subscription can open the space right away - access is worked out when they visit, so there is no background job to wait for.

> **Tip:** You can add multiple WooCommerce rules to a single space. Access is granted if the member matches any one of the listed memberships or subscriptions. For what each **Access level** means, see [Access level](01-memberpress.md#access-level).

## Auto-Activate

Jetonomy Pro listens to WooCommerce Memberships and WooCommerce Subscriptions status changes - not to raw order status transitions.

For WooCommerce Memberships, a member whose membership is active can open any space that grants access on that plan, from their next page load onward.

For WooCommerce Subscriptions, access tracks the subscription status:

| Subscription Status | Access |
|---|---|
| Active | Granted |
| On-hold | Revoked |
| Cancelled | Revoked |
| Expired | Revoked |

## Auto-Revoke

Access is revoked through the same status hooks. A WooCommerce Membership moving out of active status, or a subscription moving to on-hold, cancelled, or expired, ends access to any space gated exclusively to that level - on their very next page load, with nothing to synchronise. Their posts and replies stay where they are.

> **Note:** Revocation is driven by membership and subscription status changes, not by order Refunded transitions. Refunding a one-off order does not by itself revoke access - the gating membership or subscription has to change status.

## Combining with Other Rules

WooCommerce rules stack with MemberPress, PMPro, and trust-level rules in the same space. A member gains access if they satisfy any rule - regardless of which rule type it is.

Example: gate a "Premium VIP" space to either MemberPress VIP level OR a specific WooCommerce product purchase. Members who qualify through either path are both added automatically.

## Troubleshooting

**Rule Type dropdown does not show WooCommerce** - Confirm Jetonomy Pro is active, WooCommerce is active, and WooCommerce Memberships or WooCommerce Subscriptions is active. The adapter stays inactive (and the rule type is hidden) until one of those two extensions is present.

**No plans or products to select** - The dropdown lists WooCommerce Memberships plans and Subscription (including variable-subscription) products only. If it is empty, create a Membership plan or a Subscription product first. Plain Simple and Variable products are not selectable.

**Member not removed after a refund** - Access is tied to the gating membership or subscription status, not the order. Verify the WooCommerce Membership moved out of active status, or the subscription moved to on-hold, cancelled, or expired. A refunded order alone does not revoke access.

## What's Next?

Gate spaces by LearnDash course or group enrollment - so students get discussion access automatically when they enroll.

[LearnDash Integration →](04-learndash.md)
