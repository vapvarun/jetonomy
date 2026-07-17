Jetonomy ships a full WP-CLI surface covering every core domain of the plugin: 15 command roots in the free plugin and 15 command roots in Jetonomy Pro, totalling 75+ subcommands across both plugins.

The 15 free roots are the 14 domain commands listed under [Free Commands](#free-commands) below (`category`, `space`, `post`, `reply`, `vote`, `flag`, `member`, `mod`, `notification`, `config`, `tag`, `user`, `privacy`, `scenario`), plus the standalone `qa-actions` command, documented under [Testing and QA Commands](#testing-and-qa-commands).

All free commands live under `wp jetonomy <subject> <subcommand>`.
All Pro commands live under `wp jetonomy-pro <subject> <subcommand>` (note the separate root - Pro commands require both plugins active).

On this local dev install you must prefix every `wp` call with `--path`:

```bash
wp --path="/path/to/wp" jetonomy space list
```

Customers running from their WordPress root directory can omit `--path`.

---

## Free Commands

### category

Manage top-level categories that group spaces.

| Subcommand | Description |
|------------|-------------|
| `create` | Create a new category |
| `list` | List all categories |
| `update <id>` | Update category fields |
| `delete <id>` | Delete a category |

**Flags - create:** `--name=<name>` `--slug=<slug>` `[--description=<text>]` `[--parent=<id>]` `[--format=<format>]`

**Flags - update:** `[--name=<name>]` `[--slug=<slug>]` `[--description=<text>]` `[--parent=<id>]` `[--sort=<order>]` `[--format=<format>]`

```bash
wp jetonomy category create --name="General" --slug=general
wp jetonomy category create --name="Support" --slug=support --parent=1
wp jetonomy category update 3 --name="Renamed" --sort=10
wp jetonomy category list --format=json
```

---

### space

Create and manage spaces (forums, Q&A boards, ideas boards, feeds).

| Subcommand | Description |
|------------|-------------|
| `create` | Create a new space |
| `list` | List spaces |
| `update <id>` | Update space settings |
| `delete <id>` | Delete a space |
| `add-member` | Add a member to a space |
| `remove-member` | Remove a member from a space |

**Flags - create:** `--title=<title>` `--slug=<slug>` `--category=<id>` `[--description=<text>]` `[--type=<type>]` `[--visibility=<vis>]` `[--join-policy=<policy>]` `[--format=<format>]`

Types: `forum` (default), `qa`, `ideas`, `feed`. Visibility: `public`, `private`, `hidden`. Join policy: `open`, `approval`, `invite`.

**Flags - update:** All optional - `[--title=<title>]` `[--description=<text>]` `[--type=<type>]` `[--visibility=<vis>]` `[--join-policy=<policy>]` `[--status=<status>]` `[--format=<format>]`

```bash
wp jetonomy space create --title="General" --slug=general --category=1
wp jetonomy space create --title="Q&A" --slug=qa --category=1 --type=qa --visibility=private --join-policy=approval
wp jetonomy space update 5 --visibility=private
wp jetonomy space list --format=table
```

---

### post

Create and manage posts within spaces.

| Subcommand | Description |
|------------|-------------|
| `create` | Create a new post |
| `list` | List posts |
| `update <id>` | Update a post |
| `delete <id>` | Delete a post |

**Flags - create:** `--space=<id>` `--author=<id>` `--title=<title>` `--content=<content>` `[--status=<status>]` `[--slug=<slug>]` `[--format=<format>]`

Status values: `published` (default), `draft`.

**Flags - update:** `[--title=<title>]` `[--content=<content>]` `[--status=<status>]` `[--slug=<slug>]` `[--format=<format>]`

```bash
wp jetonomy post create --space=5 --author=3 --title="Hello" --content="First post"
wp jetonomy post create --space=5 --author=3 --title="Draft" --content="..." --status=draft
wp jetonomy post update 42 --title="New title"
wp jetonomy post list --format=json
```

---

### reply

Create and manage replies on posts.

| Subcommand | Description |
|------------|-------------|
| `create` | Create a reply |
| `list` | List replies for a post |
| `update <id>` | Update a reply |
| `delete <id>` | Delete a reply |
| `accept` | Mark a reply as the accepted answer |

**Flags - create:** `--post=<id>` `--author=<id>` `--content=<content>` `[--parent=<id>]` `[--status=<status>]` `[--format=<format>]`

**Flags - accept:** `--post=<id>` `--reply=<id>` `[--format=<format>]`

```bash
wp jetonomy reply create --post=42 --author=3 --content="Great idea"
wp jetonomy reply create --post=42 --author=3 --content="Nested reply" --parent=17
wp jetonomy reply accept --post=42 --reply=17
```

---

### tag

Manage post tags.

| Subcommand | Description |
|------------|-------------|
| `create` | Create a tag |
| `list` | List all tags |
| `update <id>` | Update a tag |
| `delete <id>` | Delete a tag |
| `get-by-slug` | Fetch a tag by its slug |
| `attach` | Attach a tag to a post |
| `detach` | Detach a tag from a post |
| `list-for-post` | List tags on a specific post |

**Flags - create:** `--name=<name>` `[--format=<format>]`

**Flags - get-by-slug:** `--slug=<slug>` `[--format=<format>]`

**Flags - attach / detach:** `--post=<id>` `--tag=<id>` `[--format=<format>]`

**Flags - list-for-post:** `--post=<id>` `[--format=<format>]` `[--fields=<fields>]`

```bash
wp jetonomy tag create --name="announcement"
wp jetonomy tag attach --post=42 --tag=7
wp jetonomy tag detach --post=42 --tag=7
wp jetonomy tag list-for-post --post=42
```

---

### user

Manage community users - ban/unban, trust levels, profiles, and reputation.

| Subcommand | Description |
|------------|-------------|
| `create` | Create a new WordPress + Jetonomy user |
| `ban <id>` | Ban a user site-wide |
| `unban <id>` | Remove a site-wide ban |
| `promote <id>` | Increase a user's trust level by one step |
| `demote <id>` | Decrease a user's trust level by one step |
| `set-trust <id>` | Set a user's trust level to an exact value (0-5) |
| `get-trust <id>` | Read a user's current trust level |
| `update-profile <id>` | Update display name, bio, or avatar URL |
| `adjust-reputation <id>` | Add or subtract reputation points |

**Flags - create:** `--login=<login>` `--email=<email>` `[--password=<pw>]` `[--role=<role>]` `[--trust-level=<0-5>]` `[--display-name=<name>]` `[--format=<format>]`

**Flags - set-trust:** `--level=<0-5>` `[--format=<format>]`

**Flags - update-profile:** `[--display-name=<name>]` `[--bio=<bio>]` `[--avatar-url=<url>]` `[--format=<format>]`

**Flags - adjust-reputation:** `--delta=<int>` (use negative values to subtract) `[--format=<format>]`

```bash
wp jetonomy user create --login=alice --email=alice@example.com
wp jetonomy user create --login=mod1 --email=m1@ex.com --trust-level=4 --role=editor
wp jetonomy user set-trust 42 --level=3
wp jetonomy user adjust-reputation 42 --delta=25
wp jetonomy user adjust-reputation 42 --delta=-10
wp jetonomy user update-profile 42 --bio="Hello world" --avatar-url="https://example.com/a.png"
```

---

### member

Manage space membership - join, leave, and role assignment.

| Subcommand | Description |
|------------|-------------|
| `add` | Add a user to a space (admin shortcut) |
| `remove` | Remove a user from a space |
| `promote` | Promote a member to moderator |
| `demote` | Demote a member from moderator |
| `join` | Record a user joining a space (respects join policy) |
| `leave` | Record a user leaving a space |
| `set-role` | Set a member's role directly |
| `is-member` | Check whether a user is a member |

Note: `--by` is used for the user ID in all subcommands because `--user` is a reserved WP-CLI global flag.

**Flags - join:** `--space=<id>` `--by=<user_id>` `[--role=<role>]` `[--format=<format>]`

**Flags - set-role:** `--space=<id>` `--by=<user_id>` `--role=<role>` `[--format=<format>]`

**Flags - is-member:** `--space=<id>` `--by=<user_id>` `[--format=<format>]`

```bash
wp jetonomy member join --space=15 --by=4
wp jetonomy member join --space=15 --by=4 --role=moderator
wp jetonomy member set-role --space=15 --by=4 --role=moderator
wp jetonomy member leave --space=15 --by=4
wp jetonomy member is-member --space=15 --by=4
```

---

### vote

Cast and inspect votes on posts and replies.

| Subcommand | Description |
|------------|-------------|
| `create` | Cast a vote (upvote or downvote) |
| `delete` | Retract a vote |
| `list` | List votes on a post or reply |
| `cast` | Alias for create with explicit flags |

Note: `--voter` is used for the voter's user ID because `--user` is a reserved WP-CLI global flag.

**Flags - cast:** `--voter=<id>` `--type=<type>` `--id=<id>` `--value=<value>` `[--format=<format>]`

Type: `post` or `reply`. Value: `1` (upvote) or `-1` (downvote).

```bash
wp jetonomy vote cast --voter=3 --type=post --id=42 --value=1
wp jetonomy vote cast --voter=3 --type=reply --id=17 --value=-1
```

---

### flag

Create and manage content flags (user reports).

| Subcommand | Description |
|------------|-------------|
| `list` | List flags (filterable by status) |
| `resolve` | Resolve or dismiss a flag |
| `create` | File a new flag against a post or reply |

Note: `--reporter` is used for the reporting user ID because `--user` is reserved.

**Flags - create:** `--type=<type>` `--id=<id>` `--reporter=<id>` `--reason=<reason>` `[--description=<description>]` `[--format=<format>]`

Type: `post` or `reply`. Reason values: `spam`, `harassment`, `off-topic`, `other`.

```bash
wp jetonomy flag create --type=post --id=42 --reporter=3 --reason=spam
wp jetonomy flag create --type=reply --id=17 --reporter=3 --reason=harassment --description="Context here"
wp jetonomy flag list --format=json
```

---

### notification

Trigger and inspect notifications.

| Subcommand | Description |
|------------|-------------|
| `send` | Trigger a notification event |
| `list` | List notifications for a user |
| `trigger` | Alias for send with explicit flags |
| `mark-read` | Mark notifications as read for a user |

Note: `--to` is used for the recipient user ID because `--user` is reserved.

**Flags - trigger:** `--type=<type>` `--to=<user_id>` `--actor=<user_id>` `--object-type=<type>` `--object-id=<id>` `--message=<text>` `[--format=<format>]`

**Flags - list:** `--to=<user_id>` `[--limit=<n>]` `[--offset=<n>]` `[--fields=<fields>]` `[--format=<format>]`

```bash
wp jetonomy notification trigger --type=reply_to_post --to=1 --actor=2 --object-type=post --object-id=5 --message="Someone replied"
wp jetonomy notification list --to=1
wp jetonomy notification list --to=1 --limit=5 --format=json
```

---

### config

Read and write Jetonomy settings using dotted key paths.

| Subcommand | Description |
|------------|-------------|
| `get` | Read a setting value |
| `set` | Write a setting value |
| `list` | List all settings |
| `reset` | Reset a single key to its default |
| `reset-all` | Reset all settings to defaults |
| `keys` | List available keys under a parent path |

**Flags - get:** `[--key=<dotted_path>]` `[--format=<format>]`

**Flags - set:** `--key=<dotted_path>` `--value=<value>` `[--format=<format>]`

**Flags - keys:** `[--key=<parent_path>]` `[--format=<format>]` `[--fields=<fields>]`

```bash
wp jetonomy config get --key=trust_thresholds.1.posts
wp jetonomy config get --key=rate_limits --format=json
wp jetonomy config set --key=trust_thresholds.1.posts --value=7
wp jetonomy config set --key=notification_defaults.mention.email --value=false
wp jetonomy config keys --key=trust_thresholds
wp jetonomy config list
```

---

### mod

Advanced moderation actions: bans, flag management, and content decisions.

| Subcommand | Description |
|------------|-------------|
| `approve` | Approve flagged content |
| `spam` | Mark content as spam |
| `trash` | Trash content |
| `flags` | List flags with optional status filter |
| `resolve` | Resolve or dismiss a flag |
| `ban` | Ban a user (site-wide or space-scoped) |
| `unban` | Lift a ban |
| `is-banned` | Check whether a user is banned |

Note: `--target` is used for the affected user and `--issuer` for the moderator, avoiding the reserved `--user` flag.

**Flags - flags:** `[--status=<status>]` `[--format=<format>]` `[--fields=<fields>]`

Status values: `valid`, `dismissed`.

**Flags - resolve:** `--resolver=<user_id>` `--decision=<decision>` `[--format=<format>]`

**Flags - ban:** `--target=<user_id>` `--issuer=<user_id>` `[--type=<type>]` `[--space=<id>]` `[--reason=<text>]` `[--expires=<datetime>]` `[--format=<format>]`

**Flags - is-banned:** `--target=<user_id>` `[--space=<id>]` `[--format=<format>]`

```bash
wp jetonomy mod flags --status=valid
wp jetonomy mod resolve 42 --resolver=1 --decision=valid
wp jetonomy mod resolve 17 --resolver=1 --decision=dismissed
wp jetonomy mod ban --target=5 --issuer=1 --reason="spam"
wp jetonomy mod ban --target=5 --issuer=1 --type=space_ban --space=3
wp jetonomy mod ban --target=5 --issuer=1 --type=silence --expires="2026-05-01 00:00:00"
wp jetonomy mod unban 5
wp jetonomy mod is-banned --target=5 --space=3
```

---

### privacy

Find and remediate orphaned user data - rows still pointing at WordPress accounts that no longer exist. Use this for GDPR cleanup of data left behind by accounts deleted before Jetonomy 1.7.1, when neither the user-delete path nor the multisite removal hooks purged every Jetonomy table (notably raw AI prompt/response logs).

| Subcommand | Description |
|------------|-------------|
| `scan` | Report orphaned user data (read-only) |
| `purge-orphans` | Purge all data belonging to accounts that no longer exist |

`scan` is read-only. It lists each `(table, column)` still holding rows whose user id is absent from `wp_users`, plus the total orphan row and account counts. Rows with user id `0` are never reported - `0` is the anonymized/system sentinel that the eraser and delete path deliberately leave behind.

`purge-orphans` replays the live user-deletion path (it fires the `jetonomy_purge_orphan_user` action, the same one free and Pro already listen to via `on_user_delete()`), so denormalized counters are recomputed and caches busted exactly as a real deletion would - there is no second, drift-prone cleanup list. It is idempotent: purging an orphan removes the rows that made it discoverable, so a second run finds nothing and does nothing. Run `--dry-run` first to preview what would be removed. On multisite it cleans the current site's tables; pass `--url=<site>` per site to sweep a network.

**Flags - scan:** `[--format=<format>]`

Format values: `table` (default), `json`, `csv`.

**Flags - purge-orphans:** `[--dry-run]` (report what would be removed without removing anything) `[--format=<format>]`

Format values: `table` (default), `json`.

```bash
wp jetonomy privacy scan
wp jetonomy privacy scan --format=json
wp jetonomy privacy purge-orphans --dry-run
wp jetonomy privacy purge-orphans
```

---

### scenario

Run end-to-end PHP scenarios that exercise full user journeys against the live database.

| Subcommand | Description |
|------------|-------------|
| `run <name>` | Run a named scenario |
| `list` | List all available scenarios |

**Flags - run:** `[--cleanup]` (roll back all data created by the scenario) `[--format=<format>]`

**Flags - list:** `[--format=<format>]` `[--fields=<fields>]`

Bundled scenarios: `basic-forum-flow`, `notification-delivery-sweep`, `multi-user-voting-thread`, `moderation-lifecycle`, `space-member-journey`.

```bash
wp jetonomy scenario list
wp jetonomy scenario list --format=json
wp jetonomy scenario run basic-forum-flow
wp jetonomy scenario run notification-delivery-sweep --cleanup
wp jetonomy scenario run multi-user-voting-thread --format=json
```

---

## Pro Commands

Pro commands use `wp jetonomy-pro` as the top-level namespace. Every Pro subcommand requires both Jetonomy (free) and Jetonomy Pro to be active.

---

### extension

Enable, disable, and inspect Pro extensions.

| Subcommand | Description |
|------------|-------------|
| `list` | List all extensions and their current state |
| `enable <id>` | Enable an extension |
| `disable <id>` | Disable an extension |
| `activate <id>` | Activate (boot) an extension that is already enabled |
| `deactivate <id>` | Deactivate a running extension |
| `status <id>` | Show status for a single extension |

```bash
wp jetonomy-pro extension list
wp jetonomy-pro extension list --format=json
wp jetonomy-pro extension enable private-messaging
wp jetonomy-pro extension disable polls
wp jetonomy-pro extension status webhooks
```

---

### custom-fields

Manage custom fields that extend post or user objects.

| Subcommand | Description |
|------------|-------------|
| `list` | List all defined custom fields |
| `create` | Define a new custom field |
| `delete <id>` | Remove a custom field definition |

**Flags - create:** `--key=<slug>` `--label=<text>` `--type=<type>` `--applies-to=<target>` `[--description=<text>]` `[--options=<csv>]` `[--required]` `[--default=<value>]` `[--format=<format>]`

Types: `text`, `textarea`, `select`, `checkbox`, `number`, `url`. Applies-to: `post` or `user`.

```bash
wp jetonomy-pro custom-fields create --key=company --label=Company --type=text --applies-to=user
wp jetonomy-pro custom-fields create --key=priority --label=Priority --type=select --applies-to=post --options="low,medium,high"
wp jetonomy-pro custom-fields list --format=json
wp jetonomy-pro custom-fields delete 4
```

---

### white-label

Set white-label branding for the community frontend.

| Subcommand | Description |
|------------|-------------|
| `set-logo` | Replace the community logo |
| `set-colors` | Set primary and accent brand colors |

```bash
wp jetonomy-pro white-label set-logo --url=https://example.com/logo.png
wp jetonomy-pro white-label set-colors --primary="#1a73e8" --accent="#fbbc04"
```

---

### reactions

Manage emoji reactions on posts and replies.

| Subcommand | Description |
|------------|-------------|
| `list` | List all reactions on an object |
| `purge` | Remove all reactions from an object |
| `add` | Add a reaction from a user |
| `remove` | Remove a user's reaction |

Note: `--by` is used for the acting user, `--on-type` and `--on-id` for the target object.

**Flags - add / remove:** `--on-type=<type>` `--on-id=<id>` `--by=<user_id>` `--emoji=<slug>` `[--format=<format>]`

```bash
wp jetonomy-pro reactions add --on-type=post --on-id=12 --by=1 --emoji=thumbsup
wp jetonomy-pro reactions add --on-type=reply --on-id=45 --by=2 --emoji=heart
wp jetonomy-pro reactions list --on-type=post --on-id=12
wp jetonomy-pro reactions purge --on-type=post --on-id=12
```

---

### ai

Inspect and test the AI moderation / summarization provider.

| Subcommand | Description |
|------------|-------------|
| `test-provider <provider>` | Send a test prompt to a named provider |
| `clear-cache` | Purge the AI response cache |
| `export-usage` | Export AI API usage records |
| `status` | Show current provider health |
| `list-providers` | List all configured AI providers |
| `provider-status <provider>` | Show status for a specific provider |

**Flags - test-provider:** `[--prompt=<text>]` `[--format=<format>]`

```bash
wp jetonomy-pro ai status
wp jetonomy-pro ai list-providers --format=json
wp jetonomy-pro ai test-provider anthropic --prompt="Say hi"
wp jetonomy-pro ai clear-cache
wp jetonomy-pro ai export-usage --format=json
```

---

### advanced-moderation

Inspect and test automated moderation rules.

| Subcommand | Description |
|------------|-------------|
| `list-rules` | List all active moderation rules |
| `test-rule <id>` | Test a rule against sample content |

```bash
wp jetonomy-pro advanced-moderation list-rules
wp jetonomy-pro advanced-moderation list-rules --format=json
wp jetonomy-pro advanced-moderation test-rule 3
```

---

### custom-badges

Award and inspect custom reputation badges.

| Subcommand | Description |
|------------|-------------|
| `list` | List all badge definitions |
| `award` | Award a badge to a user |

**Flags - award:** `--badge=<id>` `--to=<user_id>` `[--format=<format>]`

```bash
wp jetonomy-pro custom-badges list
wp jetonomy-pro custom-badges award --badge=5 --to=42
```

---

### polls

Create and manage polls attached to posts.

| Subcommand | Description |
|------------|-------------|
| `list` | List polls |
| `close <id>` | Close a poll early |
| `create` | Create a poll on a post |
| `get <id>` | Get poll details and current results |
| `vote` | Cast a vote on a poll option |

Note: `--by` is used for the voter user ID because `--user` is reserved.

**Flags - create:** `--post=<id>` `--question=<text>` `--options=<csv>` `[--multiple]` `[--closes-at=<datetime>]` `[--format=<format>]`

**Flags - vote:** `--post=<id>` `--by=<user_id>` and the option index/id.

```bash
wp jetonomy-pro polls create --post=12 --question="Favourite colour?" --options="Red,Green,Blue"
wp jetonomy-pro polls create --post=12 --question="Pick many" --options="A,B,C" --multiple
wp jetonomy-pro polls get 4
wp jetonomy-pro polls close 4
```

---

### email-digest

Manage user email digest preferences and trigger sends.

| Subcommand | Description |
|------------|-------------|
| `send-now` | Send a digest immediately for a user |
| `export-digests` | Export digest records |
| `get-prefs` | Get a user's digest preferences |
| `set-prefs` | Update a user's digest preferences |

Note: `--for` is used for the target user ID because `--user` is reserved.

**Flags - get-prefs / send-now:** `--for=<user_id>` `[--format=<format>]`

**Flags - set-prefs:** `--for=<user_id>` `[--frequency=<frequency>]` `[--enabled]` `[--disabled]` `[--types=<csv>]` `[--format=<format>]`

Frequency values: `daily`, `weekly`, `off`.

```bash
wp jetonomy-pro email-digest get-prefs --for=1
wp jetonomy-pro email-digest set-prefs --for=1 --frequency=weekly --enabled
wp jetonomy-pro email-digest set-prefs --for=1 --frequency=off
wp jetonomy-pro email-digest send-now --for=1
```

---

### web-push

Manage browser push subscriptions and send push notifications.

| Subcommand | Description |
|------------|-------------|
| `send` | Send a push notification to a user |
| `list-subscriptions` | List a user's active push subscriptions |
| `subscribe` | Register a push subscription for a user |
| `unsubscribe` | Remove a push subscription |

Note: `--for` is used for the subscriber user ID, `--to` for the notification recipient.

**Flags - subscribe:** `--for=<user_id>` `--endpoint=<url>` `--p256dh=<key>` `--auth=<key>` `[--format=<format>]`

**Flags - unsubscribe:** `--for=<user_id>` `--endpoint=<url>` `[--format=<format>]`

```bash
wp jetonomy-pro web-push list-subscriptions --for=1
wp jetonomy-pro web-push send --to=1 --title="New reply" --body="Alice replied to your post"
```

---

### analytics

Export and report on community analytics.

| Subcommand | Description |
|------------|-------------|
| `export` | Export raw analytics data |
| `report` | Print a formatted summary report |
| `overview` | High-level stats for a date range |
| `top-spaces` | Rank spaces by activity for a period |

**Flags - overview:** `[--range=<range>]` `[--start=<date>]` `[--end=<date>]` `[--format=<format>]`

Range values: `7d`, `30d`, `90d`, `custom`. When using `custom`, provide `--start=YYYY-MM-DD` and `--end=YYYY-MM-DD`.

**Flags - top-spaces:** `[--range=<range>]` `[--limit=<n>]` `[--format=<format>]`

```bash
wp jetonomy-pro analytics overview --range=7d
wp jetonomy-pro analytics overview --range=custom --start=2026-03-01 --end=2026-03-15
wp jetonomy-pro analytics top-spaces --range=30d --limit=5
wp jetonomy-pro analytics export --range=30d --format=csv
```

---

### seo-pro

Generate SEO sitemaps for community content.

| Subcommand | Description |
|------------|-------------|
| `generate-sitemaps` | Rebuild all Jetonomy sitemaps |

```bash
wp jetonomy-pro seo-pro generate-sitemaps
```

---

### webhooks

Manage outbound webhook endpoints.

| Subcommand | Description |
|------------|-------------|
| `list` | List all registered webhooks |
| `test <id>` | Send a test ping to a webhook |
| `retry <id>` | Retry failed deliveries for a webhook |
| `create` | Register a new webhook |
| `update <id>` | Update webhook settings |

Note: `--target-url` is used instead of `--url` because WP-CLI reserves `--url` globally for multisite routing.

**Flags - create:** `--target-url=<url>` `--events=<csv>` `[--name=<text>]` `[--secret=<text>]` `[--description=<text>]` `[--disabled]` `[--format=<format>]`

Secret is auto-generated if omitted. Events are a comma-separated list of event slugs such as `post.created`, `reply.created`, `user.registered`.

**Flags - update:** `[--target-url=<url>]` `[--events=<csv>]` `[--name=<text>]` `[--description=<text>]` `[--format=<format>]`

```bash
wp jetonomy-pro webhooks list
wp jetonomy-pro webhooks create --target-url=https://example.com/hook --events=post.created,reply.created
wp jetonomy-pro webhooks create --target-url=https://example.com/hook --events=user.registered --disabled
wp jetonomy-pro webhooks test 3
wp jetonomy-pro webhooks retry 3
```

---

### messaging

Manage private message conversations.

| Subcommand | Description |
|------------|-------------|
| `export-conversations` | Export conversation records for a user |
| `purge-old` | Delete conversations older than a given age |
| `create-conversation` | Start a new private conversation |
| `send` | Send a message in an existing conversation |

Note: `--by` is the conversation initiator, `--with` is a comma-separated list of participant IDs, `--from` is the message sender.

**Flags - create-conversation:** `--by=<user_id>` `--with=<csv>` `[--title=<text>]` `[--type=<type>]` `[--message=<text>]` `[--format=<format>]`

**Flags - send:** `--conversation=<id>` `--from=<user_id>` `--content=<text>` `[--format=<format>]`

```bash
wp jetonomy-pro messaging create-conversation --by=1 --with=3,4
wp jetonomy-pro messaging create-conversation --by=1 --with=2 --message="Hi there"
wp jetonomy-pro messaging send --conversation=12 --from=1 --content="hello"
wp jetonomy-pro messaging export-conversations --by=1
```

---

### reply-by-email

Configure and test inbound reply-by-email processing.

| Subcommand | Description |
|------------|-------------|
| `configure` | Set SMTP / IMAP connection settings |
| `test-smtp` | Send a test email to verify outbound settings |

```bash
wp jetonomy-pro reply-by-email configure --host=mail.example.com --user=inbox@example.com
wp jetonomy-pro reply-by-email test-smtp
```

---

## Testing and QA Commands

These commands run the built-in quality assurance suites against a live WordPress + Jetonomy install. Run them before every release to catch regressions early.

### qa-actions (free)

Runs all four smoke-test phases in sequence and reports a pass/fail total. Takes no arguments.

| Phase | What it covers |
|-------|----------------|
| Phase 1 | REST round-trip tests (creates, reads, deletes via the REST API) |
| Phase 2 | Model unit tests (direct model-layer assertions) |
| Phase 3 | Pro extension tests (skipped automatically on free-only installs) |
| Phase 4 | Journey smoke tests (C1-C12 - full end-to-end user journeys) |

```bash
wp jetonomy qa-actions
```

Expected output when all tests pass:

```
--- Jetonomy Action Tests ---

Phase 1: REST Round-Trip Tests
...
Phase 4: Journey Smoke Tests (C1-C12)
...
  REST Tests:     42/42
  Model Tests:    58/58
  Pro Tests:      64/64
  Journey Tests:  46/46
  Total: 210/210 PASS
```

### scenario run (free)

Run a named end-to-end scenario against the live database. Use `--cleanup` to roll back all data the scenario creates.

```bash
# List available scenarios first
wp jetonomy scenario list

# Run a scenario and keep the data (useful for manual inspection after)
wp jetonomy scenario run basic-forum-flow

# Run and clean up all created data
wp jetonomy scenario run notification-delivery-sweep --cleanup
wp jetonomy scenario run moderation-lifecycle --cleanup
```

Scenarios are PHP classes under `includes/cli/scenarios/` and can be run directly by PHPUnit via `composer test:combo` if you prefer not to use a live database.
