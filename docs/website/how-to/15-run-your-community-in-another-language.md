---
title: "Run Your Community in Another Language"
category: "how-to"
order: 15
---

# Run Your Community in Another Language

This guide is for community owners whose site is not in English, or whose members pick their own language. You will see which languages Jetonomy ships, how members get their own language, and the few places that stay in one language for now.

## Languages included

Jetonomy and Jetonomy Pro ship translations for German, French, Spanish, Italian, Dutch, Brazilian Portuguese, Russian, Japanese, Korean and Simplified Chinese. They load on their own when your site language (**Settings > General > Site Language**) or a member's profile language is one of these.

## How members get their own language

WordPress lets each member choose a language on their profile (**Users > Profile > Language**). Jetonomy follows it: menus, buttons, messages and emails sent to that member use their language, and the site language is used for visitors.

## Add or change a translation

Use a translation plugin such as Loco Translate, or place your own `jetonomy-{locale}.po` / `.mo` files (and `jetonomy-pro-{locale}` for Pro) in `wp-content/languages/plugins/`. Files there survive plugin updates.

## Known limitations

These stay in one language in this version and are planned for a later release:

- **Notification text is written in the language of the member who caused it.** A notification such as "Alex replied to your topic" is stored as a sentence when it is created, so a member reading in German sees it in the language Alex's request ran in. Notification emails put their subject, layout and buttons in the recipient's language, but the notification sentence inside them has the same limit.
- **AI features work in English.** The prompts Jetonomy Pro sends to your AI provider and the error messages from AI features are English only. Summaries and suggestions usually follow the language of the topic, but this is not guaranteed.
