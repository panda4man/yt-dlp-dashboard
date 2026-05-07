# Dark Mode / Light Mode Design Spec
_Date: 2026-05-07_

## Context

The app currently uses hardcoded light-mode Tailwind classes throughout all Vue components. This adds dark mode support that automatically follows the user's system preference (`prefers-color-scheme`) while also allowing a manual toggle in the nav bar. The manual preference persists across sessions via `localStorage`.

---

## Approach

**Class-based dark mode** using Tailwind v4's `@custom-variant`. A `dark` class on `<html>` activates all `dark:` utility variants. JS determines the initial class before the page renders (no flash), then a Vue composable manages toggling and system preference syncing.

---

## Changes

### `resources/css/app.css`

Add one line to register the dark variant:

```css
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
```

### `resources/views/app.blade.php`

Inline `<script>` injected **before `<body>`** to set the class synchronously — prevents flash of wrong theme on load:

```html
<script>
  (function() {
    const t = localStorage.getItem('theme');
    if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  })();
</script>
```

### `resources/js/composables/useTheme.js` _(new file)_

Composable that encapsulates all theme logic:

- `isDark` — reactive ref, true when `<html>` has `dark` class
- `toggle()` — flips dark/light, writes `'dark'`/`'light'` to `localStorage`
- `clearOverride()` — removes localStorage key, reverts to system preference
- Registers a `MediaQueryList` listener on `(prefers-color-scheme: dark)` — fires only when no manual override is stored, keeping system-auto behaviour when the user hasn't explicitly toggled

### `resources/js/Layouts/AppLayout.vue`

- Import and use `useTheme` composable
- Add sun/moon toggle button in nav bar (right side, next to Sign out)
- Sun icon shown when dark mode is active (click → go light)
- Moon icon shown when light mode is active (click → go dark)
- Icons: inline SVG, no external icon library

### Vue pages — `dark:` variant additions

Every hardcoded light-mode color class gets a paired `dark:` class. Mapping:

| Light | Dark |
|---|---|
| `bg-gray-50` | `dark:bg-gray-900` |
| `bg-gray-100` | `dark:bg-gray-800` |
| `bg-white` | `dark:bg-gray-800` |
| `border-gray-100` | `dark:border-gray-700` |
| `border-gray-200` | `dark:border-gray-700` |
| `border-gray-300` | `dark:border-gray-600` |
| `text-gray-900` | `dark:text-gray-100` |
| `text-gray-800` | `dark:text-gray-200` |
| `text-gray-700` | `dark:text-gray-300` |
| `text-gray-600` | `dark:text-gray-400` |
| `text-gray-500` | `dark:text-gray-400` |
| `text-gray-400` | `dark:text-gray-500` |
| `text-gray-300` | `dark:text-gray-600` |
| `hover:bg-gray-100` | `dark:hover:bg-gray-700` |
| `hover:bg-gray-900` | `dark:hover:bg-gray-700` |
| `bg-gray-800` (nav) | `dark:bg-gray-900` |
| `focus:ring-blue-500` | `dark:focus:ring-blue-400` |
| Input borders | `dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100` |

Status badge backgrounds keep their hue but shift to darker tones:

| Light | Dark |
|---|---|
| `bg-yellow-100 text-yellow-800` | `dark:bg-yellow-900 dark:text-yellow-200` |
| `bg-blue-100 text-blue-800` | `dark:bg-blue-900 dark:text-blue-200` |
| `bg-green-100 text-green-800` | `dark:bg-green-900 dark:text-green-200` |
| `bg-red-100 text-red-800` | `dark:bg-red-900 dark:text-red-200` |

Pages affected: `Login.vue`, `Dashboard.vue`, `History.vue`, `AppLayout.vue`

---

## Behaviour

| Scenario | Result |
|---|---|
| First visit, system = dark | Dark mode on, no localStorage entry |
| First visit, system = light | Light mode on, no localStorage entry |
| User toggles to dark | `localStorage.theme = 'dark'`, `dark` class added |
| User toggles back to light | `localStorage.theme = 'light'`, `dark` class removed |
| System changes while app open, no override | App follows system change in real time |
| System changes while app open, override set | App ignores system change |
| Page reload with override | Inline script restores preference before render |

---

## Scope

- Frontend-only. No PHP changes, no new routes, no DB changes.
- No new npm dependencies.
- `useTheme.js` composable is the single source of truth for theme state.
