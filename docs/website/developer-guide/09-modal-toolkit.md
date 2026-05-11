Jetonomy ships a small JavaScript toolkit that replaces native `window.confirm`, `window.alert`, and `window.prompt` with branded, accessible modal dialogs. The toolkit lives in `assets/js/jetonomy-modals.js` and is enqueued on every community page and inside wp-admin.

**Globals:** `window.jetonomyConfirm`, `window.jetonomyAlert`, `window.jetonomyPrompt`
**Localisation contract:** `window.jetonomyModalsI18n` (added in 1.4.2)
**Source:** `assets/js/jetonomy-modals.js`

---

## Why the toolkit exists

Native browser dialogs are ugly, untranslatable, blocking, not styleable, and inconsistent across browsers - some hide them, some show "Prevent this page from creating additional dialogs" checkboxes, mobile Safari renders them at the bottom of the viewport. They also bypass any focus-management or screen-reader contract that the rest of the community UI follows.

The toolkit gives you:

- A consistent visual language that matches Jetonomy's `--jt-*` token system
- Promise-based async / await syntax instead of blocking calls
- Keyboard support - ESC dismisses, Enter confirms (or commits a single-line prompt)
- Backdrop click to cancel
- Focus trapping while the modal is open, focus restoration on close
- Built-in translatable labels via `window.jetonomyModalsI18n`

**Every custom JS that ships in the Jetonomy ecosystem should use these globals instead of native dialogs.** That includes Pro extensions, theme bridge plugins, and third-party integrations that hook into community pages.

---

## API Reference

### `jetonomyConfirm( message, opts? )`

Asks the user a yes/no question. Resolves `true` when the user confirms, `false` when they cancel, press ESC, or click the backdrop.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `message` | `string` | The body text shown inside the modal |
| `opts.title` | `string?` | Heading text. Omit for a body-only modal |
| `opts.confirmLabel` | `string?` | Confirm button label. Defaults to the localised "Confirm" string |
| `opts.cancelLabel` | `string?` | Cancel button label. Defaults to the localised "Cancel" string |
| `opts.danger` | `boolean?` | When `true`, the confirm button uses the danger style (red). Use for destructive actions |

**Returns:** `Promise<boolean>`

**Example:**

```javascript
const proceed = await window.jetonomyConfirm(
    'Delete this post? This cannot be undone.',
    {
        title:        'Delete post',
        confirmLabel: 'Delete',
        cancelLabel:  'Keep it',
        danger:       true,
    }
);

if ( ! proceed ) {
    return;
}
await myPlugin.deletePost( postId );
```

---

### `jetonomyAlert( message, opts? )`

Shows a message and waits for the user to dismiss it. Resolves `true` once the user clicks the confirm button, presses Enter, presses ESC, or clicks the backdrop.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `message` | `string` | The body text shown inside the modal |
| `opts.title` | `string?` | Heading text |
| `opts.confirmLabel` | `string?` | Confirm button label. Defaults to `"OK"` |

**Returns:** `Promise<true>`

**Example:**

```javascript
await window.jetonomyAlert(
    'Your changes have been saved.',
    { title: 'Saved' }
);

// Code here runs after the user dismisses the dialog.
location.reload();
```

---

### `jetonomyPrompt( message, opts? )`

Asks the user for a string input. Resolves the submitted string on submit, or `null` on cancel / ESC / backdrop click.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `message` | `string` | The body text shown above the input field |
| `opts.title` | `string?` | Heading text |
| `opts.placeholder` | `string?` | Placeholder text shown inside the empty input |
| `opts.defaultValue` | `string?` | Pre-filled value. The input is auto-selected on open so the user can overwrite or accept it |
| `opts.multiline` | `boolean?` | When `true`, renders a `<textarea>` instead of an `<input type="text">` |
| `opts.confirmLabel` | `string?` | Submit button label. Defaults to `"Submit"` |
| `opts.cancelLabel` | `string?` | Cancel button label. Defaults to `"Cancel"` |

**Returns:** `Promise<string|null>` - the input value on submit, `null` on cancel

**Example:**

```javascript
const reason = await window.jetonomyPrompt(
    'Tell us why you are reporting this reply.',
    {
        title:        'Report reply',
        placeholder:  'Optional context for the moderator team',
        multiline:    true,
        confirmLabel: 'Submit report',
    }
);

if ( reason === null ) {
    return; // User cancelled.
}

await myPlugin.reportReply( replyId, reason );
```

Single-line prompts commit on Enter; multiline prompts require an explicit button click (Enter inserts a newline as expected).

---

## End-to-end Example

A custom moderator action that confirms, prompts for a note, then alerts on success - all without touching a native dialog:

```javascript
async function quarantinePost( postId ) {
    const proceed = await window.jetonomyConfirm(
        'Quarantine this post? It will be hidden from public view until reviewed.',
        {
            title:        'Quarantine post',
            confirmLabel: 'Quarantine',
            danger:       true,
        }
    );

    if ( ! proceed ) {
        return;
    }

    const note = await window.jetonomyPrompt(
        'Add a moderator note (optional).',
        {
            title:        'Moderator note',
            placeholder:  'e.g. flagged for review by 3 members',
            multiline:    true,
            confirmLabel: 'Save note',
        }
    );

    if ( note === null ) {
        return; // User backed out at the note stage.
    }

    await fetch( `/wp-json/my-plugin/v1/quarantine/${ postId }`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
        body:    JSON.stringify( { note } ),
    } );

    await window.jetonomyAlert(
        'Post quarantined. Moderators have been notified.',
        { title: 'Done' }
    );
}
```

The user gets three branded, translatable, keyboard-friendly modals in sequence instead of three jarring native dialogs.

---

## Localisation: `window.jetonomyModalsI18n`

Jetonomy localises four default button labels onto `window.jetonomyModalsI18n` via `wp_localize_script`. The script is registered on both the front-end and inside wp-admin, so the global is reliably available wherever the toolkit JS is loaded.

**Shape:**

```javascript
window.jetonomyModalsI18n = {
    cancel:  'Cancel',  // Default cancel button label (jetonomyConfirm, jetonomyPrompt)
    confirm: 'Confirm', // Default confirm button label (jetonomyConfirm)
    submit:  'Submit', // Default submit button label (jetonomyPrompt)
    ok:      'OK',      // Default OK button label (jetonomyAlert)
};
```

The values are translated through WordPress's standard i18n pipeline - if your site loads a `jetonomy` translation file, the strings arrive pre-translated and the modals adopt the active locale automatically.

### How third-party callers should use it

The global is provided so you can **read** the localised strings when you need additional context inside your own UI (for example, to label a custom action that mirrors one of the toolkit buttons):

```javascript
const cancelLabel = ( window.jetonomyModalsI18n && window.jetonomyModalsI18n.cancel ) || 'Cancel';

myDropdown.appendChild( makeMenuItem( cancelLabel, onCancel ) );
```

**Do not override the global.** Overrides are not supported and may be reset on the next page load. If you want a different label on a single modal, pass it via the per-call `opts.confirmLabel` / `opts.cancelLabel` instead:

```javascript
// Correct - per-call override
await window.jetonomyConfirm( 'Publish?', { confirmLabel: 'Publish now', cancelLabel: 'Keep as draft' } );

// Wrong - mutating the global
window.jetonomyModalsI18n.confirm = 'Publish now'; // do not do this
```

This keeps the global predictable for every other piece of code that reads from it.

---

## What's Next?

- [Visibility and Access Matrix](./08-visibility-and-access-matrix.md) - PHP-side public/private gate
- [Hooks Reference](./02-hooks-reference.md) - All `jetonomy_*` actions and filters
- [Template Overrides](./03-template-overrides.md) - Customise templates without modifying plugin files
