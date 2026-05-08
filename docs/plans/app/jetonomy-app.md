# jetonomy-app - React Native Mobile App

## Overview

White-label community forum app. One codebase builds every customer's branded app. Connects to any Jetonomy-powered WordPress site via REST API.

## Tech Stack

- **Framework:** React Native + Expo SDK 52+
- **Navigation:** Expo Router (file-based)
- **State:** Zustand (lightweight, no boilerplate)
- **API:** Axios + React Query (caching, offline, retry)
- **Storage:** Expo SecureStore (tokens), AsyncStorage (preferences)
- **Push:** Expo Notifications
- **UI:** NativeWind (Tailwind for React Native)
- **Build:** EAS Build + EAS Submit

## Project Structure

```
jetonomy-app/
├── app/                          # Expo Router screens
│   ├── (auth)/
│   │   └── login.tsx             # License key + site URL + credentials
│   ├── (tabs)/
│   │   ├── _layout.tsx           # Bottom tab navigator
│   │   ├── index.tsx             # Home feed (all spaces)
│   │   ├── spaces.tsx            # Spaces list
│   │   ├── notifications.tsx     # Notifications
│   │   ├── messages.tsx          # Conversations (Pro)
│   │   └── profile.tsx           # Current user profile
│   ├── space/[id].tsx            # Space feed
│   ├── post/[id].tsx             # Post detail + replies
│   ├── post/new.tsx              # Create new post
│   ├── user/[id].tsx             # User profile
│   ├── search.tsx                # Search
│   └── settings.tsx              # App settings
├── components/
│   ├── PostCard.tsx              # Post in feed (title, votes, reply count)
│   ├── ReplyItem.tsx             # Reply with threading
│   ├── VoteButton.tsx            # Upvote/downvote
│   ├── SpaceCard.tsx             # Space in list
│   ├── NotificationItem.tsx      # Notification row
│   ├── ConversationItem.tsx      # Message thread preview
│   ├── SearchBar.tsx             # Search input
│   ├── ReactionPicker.tsx        # Emoji reactions (Pro)
│   ├── PollView.tsx              # Poll display + vote (Pro)
│   ├── BadgeList.tsx             # User badges
│   ├── EmptyState.tsx            # Empty list placeholder
│   └── LoadingSpinner.tsx        # Loading indicator
├── api/
│   ├── client.ts                 # Axios instance with auth headers
│   ├── auth.ts                   # login, refresh token
│   ├── posts.ts                  # CRUD posts
│   ├── replies.ts                # CRUD replies
│   ├── spaces.ts                 # list, get, join, leave
│   ├── votes.ts                  # cast vote
│   ├── notifications.ts          # list, mark read
│   ├── search.ts                 # search
│   ├── users.ts                  # profiles
│   ├── conversations.ts          # messaging (Pro)
│   ├── reactions.ts              # reactions (Pro)
│   ├── polls.ts                  # polls (Pro)
│   └── config.ts                 # app config, feature flags
├── stores/
│   ├── authStore.ts              # JWT token, user, site URL
│   ├── feedStore.ts              # Posts cache
│   └── settingsStore.ts         # Dark mode, preferences
├── hooks/
│   ├── useAuth.ts                # Auth state + actions
│   ├── usePosts.ts               # React Query hooks for posts
│   ├── useSpaces.ts              # React Query hooks for spaces
│   └── useNotifications.ts       # Notification polling
├── theme/
│   ├── colors.ts                 # Dynamic from app config accent_color
│   ├── typography.ts             # Font scale
│   └── spacing.ts                # Layout constants
├── utils/
│   ├── html.ts                   # Strip/render HTML content safely
│   ├── date.ts                   # Relative time formatting
│   └── api-discovery.ts          # Validate site URL, detect Jetonomy
├── app.json                      # Branding injected per customer
├── eas.json                      # EAS Build profiles
└── package.json
```

## Screens Detail

### Login Screen
```
┌─────────────────────────┐
│       [App Logo]        │
│                         │
│  Site URL               │
│  ┌───────────────────┐  │
│  │ mycommunity.com   │  │
│  └───────────────────┘  │
│                         │
│  Username               │
│  ┌───────────────────┐  │
│  │                   │  │
│  └───────────────────┘  │
│                         │
│  Password               │
│  ┌───────────────────┐  │
│  │                   │  │
│  └───────────────────┘  │
│                         │
│  ┌───────────────────┐  │
│  │     Sign In       │  │
│  └───────────────────┘  │
└─────────────────────────┘
```

**Flow:**
1. User enters site URL
2. App calls `GET {url}/wp-json/jetonomy/v1` - validates Jetonomy is installed
3. User enters credentials
4. App calls `POST {url}/wp-json/jetonomy/v1/auth/token` - gets JWT
5. App calls `GET {url}/wp-json/jetonomy/v1/app/config` - gets branding + features
6. Navigate to home

For white-label builds: site URL hardcoded, only username/password shown.

### Home Feed (Tab 1)
```
┌─────────────────────────┐
│  [Sort: Hot | New | Top] │
│─────────────────────────│
│  ▲ 42  React Hooks Q&A  │
│  ▼     in React Space    │
│        12 replies · 2h   │
│─────────────────────────│
│  ▲ 18  How to deploy...  │
│  ▼     in DevOps Space   │
│        5 replies · 4h    │
│─────────────────────────│
│  ▲ 7   New feature idea  │
│  ▼     in Feature Req    │
│        3 replies · 1d    │
│─────────────────────────│
│                          │
│  [Home] [Spaces] [🔔] [💬] [👤] │
└─────────────────────────┘
```

**API:** `GET /spaces/{id}/posts?sort=hot&per_page=20&cursor=X`
- Pull to refresh
- Infinite scroll with cursor pagination
- Tap post → post detail
- Tap space name → space feed

### Spaces List (Tab 2)
```
┌─────────────────────────┐
│  Search spaces...        │
│─────────────────────────│
│  📚 Category: Learning   │
│  ├── 💬 React Space (42) │
│  ├── 💬 Node.js (28)     │
│  └── 💬 Python (15)      │
│                          │
│  📚 Category: Community  │
│  ├── 💬 General (89)     │
│  └── 💬 Announcements    │
│─────────────────────────│
│  [Home] [Spaces] [🔔] [💬] [👤] │
└─────────────────────────┘
```

**API:** `GET /categories` + `GET /spaces`

### Post Detail
```
┌─────────────────────────┐
│  ← React Space          │
│─────────────────────────│
│  React Hooks Best        │
│  Practices for 2026      │
│                          │
│  Posted by @john · 2h    │
│  ▲ 42 ▼  💬 12  🔖      │
│                          │
│  [Full post content      │
│   with HTML rendered]    │
│                          │
│  [😀 👍 ❤️ +] reactions  │
│─────────────────────────│
│  Replies (12)            │
│                          │
│  @jane · 1h        [⤶]  │
│  Great article! I'd add  │
│  that useMemo...         │
│  ▲ 5 ▼  [Reply]         │
│                          │
│    @bob · 30m            │
│    Agreed, also...       │
│    ▲ 2 ▼                 │
│─────────────────────────│
│  [Write a reply...]      │
└─────────────────────────┘
```

**API:** `GET /posts/{id}` + `GET /posts/{id}/replies` + `GET /posts/{id}/reactions`
- Vote buttons inline
- Threaded replies (3 levels)
- Reply composer at bottom
- Quote button [⤶] on each reply
- Reactions bar (Pro)
- Bookmark [🔖] toggle
- Poll embedded if exists (Pro)

### Create Post
```
┌─────────────────────────┐
│  ← New Post              │
│─────────────────────────│
│  Space: [React Space ▼]  │
│  Type:  [Topic | Q&A ▼]  │
│                          │
│  Title                   │
│  ┌───────────────────┐   │
│  │                   │   │
│  └───────────────────┘   │
│                          │
│  Similar topics:         │
│  • React Hooks Q&A (12)  │
│  • React Best Prac (42)  │
│                          │
│  Content                 │
│  ┌───────────────────┐   │
│  │ Rich text editor  │   │
│  │ B I U Link Image  │   │
│  │                   │   │
│  └───────────────────┘   │
│                          │
│  [📎 Attach] [Post]     │
└─────────────────────────┘
```

**API:** `POST /spaces/{id}/posts`
- Space selector dropdown
- Similar topics on title input (debounced FULLTEXT search)
- Rich text editor (markdown or basic HTML)
- Image upload via WP media REST

### Notifications (Tab 3)
```
┌─────────────────────────┐
│  Notifications    [✓ All]│
│─────────────────────────│
│  🔵 @jane replied to     │
│     your post "React..." │
│     2 minutes ago        │
│─────────────────────────│
│     @bob mentioned you   │
│     in "DevOps Setup"    │
│     1 hour ago           │
│─────────────────────────│
│  [Home] [Spaces] [🔔] [💬] [👤] │
└─────────────────────────┘
```

**API:** `GET /notifications` + `PATCH /notifications/{id}` + `POST /notifications/mark-all-read`
- Unread badge on tab icon via `GET /notifications/unread-count`
- Tap → navigate to post/reply
- Mark all read button

### Messages (Tab 4 - Pro only)
```
┌─────────────────────────┐
│  Messages         [New]  │
│─────────────────────────│
│  🔵 Jane Smith           │
│     Sure, I'll check...  │
│     2m ago               │
│─────────────────────────│
│     Dev Team Chat        │
│     Bob: Deployed v1.2   │
│     1h ago               │
│─────────────────────────│
│  [Home] [Spaces] [🔔] [💬] [👤] │
└─────────────────────────┘
```

**API:** `GET /conversations` + `GET /conversations/{id}/messages` + `POST /conversations/{id}/messages`
- Tab hidden if Pro messaging not active (detected via app config)
- Unread count badge

### Profile (Tab 5)
```
┌─────────────────────────┐
│  @john                   │
│  [Avatar]                │
│  John Smith              │
│  Trust Level 3 · 🏆 420  │
│                          │
│  Posts: 42 | Replies: 128│
│  Badges: [🥇] [🥈] [⭐]  │
│─────────────────────────│
│  [My Posts] [Bookmarks]  │
│  [Settings] [Logout]    │
│─────────────────────────│
│  [Home] [Spaces] [🔔] [💬] [👤] │
└─────────────────────────┘
```

**API:** `GET /users/me` + `GET /users/{id}/badges` + `GET /users/{id}/posts`

## API Client

```typescript
// api/client.ts
import axios from 'axios';
import * as SecureStore from 'expo-secure-store';

const client = axios.create();

client.interceptors.request.use(async (config) => {
  const siteUrl = await SecureStore.getItemAsync('site_url');
  const token = await SecureStore.getItemAsync('jwt_token');

  config.baseURL = `${siteUrl}/wp-json/jetonomy/v1`;

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

export default client;
```

## Feature Detection

```typescript
// api/config.ts
interface AppConfig {
  site_name: string;
  accent_color: string;
  logo_url: string;
  features: {
    messaging: boolean;
    reactions: boolean;
    polls: boolean;
    badges: boolean;
    custom_fields: boolean;
    web_push: boolean;
  };
  jetonomy_version: string;
  pro_active: boolean;
}

export const getAppConfig = () =>
  client.get<AppConfig>('/app/config');
```

App hides/shows tabs and features based on this config. Messaging tab hidden if `features.messaging = false`.

## Auth Store

```typescript
// stores/authStore.ts
interface AuthState {
  siteUrl: string | null;
  token: string | null;
  user: User | null;
  appConfig: AppConfig | null;
  login: (siteUrl: string, username: string, password: string) => Promise<void>;
  logout: () => void;
}
```

## Theme System

Colors derived from `appConfig.accent_color`:

```typescript
// theme/colors.ts
export const buildTheme = (accent: string) => ({
  primary: accent,
  primaryLight: lighten(accent, 0.9),
  background: '#ffffff',
  backgroundDark: '#0f0f0f',
  text: '#1a1a1a',
  textSecondary: '#6b7280',
  border: '#e5e7eb',
  success: '#10b981',
  danger: '#ef4444',
  warning: '#f59e0b',
});
```

Dark mode: toggle in settings, persisted in AsyncStorage.

## Offline Support

React Query provides:
- Cached feeds - app works offline with stale data
- Optimistic updates - vote/reply feels instant, syncs in background
- Retry on reconnect - failed requests auto-retry

## Push Notifications

1. App registers for Expo Push on first launch
2. Sends push token to `POST /push/subscribe` with device info
3. When Jetonomy fires a notification (reply, mention, message):
   - Web Push extension checks for registered push tokens
   - Sends via Expo Push API
4. Tap notification → deep link to post/reply/conversation

## Development Phases

### Phase 1 - Core (Week 1-2)
- [ ] Expo project setup with Router + NativeWind
- [ ] Auth flow: site URL validation → login → JWT storage
- [ ] Home feed with PostCard component
- [ ] Post detail with replies (threaded)
- [ ] Vote buttons (optimistic update)
- [ ] Spaces list with categories

### Phase 2 - Interaction (Week 3-4)
- [ ] Create post with space selector
- [ ] Reply composer
- [ ] Search screen
- [ ] Notifications tab with unread badge
- [ ] User profile screen
- [ ] Pull to refresh + infinite scroll

### Phase 3 - Pro Features (Week 5)
- [ ] Messaging tab (conversations + thread)
- [ ] Reactions picker
- [ ] Polls display + vote
- [ ] Badges on profile
- [ ] Feature detection (show/hide Pro features)

### Phase 4 - Polish (Week 6)
- [ ] Dark mode
- [ ] Bookmarks
- [ ] Similar topics on create post
- [ ] Push notifications
- [ ] Offline reading
- [ ] Image upload in posts
- [ ] App settings screen

### Phase 5 - White-label (Week 7)
- [ ] Branding from app.json (name, icon, colors)
- [ ] Hardcoded site URL mode for white-label
- [ ] EAS build profiles
- [ ] Test builds for Android + iOS
