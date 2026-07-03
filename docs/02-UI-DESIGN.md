---
name: phpMan
colors:
  surface-root: "#0b0b14"
  surface-card: "#131320"
  surface-elevated: "#181828"
  surface-field: "#1e1e32"
  text-primary: "#e2e2f0"
  text-secondary: "#a8a8bc"
  text-muted: "#62627a"
  accent-amber: "#e0af68"
  accent-blue: "#6d9ef0"
  accent-blue-hover: "#89b4fa"
  accent-green: "#8ec88e"
  accent-red: "#e0556a"
  border-default: "#222236"
  border-hover: "#353550"
  border-active: "#4c4c6e"
  alpha-100: "rgba(255,255,255,0.04)"
  alpha-200: "rgba(255,255,255,0.07)"
  alpha-300: "rgba(255,255,255,0.12)"
  alpha-400: "rgba(255,255,255,0.18)"
  btn-bg: "#e2e2f0"
  btn-text: "#0b0b14"
  btn-hover-bg: "#ffffff"
typography:
  ui:
    fontFamily: "-apple-system, BlinkMacSystemFont, Segoe UI, system-ui, sans-serif"
  mono:
    fontFamily: "SF Mono, Cascadia Code, Menlo, Consolas, DejaVu Sans Mono, monospace"
  h1:
    fontSize: 22px
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "-0.02em"
  h2:
    fontSize: 15px
    fontWeight: 500
    fontFamily: ui
  h3:
    fontSize: 13px
    fontWeight: 500
    fontFamily: ui
  body:
    fontSize: 14px
    lineHeight: 1.55
  body-mobile:
    fontSize: 13px
  code:
    fontSize: 13px
    lineHeight: 1.55
    fontFamily: mono
  code-mobile:
    fontSize: 12px
    lineHeight: 1.45
  small:
    fontSize: 12px
    lineHeight: 1.5
rounded:
  sm: 2px
  md: 4px
  lg: 6px
  xl: 12px
  full: 9999px
spacing:
  "1": 4px
  "2": 8px
  "3": 12px
  "4": 16px
  "6": 24px
  "8": 32px
  "10": 40px
motion:
  ease-out: "cubic-bezier(0.175, 0.885, 0.32, 1.1)"
  fast: 150ms
  normal: 200ms
layout:
  maxWidth: 900px
  sidebarWidth: 200px
  sidebarMobileWidth: 220px
  alphaSidebarWidth: 32px
  sidebarOffset: 230px
  contentPadding: "16px 24px"
  contentPaddingMobile: "8px 12px"
  breakpoint: 1024px
shadows:
  card: "0 1px 2px rgba(0,0,0,0.16)"
  popover: "0 1px 1px rgba(0,0,0,0.08), 0 4px 8px -4px rgba(0,0,0,0.12), 0 16px 24px -8px rgba(0,0,0,0.16)"
---

## Overview

phpMan is a single-file PHP web application that wraps Unix `man`, `perldoc`, `info`,
`pydoc3`, and `ri` commands into HTML, Markdown, JSON, and MCP responses.

**Design philosophy (v4.8)**: "Calibrated Terminal" — a Geist-inspired token system
applied to terminal-native colors. UI chrome uses system sans-serif for scannability;
content stays monospace to honor its terminal origins. Alpha-layered depth replaces
flat opaque surfaces.

**Informed by**: [Vercel Geist Design System](https://vercel.com/design) —
semantic token scales, 4px spacing unit, focus ring system, motion primitives,
alpha overlay depth model.

## Color System

### Dark mode (native — terminals are dark)

Cool ink surfaces warm up through amber-gold accents. The warm/cool tension
creates character: blue links are functional and cool, gold bold/underline
is the terminal's natural emphasis.

| Token | Hex | Role |
|-------|-----|------|
| `surface-root` | `#0b0b14` | Deepest page background — ink black with blue undertone |
| `surface-card` | `#131320` | Cards, sidebar, TLDR block, form fieldset |
| `surface-elevated` | `#181828` | Code blocks, elevated surfaces |
| `surface-field` | `#1e1e32` | Input fields — lighter than card for contrast |
| `text-primary` | `#e2e2f0` | Body text, headings — 4.5:1+ contrast |
| `text-secondary` | `#a8a8bc` | Metadata, sidebar links, form labels |
| `text-muted` | `#62627a` | Disabled text, TLDR source, timestamp |
| `accent-amber` | `#e0af68` | Bold text, underlines — terminal gold |
| `accent-blue` | `#6d9ef0` | Links, focus ring — functional cool |
| `accent-blue-hover` | `#89b4fa` | Link/button hover |
| `accent-green` | `#8ec88e` | Copy success, underline annotation |
| `accent-red` | `#e0556a` | Errors |
| `border-default` | `#222236` | Default borders |
| `border-hover` | `#353550` | Interactive border hover |
| `border-active` | `#4c4c6e` | Focus/active borders |
| `alpha-100`–`400` | `rgba(255,255,255,0.04)`–`0.18` | Translucent overlays for hover/active depth |
| `btn-bg` | `#e2e2f0` | Primary button — inverted surface |
| `btn-text` | `#0b0b14` | Button text |

### Light mode: Hakusho (白書) — Scholar's Manuscript

Same semantic token names, different values. Warm paper tones replace cool ink.
Auto-switch via `prefers-color-scheme`. Manual override via `[data-theme]`.

| Token | Hex | Role |
|-------|-----|------|
| `surface-root` | `#fafaf6` | Warm paper white |
| `surface-card` | `#f0ede4` | Recessed panel — tatami straw |
| `surface-elevated` | `#eae6dc` | Code blocks — paper shadow |
| `surface-field` | `#ffffff` | Input fields — fresh paper |
| `text-primary` | `#1a1a26` | Body text — sumi ink |
| `text-secondary` | `#4a4a5e` | Secondary text |
| `text-muted` | `#888898` | Muted metadata |
| `accent-amber` | `#b35c00` | Bold — burnt amber |
| `accent-blue` | `#2e5c8a` | Links — indigo |
| `accent-green` | `#4a7a5e` | Underline — pine |
| `border-default` | `#ddd8cc` | Borders — paper shadow |
| `alpha-100`–`400` | `rgba(0,0,0,0.04)`–`0.16` | Dark translucent overlays |
| `btn-bg` | `#1a1a26` | Primary button |
| `btn-text` | `#ffffff` | Button text |

### Why not Solarized / One Light / Nord Light

All three lean cool (blue-gray or beige-gray). Hakusho leans **warm** — aged
paper, charcoal ink. It has material quality: tactile rather than synthetic.

## Switching Mechanism

Three-layer cascade (highest specificity wins):

1. `:root` — Tokyo Night dark defaults
2. `@media (prefers-color-scheme: light)` — Hakusho light, follows OS
3. `:root[data-theme="dark|light"]` — manual override, set by JS `localStorage`

JS persists choice to `localStorage` key `phpman-theme-v2`. OS theme changes
are respected when no manual override is active.

## Typography

**The sans/mono split (v4.8 signature change):**

UI chrome (H1 breadcrumb, form, sidebar, footer) uses system sans-serif.
Content (`#man-content`, `pre`, `code`) stays monospace. This visual separation
between the reading environment and the terminal artifact is the design's core
typographic risk.

| Token | Value | Usage |
|-------|-------|-------|
| `font-ui` | `-apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif` | Chrome: H1, form, sidebar, footer, TLDR header |
| `font-mono` | `'SF Mono', 'Cascadia Code', Menlo, Consolas, 'DejaVu Sans Mono', monospace` | Content: `#man-content`, `pre`, `code`, text input |
| `h1` | 22px/600w/1.3/-0.02em, ui | Breadcrumb — clear but compact |
| `h2` | 15px/500w, ui | Section headers in enhanced HTML — bottom border |
| `h3` | 13px/500w, ui | Sub-section headers |
| `h4` | 12px/500w, ui, uppercase `+0.05em` | Minor labels |
| `body` | 14px/1.55, ui | Default page text |
| `code`/`pre` | 13px/1.55, mono | Man page content |
| `small` | 12px, ui/mono | Metadata, captions |
| `code-mobile` | 12px/1.45, mono | Content on narrow screens |
| `body-mobile` | 13px, ui | UI on narrow screens |

**Rationale**: Man pages are monospace documents — that's non-negotiable. But the
chrome around them (navigation, form, footer, sidebar) doesn't need to be monospace.
Vercel Geist uses Geist Sans for UI and Geist Mono for code; we do the same with
system fonts. This improves scannability and gives the page a clearer hierarchy.

## Layout

| Token | Value | Usage |
|-------|-------|-------|
| `maxWidth` | 900px | Content area — man pages are narrow by nature |
| `sidebarOffset` | 230px | Right margin for TOC sidebar clearance |
| `sidebarWidth` | 200px | Desktop TOC sidebar |
| `sidebarMobileWidth` | 220px | Mobile TOC overlay drawer |
| `alphaSidebarWidth` | 32px | Alphabet index sidebar (compact vertical) |
| `breakpoint` | 1024px | Mobile/desktop threshold |

Desktop: content left + TOC sidebar fixed right (200px). Mobile: TOC collapses
to a title row; tap to expand overlay drawer with drop shadow.

## Spacing Scale

Based on a 4px unit (from Vercel Geist):

| Token | Value | Usage |
|-------|-------|-------|
| `space-1` | 4px | Tight — icon padding, inline gaps |
| `space-2` | 8px | Inside groups — form gaps, list item padding |
| `space-3` | 12px | Comfortable — TLDR body, table cells |
| `space-4` | 16px | Between groups — section padding |
| `space-6` | 24px | Section separation — heading top margin |
| `space-8` | 32px | Major sections — H2 top margin |
| `space-10` | 40px | Page-level — footer separation |

## Elevation & Depth

Three z-index layers, now with shadows for depth cues:

| Layer | z-index | Element | Shadow |
|-------|---------|---------|--------|
| Content | 0 | Page content, form, code blocks | — |
| Overlay | 100 | TOC sidebar, alpha index | `popover` |
| Top | 200 | Theme toggle, mobile expanded TOC | `popover` |
| Topmost | 210 | Back-to-top button | `card` |

### Alpha Depth Model (v4.8)

Instead of opaque surface variants, hover/active states use translucent alpha
overlays. This creates depth through transparency — a terminal-native concept
(terminal emulators use transparency) applied with restraint:

- `alpha-100` (4%): Default surface tint on cards, TLDR item backgrounds
- `alpha-200` (7%): Hover state on sidebar links, copy button background
- `alpha-300` (12%): Active state, elevated hover
- `alpha-400` (18%): Heavy overlay — not currently used, reserved

No backdrop-filter blur — the depth comes from precise alpha stacking alone.

## Border Radii

| Token | Value | Usage |
|-------|-------|-------|
| `sm` | 2px | Focus ring outline |
| `md` | 4px | TLDR example items, copy button |
| `lg` | 6px | Default — buttons, inputs, cards, sidebars, code blocks |
| `xl` | 12px | (reserved for modals) |
| `full` | 9999px | (reserved for pills) |

Consistent 6px radius on all surface-level elements. No mixing of sharp
and round corners within a single view (Vercel Geist principle).

## Motion

| Token | Value | Usage |
|-------|-------|-------|
| `ease-out` | `cubic-bezier(0.175, 0.885, 0.32, 1.1)` | All transitions |
| `fast` | 150ms | State changes: hover, toggle, focus |
| `normal` | 200ms | Overlays: TOC expand, popover appear |

Instant (0ms) is the default. Motion is added only when it clarifies a change.
`prefers-reduced-motion: reduce` kills all transitions and animations.

## Components

### Search Form (Toolbar)
- Wrapped in `<fieldset>` with card background + `card` shadow
- Legend hidden
- Single row on desktop: mode radios (`<span>`) → text input → Go button
- Text input: mono font, 14px, `lg` radius, `flex: 1` fills remaining space, focus glow ring (`0 0 0 3px rgba(109,158,240,0.2)`)
- Radio buttons: `accent-color: blue`, 14px labels, compact inline in `<span class="form-modes">`
- Submit button: inverted surface (`btn-bg` on `btn-text`), 14px, `lg` radius, scale(0.97) on active
- Desktop: single flex row, input `max-width: 400px`
- Mobile (≤1024px): vertical stack (`flex-direction: column`), input 16px, submit 16px, radios 13px, min 44px touch target

### TOC Sidebar
- Fixed position, `lg` radius, card background, `popover` shadow
- Title: 13px/600w, bottom border
- Links: 12px, secondary color, `md` radius, ellipsis overflow
- Hover: `alpha-200` background → primary text
- Sub-links (L2): 11px, muted, 16px left padding
- Mobile: collapsed to title row; tap `body.toc-open` to reveal; □/✕ toggle icons

### TLDR Block
- Card background, `lg` radius, `card` shadow
- Header: sans-serif 13px/600w, `alpha-100` background, bottom border
- Source label: 11px uppercase, muted
- Description: sans-serif 13px, italic, secondary color
- Examples: list items with `alpha-100` background, `md` radius
- Code in examples: mono 12px, green, elevated surface, `md` radius

### Code Block + Copy Button
- Elevated surface background, `lg` radius, default border
- Copy button: `alpha-200` bg, 11px/500w sans-serif, hidden until parent hover
- Hover: `alpha-300` → primary text
- Copied state: green background, white text
- Mobile: copy button always visible (no hover on touch)

### Back to Top
- Fixed bottom-right, `alpha-200` background, `lg` radius, 12px/500w sans-serif
- Border: 1px default
- Hover: `alpha-300` → primary text

### Alphabet Index (Search)
- Fixed position, 32px wide, card background, `lg` radius, `card` shadow
- Links: mono 11px, centered, blue
- Empty: border color, no pointer events
- Search result groups: amber H2 with 2px bottom border

### Footer
- Top border separator, 12px sans-serif, muted text
- Links: secondary → primary on hover
- Format links (Markdown · JSON · MCP): uppercase, 0.04em letter-spacing, muted → blue on hover
- Appear inline: "Generated by phpman v4.8 · **Markdown** · **JSON** · **MCP** Author: Che Dong …"

### Focus Ring
Two-layer box-shadow on all `:focus-visible` interactive elements:
```
box-shadow: 0 0 0 2px var(--surface-root),   /* 2px gap in surface color */
            0 0 0 4px var(--accent-blue);     /* 2px blue ring */
```
Never remove outlines without providing this replacement. Input fields get a
softer glow ring instead (3px blue at 20% opacity).

## Do's and Don'ts

### DO
- Use CSS custom properties from the design token system — never hardcode hex values
- Keep sans-serif for UI chrome, monospace for content — the split IS the design
- Test at ≤1024px for mobile layout
- Respect `prefers-reduced-motion` — test with it on
- Provide visible focus ring on every interactive element
- Keep XHTML 1.0 Transitional compliance — no HTML5 semantic tags
- Use `body.toc-open` / `body.ext-nav` class-based state toggles
- Maintain WCAG AA contrast (4.5:1 minimum for body text)

### DON'T
- Don't add `og:` meta tags — `property` attribute incompatible with XHTML
- Don't mix rounded and sharp corners within a single view
- Don't use more than two font weights (400, 500/600) in a single view
- Don't add backdrop-filter or heavy blur effects — alpha stacking is sufficient
- Don't change `accent-amber` from gold — it's semantic: bold = terminal emphasis
- Don't change `accent-green` from green — it's semantic: underline = annotation
- Don't add decorative animation — motion must clarify a state change
- Don't use `border-radius` > 12px except for circular/pill elements
