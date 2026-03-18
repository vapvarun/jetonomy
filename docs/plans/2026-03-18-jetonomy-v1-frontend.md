# Jetonomy v1.0 — Frontend Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development to implement this plan.

**Goal:** Build the complete frontend — URL routing, PHP templates, Interactivity API blocks, CSS design system, and Preact reply editor island.

**Architecture:** WordPress rewrite rules → PHP router → server-rendered templates with Interactivity API directives → Preact island for editor. Theme-adaptive CSS via custom properties inheriting from theme.json.

**Tech Stack:** PHP templates, WP Interactivity API, Preact (for editor island), @wordpress/scripts build, CSS Layers + Custom Properties.

---

## File Structure

```
├── includes/
│   ├── class-router.php          # URL rewrite rules + template routing
│   └── class-template-loader.php # Template rendering helper
├── templates/
│   ├── main.php                  # Shell template (wraps all views)
│   ├── views/
│   │   ├── home.php              # Community home (categories + spaces)
│   │   ├── category.php          # Single category view
│   │   ├── space.php             # Space view (post listing)
│   │   ├── single-post.php       # Single post + replies
│   │   ├── user-profile.php      # User profile page
│   │   └── search.php            # Search results
│   └── partials/
│       ├── header.php            # Jetonomy header (nav, search, notif)
│       ├── breadcrumb.php        # Breadcrumb trail
│       ├── post-card.php         # Single post row in listing
│       ├── reply-card.php        # Single reply block
│       ├── sidebar.php           # Sidebar (trending, leaderboard, tags)
│       ├── vote-buttons.php      # Vote up/down with IA directives
│       ├── composer.php          # Reply composer (Preact mount point)
│       └── pagination.php        # Load more / cursor pagination
├── blocks/
│   ├── jetonomy-app/
│   │   ├── block.json
│   │   ├── render.php
│   │   └── view.js               # Interactivity API store + actions
│   └── build/                    # Compiled output
├── assets/
│   ├── css/
│   │   └── jetonomy.css          # Main stylesheet (CSS Layers)
│   └── js/
│       └── editor-island.js      # Preact reply editor (built separately)
├── src/
│   └── editor/
│       ├── index.jsx             # Preact editor island entry
│       ├── Editor.jsx            # Tiptap-lite editor component
│       └── MentionList.jsx       # @mention autocomplete
└── package.json                  # Build config
```

---

## Tasks

### Task 1: URL Router + Template System
### Task 2: CSS Design System (theme-adaptive)
### Task 3: Main shell + Home view + Space view templates
### Task 4: Single post + Reply templates with vote buttons
### Task 5: Interactivity API store (voting, sorting, load-more)
### Task 6: Preact reply editor island
### Task 7: Sidebar, search, user profile templates
### Task 8: Wire into plugin bootstrap + build config
