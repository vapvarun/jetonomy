# Contributing to Jetonomy

## Branch Workflow

All work happens on version branches (e.g. `v1.3.0`). **Never develop on `main`.**

```
main (production)
 └── v1.3.0 (current version branch)
      ├── feature/private-topics
      ├── fix/vote-state-indicator
      └── refactor/cache-layer
```

1. Create feature branch off the version branch: `git checkout -b feature/my-feature v1.3.0`
2. Make changes, commit, push
3. PR into the version branch (never directly into `main`)
4. Version branch merges into `main` at release

## Pre-Commit Checks (mandatory)

Both must pass with zero errors before committing:

```bash
# WPCS
vendor/bin/phpcs --standard=.phpcs.xml.dist includes/ templates/

# PHPStan
vendor/bin/phpstan analyse -c phpstan.neon.dist
```

## Code Patterns

### Models (`includes/models/`)

All data access goes through model classes that extend `Jetonomy\Models\Model`. No raw SQL outside `includes/db/`.

```php
// Good — use model methods
$post = Post::find( $id );
Post::update( $id, [ 'title' => $title ] );

// Bad — raw query in a controller
$wpdb->get_row( "SELECT * FROM {$wpdb->prefix}jt_posts WHERE id = $id" );
```

### REST Controllers (`includes/api/`)

All extend `Jetonomy\API\Base_Controller` (which extends `WP_REST_Controller`). Use the built-in helpers:

- `$this->check_permission( $action, $space_id )` for auth
- `$this->permission_error()` for 403 responses
- `$this->not_found( 'Post' )` for 404 responses

### Permission Engine

Three layers, checked in order:

1. **Global ban** via `Restriction` model
2. **WP capability** `jetonomy_{action}`
3. **Space role** (viewer, member, moderator, admin)

Always use `Permission_Engine::can( $user_id, $action, $space_id )`. WP admins (`manage_options`) bypass layers 1-2.

### Settings

All core settings live in one option: `get_option( 'jetonomy_settings', [] )`. Never create separate `jetonomy_*` options for individual settings. The settings array is read once and passed through.

### Counter Pattern

Denormalized counters (`reply_count`, `post_count`, `vote_score`) are updated inside `Model::create()` and `Model::delete()` methods. Never increment counters in controllers or hooks.

```php
// Inside Reply::create() — correct
Space::increment_reply_count( $space_id );

// Inside Replies_Controller::create_item() — wrong
$wpdb->query( "UPDATE ... SET reply_count = reply_count + 1" );
```

### Template Overrides

Theme authors override templates by placing files in `theme/jetonomy/`:

```
theme/jetonomy/views/home.php         → overrides templates/views/home.php
theme/jetonomy/partials/header.php    → overrides templates/partials/header.php
```

Templates receive data via local `$data` array, never globals.

### CSS Tokens

All visual values use `--jt-*` custom properties. Never hardcode `px`, `hex`, or `font-family`.

```css
/* Correct */
.jt-card { background: var(--jt-bg); border-radius: var(--jt-radius); }

/* Wrong */
.jt-card { background: #ffffff; border-radius: 8px; }
```

Tokens are defined in `:root, .jt-app` in `assets/css/jetonomy.css`. When you need a new value, add a token there first. Dark mode is handled globally by reassigning tokens in `.jt-dark .jt-app` -- never add per-component dark selectors.

## Testing

### Running PHPUnit

```bash
# Install WP test library (first time only)
bash bin/install-wp-tests.sh wp_tests root root 127.0.0.1 latest true

# Run all tests
vendor/bin/phpunit

# Run specific suite
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit --testsuite security
```

Suites: `unit`, `integration`, `security`, `error-paths`, `concurrency`, `pro`.

### What to Test

- Model CRUD and counter side-effects
- Permission checks for each action + space role
- REST endpoint response codes and shapes
- Edge cases: deleted parent, banned user, trust level gates

## CI Pipeline

GitHub Actions runs on push to `main`/`v*` branches and all PRs:

| Job | What it checks |
|-----|----------------|
| **php-lint** | Syntax across PHP 8.1/8.2/8.3/8.4 |
| **phpunit** | Full matrix: PHP 8.1-8.4 x WP 6.7/6.8/6.9 |
| **phpstan** | Static analysis, zero errors required |
| **wpcs** | Coding standards, zero errors required |
| **plugin-check** | WordPress PCP with noise filtering |

All five jobs must pass before merge.

## Naming Conventions

| Entity | Prefix | Example |
|--------|--------|---------|
| Options | `jetonomy_` | `jetonomy_settings` |
| User meta | `jetonomy_` | `jetonomy_onboarding_complete` |
| DB tables | `jt_` | `wp_jt_posts` |
| Hooks | `jetonomy_` | `jetonomy_after_create_post` |
| AJAX actions | `jetonomy_` | `wp_ajax_jetonomy_create_space` |
| Asset handles | `jetonomy` | `jetonomy`, `jetonomy-admin` |
| REST namespace | `jetonomy/v1` | `/wp-json/jetonomy/v1/posts` |

## Admin Architecture

AJAX handlers live in `Jetonomy\Admin\Ajax\*_Handler` classes, never in the `Admin` class directly. The `Admin` class handles menus, settings registration, and rendering only. Max 750 lines for `Admin`, 400 lines for each handler.
