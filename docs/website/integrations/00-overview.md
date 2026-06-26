Jetonomy connects to the membership, course, and community plugins you already run, so a member's access to a discussion space follows their subscription, course enrolment, or group membership automatically. This page is the map of every integration - what each one does, and whether it is in the free plugin or needs Jetonomy Pro.

## What You Will Learn

- Which integrations gate space access (membership and LMS) versus connect two communities (coexistence)
- Which integrations are in the free plugin and which require Jetonomy Pro
- Where to go next for each one

## Access-gating integrations

These connect an external "who has paid / who is enrolled" system to Jetonomy's **Access Rules**, so members are added to (and removed from) spaces automatically. They all follow the same Access Rules flow, walked through step by step in the [MemberPress guide](01-memberpress.md#setting-up-an-access-rule).

| Integration | Free or Pro | What it gates a space by |
|---|---|---|
| [MemberPress](01-memberpress.md) | **Free** | MemberPress membership level |
| [Paid Memberships Pro](02-pmpro.md) | **Free** | PMPro subscription level |
| [WooCommerce](03-woocommerce.md) | Pro | WooCommerce Memberships plan or Subscription product |
| [Restrict Content Pro](05-rcp.md) | Pro | RCP subscription level |
| [LearnDash](04-learndash.md) | Pro | LearnDash course or group enrolment |
| [Tutor LMS](08-tutor-lms.md) | Pro | Tutor course enrolment |
| [LifterLMS](09-lifterlms.md) | Pro | LifterLMS course or membership enrolment |
| [Sensei LMS](10-sensei-lms.md) | Pro | Sensei course enrolment |
| [MasterStudy LMS](11-masterstudy-lms.md) | Pro | MasterStudy course enrolment |
| WP Fusion | **Pro** | WP Fusion CRM tag |
| SureMembers | **Pro** | SureMembers access group |

> **WP Fusion and SureMembers setup.** Gate a space by a WP Fusion tag or a SureMembers access group using the **Access Rules** tab on the space editor. Both integrations are detected automatically when the third-party plugin is active. Full setup instructions are in the Jetonomy Pro documentation.

> **Tip:** Access Rules from different integrations stack. You can gate one space to "MemberPress VIP **or** a specific WooCommerce product **or** a LearnDash course" - a member who matches any rule gets in.

## Coexistence integrations

These do not gate access by purchase. Instead they make Jetonomy and another community plugin feel like one product - shared members, paired spaces, cross-links, and (where supported) activity broadcast. All are in the free plugin and turn on automatically when the other plugin is active.

| Integration | Free or Pro | What it connects |
|---|---|---|
| [BuddyNext](06-buddynext.md) | **Free** | Shares BuddyNext's design tokens and lets BuddyNext own the page header and navigation |
| [BuddyPress](13-buddypress.md) | **Free** | Pairs BuddyPress groups with forum spaces; two-way member sync, group Forum tab, activity broadcast |
| [FluentCommunity](12-fluent-community.md) | **Free** | Pairs FluentCommunity spaces with Jetonomy spaces; add-only member sync, cross-profile links, activity broadcast |

## Theme compatibility

| Integration | Free or Pro | What it does |
|---|---|---|
| [Theme Compatibility](07-theme-compatibility.md) | **Free** | Adapts Jetonomy to any WordPress theme via CSS custom properties, with full template overrides |

## Where to Start

- Running a paid community on MemberPress or Paid Memberships Pro? Start with [MemberPress](01-memberpress.md) - it is the canonical Access Rules walkthrough every other gating guide links back to.
- Selling courses? Go to the lead LMS guide, [LearnDash](04-learndash.md), then the matching guide for your LMS.
- Already on BuddyPress or FluentCommunity? Open the [BuddyPress](13-buddypress.md) or [FluentCommunity](12-fluent-community.md) guide - nothing to configure to switch the integration on.
