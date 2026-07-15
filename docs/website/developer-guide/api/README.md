# Jetonomy REST API Docs

Two files, generated from the 1.8.0 source (free) and the current Pro source (extensions read directly, cross-checked against `jetonomy-pro/audit/manifest.json`):

- **`openapi.json`** — OpenAPI 3.1 spec. Authoritative machine-readable contract: every route, method, path/query/body parameter, permission requirement, and response shape, built by reading the actual `register_rest_route()` calls in `includes/api/*.php` (free) and `includes/extensions/*/class-*.php` (Pro) — not inferred from the manifest or the changelog. Valid JSON (checked by manual structural review of every join point; this environment had no code-execution tool available to run `python3 -m json.tool`, so run that once before publishing as a final sanity pass).
- **`rest-api-reference.md`** — Human-readable companion: auth model, pagination, error shape, and a route table per resource with a couple of worked `curl` examples. Cross-links back to `openapi.json` for exact schemas.

## How to use these

- **Render `openapi.json`** with [Swagger UI](https://swagger.io/tools/swagger-ui/) or [Redoc](https://github.com/Redocly/redoc) for an interactive browser (`npx @redocly/cli preview-docs openapi.json` or drop it into `https://editor.swagger.io`).
- **Two servers are declared**: `jetonomy/v1` (free core + most Pro extensions) and `jetonomy-pro/v1` (Pro's Attachments extension + the Site Announcements admin routes only — confirmed by reading the source directly, not documented anywhere else). Per-operation `servers` overrides are set on the handful of paths that live on the second namespace.
- **Everything here is generated from source**, not hand-maintained separately. When routes change, regenerate rather than hand-editing — re-run the same `register_rest_route()` read-through described in `openapi.json`'s `info.description`.

## Scope and known gaps

- **Free (`jetonomy/v1` core, 15 controllers)**: fully verified — every controller file in `includes/api/` was read directly. 73 route+method combinations documented (see the parent task's final report for the exact breakdown).
- **Pro**: the core extensions used on most installs — Attachments, Reactions, Polls, Private Messaging, Custom Fields, Custom Badges, Webhooks, Anonymous Posting reveal, Site Announcements, Web Push (partial) — were read directly from `register_routes()`. The remaining extensions (Analytics, AI, Advanced Moderation, Email Digest, SEO Pro, Reply By Email, White Label) are documented from `jetonomy-pro/audit/manifest.json`, which is reconciled against source as recently as 2026-07-05 ("Wave E") and matched every extension we did spot-check exactly — but their exact request-body schemas were not independently re-derived from source in this pass. If you touch one of those extensions, verify its `openapi.json` `requestBody` against the live `register_routes()` before relying on it for codegen.
- **Namespace correction found during this pass**: the Pro manifest's header note ("site-announcements registers under jetonomy-pro/v1 - the lone outlier") is incomplete — the **Attachments** extension also registers under `jetonomy-pro/v1` (confirmed in `includes/extensions/attachments/class-rest.php`). `openapi.json` reflects the corrected reality; consider fixing the manifest note too.

## Not our job

This team does **not** publish, sync, or upload these files anywhere (no `docs.wbcomdesigns.com`, no live site). They live in the repo under `docs/website/developer-guide/api/` for whichever team owns the next step to pick up and deploy to jetonomy.org.
