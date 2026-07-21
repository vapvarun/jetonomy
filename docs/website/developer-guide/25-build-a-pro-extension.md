# Build a Pro Extension

Since Jetonomy Pro 1.8.1, the extension system that powers all bundled Pro modules (Private Messaging, Polls, Analytics, and the rest) is open to third-party developers. Your plugin can register an extension that gets the exact same lifecycle the bundled ones do: an admin toggle on the Extensions screen, table creation on the migration guard, `boot()` when enabled, and cleanup on deactivation.

Requires: Jetonomy Pro active (the SDK classes ship with Pro). Your extension is **not** gated by the customer's Jetonomy Pro license — if you sell your extension separately, gate your own `boot()`.

## The contract

Extend the abstract `Jetonomy_Pro\Extension` and register the instance through the `jetonomy_pro_register_extensions` filter:

```php
add_filter( 'jetonomy_pro_register_extensions', function ( $extensions ) {
	if ( ! class_exists( 'Jetonomy_Pro\\Extension' ) ) {
		return $extensions; // Pro not active.
	}
	require_once __DIR__ . '/class-my-extension.php';
	$ext = new \Acme\Jetonomy\My_Extension();
	$extensions[ $ext->get_id() ] = $ext;
	return $extensions;
} );
```

```php
namespace Acme\Jetonomy;

class My_Extension extends \Jetonomy_Pro\Extension {

	/**
	 * REQUIRED for external extensions. The base id() derives the id from
	 * your class NAMESPACE (second-to-last segment, Pascal_Case → kebab-case),
	 * which almost never matches for third-party code — return it explicitly.
	 */
	public function id(): string {
		return 'acme-kudos';
	}

	public function meta(): array {
		return array(
			'id'          => 'acme-kudos',
			'name'        => __( 'Acme Kudos', 'acme-kudos' ),
			'version'     => '1.0.0',
			'description' => __( 'Send kudos to helpful members.', 'acme-kudos' ),
			'category'    => __( 'Engagement', 'acme-kudos' ),
			'requires'    => 'starter',
		);
	}

	/** Called only when the extension is enabled on the Extensions screen. */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Called on the version-change migration guard — dbDelta your tables here. Idempotent. */
	public function activate(): void {}

	/** Called on Jetonomy Pro deactivation — clear cron, etc. */
	public function deactivate(): void {}
}
```

## Lifecycle guarantees

| Moment | What runs |
|---|---|
| Pro loads (`plugins_loaded:20`) | Your filter callback registers the instance |
| Stored DB version ≠ Pro version | `activate()` on every registered extension (including yours) |
| Extension enabled + each request | `boot()` |
| Pro deactivated | `deactivate()` on enabled extensions |

Enable/disable state lives in the `jetonomy_pro_extensions` option and is toggled from **Jetonomy → Extensions**, where your extension appears alongside the bundled ones using your `meta()` values.

## Rules that keep you compatible

1. **Never reuse a bundled id** (`private-messaging`, `polls`, `reactions`, …) — duplicate ids are ignored, first registration wins.
2. **Prefix everything**: options and user meta `acme_kudos_*`, tables `{$wpdb->prefix}acme_kudos_*`, cron hooks `acme_kudos_*`. Do not use the `jetonomy_pro_` prefixes — those namespaces belong to bundled modules.
3. **REST permission callbacks**: use `$this->rest_auth_mutation( $caps )` from the base class for mutation routes — it resolves free's `REST_Auth` lazily and fails closed (never reference `\Jetonomy\API\REST_Auth` directly at registration time).
4. **Background jobs** go through `Jetonomy_Pro\Queue` (`async()` / `recurring()` / `cancel()`) — Action-Scheduler-first with WP-Cron fallback; cancel your hooks in `deactivate()`.
5. **Licensing is yours**: the customer's Jetonomy Pro license never disables your extension. If yours is paid, check your own license at the top of `boot()` and return early.
6. **Frontend surfaces** must follow the [Frontend Interactivity Standard](../../standards/frontend-interactivity.md) (declarative store actions, `restFetch`, re-init on `jetonomy:navigated`) and use the [`--jt-*` design tokens](16-theming-and-tokens.md).

## Hooking free instead

If you don't need an admin toggle, tables, or the lifecycle — just behavior — you may not need an extension at all. The free plugin fires 190+ documented hooks ([hooks reference](02-hooks-reference.md)) and supports [REST](18-extend-the-rest-api.md), [frontend](17-extend-the-frontend.md), and [adapter](05-adapters.md) extension without Pro. Reach for the extension SDK when you want your feature to feel like a first-class Pro module.
