Jetonomy's frontend runs on the WordPress Interactivity API (`@wordpress/interactivity`). The store is keyed `'jetonomy'` and handles voting, navigation, bookmarks, moderation actions, and more. After every client-side page swap the store dispatches a `jetonomy:navigated` document event so companion scripts can re-initialize without duplicating nav logic. All REST calls go through a shared `window.jetonomyRest.restFetch` client.

This page explains how to extend the store, keep scripts alive across navigation, and call REST endpoints from your own JavaScript.

**Source references:**
- Store definition: `assets/js/view.js`
- `jetonomy:navigated` event: `assets/js/view.js:776`
- REST client: `assets/js/jetonomy-rest.js`
- Pagination hydration: `assets/js/pagination-hydrator.min.js`
- Interactivity standard: `docs/standards/frontend-interactivity.md`

---

## How the client-side router works

Every click inside `#jetonomy-app` is delegated to `actions.navigate`. The action decides whether the target URL is safe to swap client-side:

- **Most routes** - swapped inside `[data-wp-router-region="jetonomy/main"]` without a full reload.
- **Rich-editor routes** (`/s/{slug}/t/{slug}/` single topic, `/s/{slug}/new/` new post) - forced to full-page load because the composer and Prism.js bind on `DOMContentLoaded` and do not re-init on swap.

After each swap the navigate action dispatches `jetonomy:navigated` on `document`. Every companion script that targets region content must listen to this event; `DOMContentLoaded` alone is not reliable for region content.

---

## Extending the Interactivity API store

Import `store` from `@wordpress/interactivity` inside a script module and pass the `'jetonomy'` namespace to extend the existing store. New `state`, `actions`, and `callbacks` you add merge with the plugin's own entries; you do not replace them.

Register your module with `wp_enqueue_script_module` and declare `jetonomy-view` as a dependency so it loads after the core store.

```php
// In your plugin's PHP boot file:
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_script_module(
        'my-plugin-view',
        MY_PLUGIN_URL . 'assets/js/my-view.js',
        array( 'jetonomy-view' ),        // depends on the core store module
        MY_PLUGIN_VERSION
    );
} );
```

```js
// assets/js/my-view.js  (ES module - loaded as a script module)
import { store, getContext } from '@wordpress/interactivity';

const { state, actions } = store( 'jetonomy', {
    state: {
        // Extend the shared state with your own reactive keys.
        myPluginPanelOpen: false,
    },

    actions: {
        toggleMyPanel() {
            state.myPluginPanelOpen = ! state.myPluginPanelOpen;
        },
    },
} );
```

Then bind your action declaratively in a template (or a template override):

```html
<button
    data-wp-interactive="jetonomy"
    data-wp-on--click="actions.toggleMyPanel"
    data-wp-bind--aria-expanded="state.myPluginPanelOpen"
>
    Toggle
</button>
<div data-wp-bind--hidden="!state.myPluginPanelOpen">
    My plugin panel content
</div>
```

Declarative controls auto-hydrate after every client-side swap - no re-init code needed.

---

## Re-initializing after `jetonomy:navigated`

Classic (non-module) scripts that need to wire up DOM nodes in the router region must listen to `jetonomy:navigated` alongside the initial load. This event fires immediately after each content swap.

The pattern is: guard your init function so it only wires freshly-swapped nodes (idempotent), and bind it to both startup and navigation.

```js
// assets/js/my-classic-init.js
function initMyPlugin() {
    // Only target unwired nodes so re-running after navigation is safe.
    document.querySelectorAll( '.my-widget:not([data-my-wired])' ).forEach( function ( el ) {
        el.dataset.myWired = '1';
        el.addEventListener( 'click', function () {
            // handle click
        } );
    } );
}

// Startup - guard for cases where the DOM is already ready.
if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initMyPlugin );
} else {
    initMyPlugin();
}

// Re-run after every client-side navigation.
document.addEventListener( 'jetonomy:navigated', initMyPlugin );
```

The `jetonomy:navigated` event is a `CustomEvent` with `detail.href` set to the navigated URL:

```js
document.addEventListener( 'jetonomy:navigated', function ( event ) {
    console.log( 'navigated to', event.detail.href );
    initMyPlugin();
} );
```

> Do NOT use `DOMContentLoaded` alone for content inside the router region. It fires once on the initial page load and never again after a client-side swap.

---

## Complete worked example: "Quick-note" button that survives navigation

This example adds a per-post "Quick note" button to post cards (via a [template override](./03-template-overrides.md) or the `jetonomy_post_card_after_badges` hook) that opens a small text box and saves the note to the REST API.

### 1. Register the script module

```php
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_script_module(
        'my-quick-note',
        MY_PLUGIN_URL . 'assets/js/quick-note.js',
        array( 'jetonomy-view' ),
        MY_PLUGIN_VERSION
    );
} );
```

### 2. The script module (`quick-note.js`)

```js
import { store } from '@wordpress/interactivity';

store( 'jetonomy', {
    state: {
        quickNoteOpen: {},    // keyed by post ID
        quickNoteText: {},
    },

    actions: {
        toggleQuickNote() {
            const postId = this.context.postId;
            const current = state.quickNoteOpen[ postId ] ?? false;
            state.quickNoteOpen[ postId ] = ! current;
        },

        async saveQuickNote() {
            const postId = this.context.postId;
            const text   = state.quickNoteText[ postId ] ?? '';
            if ( ! text.trim() ) return;

            const result = await window.jetonomyRest.restFetch(
                '/my-plugin/notes/' + postId,
                {
                    method: 'POST',
                    body:   { note: text },
                }
            );

            if ( result.ok ) {
                state.quickNoteOpen[ postId ] = false;
            }
        },
    },
} );
```

### 3. Markup added via hook

```php
add_action( 'jetonomy_post_card_after_badges', function ( $post ) {
    printf(
        '<div data-wp-interactive="jetonomy" data-wp-context=\'{"postId": %d}\'>
            <button class="jt-btn jt-btn--ghost" data-wp-on--click="actions.toggleQuickNote">Note</button>
            <div data-wp-bind--hidden="!state.quickNoteOpen[context.postId]">
                <textarea data-wp-bind--value="state.quickNoteText[context.postId]"
                          data-wp-on--input="state.quickNoteText[context.postId] = event.target.value">
                </textarea>
                <button class="jt-btn jt-btn--primary" data-wp-on--click="actions.saveQuickNote">Save</button>
            </div>
        </div>',
        (int) $post->id
    );
}, 10, 1 );
```

Because both the state and the actions are declared in the `'jetonomy'` store, the Interactivity API automatically re-hydrates the directives after every client-side navigation - no `jetonomy:navigated` listener is needed here.

---

## Using `window.jetonomyRest.restFetch`

`window.jetonomyRest.restFetch` is a shared REST client available on every Jetonomy community page. It:

- Resolves the REST base URL and nonce from `window.jetonomyData`.
- Sends `credentials: 'same-origin'` and the `X-WP-Nonce` header on every request.
- JSON-encodes plain-object bodies and sets `Content-Type: application/json`.
- On `403 rest_cookie_invalid_nonce` responses, fetches `/jetonomy/v1/auth/nonce` to refresh the nonce and retries the original request automatically.
- Never throws - always resolves to `{ ok: boolean, status: number, data: any }`.

**Signature:**

```js
const result = await window.jetonomyRest.restFetch( path, options );
// path - string, e.g. '/posts/42/vote' or 'posts/42' (leading slash optional)
// options - fetch-compatible object: method, body, headers, etc.
// result - { ok, status, data }
```

**Reading example:**

```js
const result = await window.jetonomyRest.restFetch( '/spaces/12/posts?per_page=5' );
if ( result.ok ) {
    const posts = result.data.items;
}
```

**Mutation example:**

```js
const result = await window.jetonomyRest.restFetch( '/posts/42/vote', {
    method: 'POST',
    body:   { direction: 'up' },
} );
if ( ! result.ok ) {
    console.error( 'Vote failed', result.status, result.data );
}
```

---

## `window.jetonomyHydrateInteractive`

When you append new DOM nodes that contain `data-wp-on--click` directives (for example, via a "Load More" fetch), the Interactivity API does not automatically re-wire those nodes. Call `window.jetonomyHydrateInteractive( nodes )` with an array of the newly appended elements and the pagination hydrator will make their click handlers work.

```js
fetch( nextPageUrl )
    .then( r => r.text() )
    .then( html => {
        const doc      = new DOMParser().parseFromString( html, 'text/html' );
        const newItems = Array.from( doc.querySelectorAll( '.jt-row' ) );
        const list     = document.querySelector( '.jt-topics' );

        newItems.forEach( item => list.appendChild( item ) );

        if ( typeof window.jetonomyHydrateInteractive === 'function' ) {
            window.jetonomyHydrateInteractive( newItems );
        }
    } );
```

This is used internally by the Load More pagination handler (`assets/js/pagination-frontend.min.js`).

---

## Compliance checklist

Before shipping any frontend feature, verify against the [Frontend Interactivity Standard](../standards/frontend-interactivity.md):

- [ ] No `DOMContentLoaded`-only handler targeting region content without a `jetonomy:navigated` pair.
- [ ] No `wp_add_inline_script` / inline `<script>` driving region behavior.
- [ ] No raw `fetch()` calls - use `window.jetonomyRest.restFetch` instead.
- [ ] Interactive controls use `data-wp-on--*` store actions wherever possible.
- [ ] Verify the feature after a client-side navigation, not only after a full page load.

---

## What's next

- [Theming and Tokens](./16-theming-and-tokens.md) - CSS custom properties your JS can read
- [Extend the REST API](./18-extend-the-rest-api.md) - add REST endpoints your `restFetch` calls can hit
- [Hooks Reference](./02-hooks-reference.md) - PHP hooks for injecting markup into templates
