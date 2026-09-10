---
title: Uncanny Automator
description: Use Uncanny Automator recipes to add members to spaces, remove them, and change their space role - and to trigger a recipe when someone's role changes.
order: 17
---

# Uncanny Automator

Jetonomy Pro registers itself as an Uncanny Automator integration, so you can wire community membership into any recipe Automator can build - without writing code.

This is useful when the thing that should grant space access is not a membership plugin Jetonomy already integrates with. A form submission, a course completion, a purchase in a plugin with no adapter, a webhook from another system: if Automator can see it, it can put the member in a space.

**Requires:** Jetonomy Pro and Uncanny Automator, both active. The integration appears in Automator automatically when both are present. Nothing to enable in Jetonomy - it is not in the Extensions list, because it is an integration rather than an extension.

## Actions

Three actions, all operating on a space you pick when you build the recipe.

| Action | What it does |
|---|---|
| **Add the user to a space** | Joins the member to the chosen space. Bypasses join requests and approval - the recipe is the approval. |
| **Remove the user from a space** | Removes their membership. Their existing posts and replies stay where they are. |
| **Change the user's role in a space** | Sets them to member, moderator or admin in that space. |

The role action respects the same guards as the admin screen: it will not remove the last admin from a space.

## Trigger

| Trigger | Fires when |
|---|---|
| **A user's role in a space changes** | Someone's space role changes to or from member, moderator or admin - however the change was made. |

The trigger fires regardless of *how* the role changed: through the admin screen, the REST API, another Automator recipe, or an integration adapter reacting to a membership change. That makes it a reliable hook for "when someone becomes a moderator, do X" without having to catch every path separately.

## Example recipes

**Course completion grants space access.** *When* a user completes a LearnDash course, *then* add the user to the "Graduates" space. Useful when you want completion rather than enrolment to be the gate - Jetonomy's own LearnDash adapter grants on enrolment.

**Form submission joins a private space.** *When* a user submits your application form, *then* add the user to the "Members" space. The form is the join request, so the space itself can stay invite-only.

**New moderator gets onboarded.** *When* a user's role in a space changes to moderator, *then* send them your moderation guidelines email and add them to your team Slack. This is the trigger doing work the admin screen cannot.

**Lapsed member loses access.** *When* a subscription expires in a plugin Jetonomy has no adapter for, *then* remove the user from the space.

## When to use this instead of an access rule

Jetonomy's own [access rules](../spaces-and-categories/04-space-settings.md) already grant space access from a membership level, role or tag, and they are the better tool when one exists for your plugin: they are evaluated continuously, so access is correct even if an event was missed.

Automator is the right choice when:

- The granting system has no Jetonomy adapter
- Access should follow an **event** rather than a **state** - completion rather than enrolment, first purchase rather than active subscription
- You need something to happen *besides* the membership change: an email, a tag, a webhook

The trade-off matters: an access rule keeps re-evaluating, so a member whose membership lapses loses access automatically. An Automator recipe fires once, so if you grant with a recipe you should revoke with one too.

## Troubleshooting

**The Jetonomy integration does not appear in Automator.** Both plugins must be active. Jetonomy Pro registers on Automator's own `automator_add_integration` hook, so if Automator is inactive nothing registers and there is no error - the integration is simply absent.

**An action ran but the member is not in the space.** Check the recipe targeted the right space; the space is chosen per-action, and a renamed space keeps its id. Then check the member is not banned, since a banned account cannot be added.

**The role trigger did not fire.** It fires on actual role changes only. Re-saving a member at the role they already hold changes nothing and correctly fires nothing.
