---
title: "Restrict a Space to Paying Members"
category: "how-to"
order: 3
---

# Restrict a Space to Paying Members

Use this guide when a space should open only for people who hold a plan, course or product you sell. Access follows the plan. It starts when the plan is active and ends when it lapses. You do nothing by hand.

## What you will set up

- A space that only plan holders can read and post in
- An **Access Rule** that links the space to your plan
- A "get the plan" message for people who do not have it yet

## Before you start

You need a membership or course plugin that Jetonomy supports.

| Plugin | Needs |
|---|---|
| MemberPress | Free Jetonomy |
| Paid Memberships Pro | Free Jetonomy |
| WooCommerce Memberships or WooCommerce Subscriptions | Jetonomy Pro |
| Restrict Content Pro | Jetonomy Pro |
| LearnDash, Tutor LMS, LifterLMS, Sensei, MasterStudy, Learnomy | Jetonomy Pro |

WP Fusion, SureMembers and Uncanny Automator also need Pro and have their own guides. See [Integrations overview](../integrations/00-overview.md).

You also need:

- The plan already created in your membership plugin
- A test account with the plan, and one without it
- A space to lock. Create it first if needed: [Launch a Support Forum](01-launch-a-support-forum.md) shows the steps.

> **Note:** No membership plugin? You can still gate by **WP Role**, **Capability** or **Trust Level** in the same screen. Those rules need no extra plugin.

## Step 1: Make the space private and invite-only

An access rule only lets people **in**. It never locks anyone out. The lock comes from the space settings, so set them first.

1. Go to **Jetonomy → Spaces** and click **Edit** under the space.
2. On the **General** tab, set **Visibility** to **Private**.
3. Set **Join Policy** to **Invite Only**.
4. Click **Update Space**.

Without this step the rule has no effect. Jetonomy warns you on the next tab: "This rule is not restricting anyone yet."

## Step 2: Add the access rule

1. Open the **Access Rules** tab of the same space.
2. In the **Add Access Rule** row, open the first dropdown and pick your plugin, for example **MemberPress** or **PMPro**. Pro adds more, such as **WooCommerce**, **RCP** and **LearnDash**.
3. In the search box that appears, type the plan or course name and pick it. WooCommerce entries end in "(WC Membership)" or "(WC Subscription)".
4. Leave the **Grants** dropdown on **Participate**. People with the plan can then read, post, reply, vote and report.
5. Click **Add Rule**.

A sentence under the form reads your rule back in plain English. The rule then appears under **Access Rules** at the bottom of the page.

| Grants | What a plan holder can do |
|---|---|
| **Read** | Read only |
| **Participate** | Read, post, reply, vote and report |
| **Full** | Same as Participate for ordinary members |

A rule can never make someone a moderator. To appoint one, use the **Members** tab. For the details, see [Access level](../integrations/01-memberpress.md#access-level).

## Step 3: Add more plans (optional)

Add another rule for each plan that should open the space. A person who matches any rule gets in. You can mix plugins, for example a MemberPress level or a LearnDash course.

## Step 4: Leave Who Can Post alone

Open the **Settings** tab and keep **Who Can Post** on **Anyone who can see it (no restriction)**. Plan holders are admitted by the rule but are not on the Members list, so **Members Only** would turn them away.

If you want plan holders on the Members list, click **Sync Members** next to the rule. It is a one-time snapshot. It does not remove people when a plan lapses. Their access still ends correctly without it.

## Check that it works

1. As a guest, open the space address. You see "This space is private. Please log in to request access." and a **Log In** button.
2. Sign in as the test account **without** the plan. You see "This space is included with" followed by the plan name. A **Get** button for that plan appears when your plugin has a sales page for it. If it has none, the message says to ask a site administrator.
3. Sign in as the test account **with** the plan. The space opens and **+ New Topic** works.
4. Cancel or expire that test plan in your membership plugin and reload the space. The lock returns on the next page load. Earlier posts stay in place.

## Common questions

**Do I need to sync or remove anyone when a plan ends?**
No. Access is checked each time someone opens the space.

**Can people without the plan still see the space name?**
With **Private**, signed-in people see it in lists and get the "included with" message. With **Hidden**, they see "Space not found." instead, so they never get the **Get** button. Plan holders also will not see a hidden space in lists until you use **Sync Members**. Most owners choose **Private**.

**Why does the Private plus Open combination not work?**
**Open** lets any signed-in person join without the plan. Use **Invite Only**.

**The Get button is missing.**
Roles, capabilities, trust levels, CRM tags and access groups are not things a visitor can buy, so no button is shown. A WooCommerce plan sold through several products also shows none.

## Related guides

- [MemberPress](../integrations/01-memberpress.md)
- [Paid Memberships Pro](../integrations/02-pmpro.md)
- [WooCommerce](../integrations/03-woocommerce.md)
- [LearnDash](../integrations/04-learndash.md)
- [Membership & Join Policies](../spaces-and-categories/03-membership-policies.md)
- [Custom Access Logic](../developer-guide/28-custom-access-logic.md)
