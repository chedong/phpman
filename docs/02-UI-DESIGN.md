---
name: phpMan
colors:
  bg-main: "#1a1b26"
  bg-sidebar: "#24283b"
  bg-input: "#24283b"
  bg-tldr: "#24283b"
  bg-tldr-header: "#1f2335"
  bg-code: "#1a1b26"
  text-body: "#c0caf5"
  text-secondary: "#a9b1d6"
  text-muted: "#787c99"
  text-link: "#7aa2f7"
  text-bold: "#e0af68"
  text-underline: "#9ece6a"
  text-button: "#1a1b26"
  border-primary: "#3b4261"
  border-active: "#89b4fa"
  button-bg: "#7aa2f7"
  button-hover: "#89b4fa"
  btn-bg: "#7aa2f7"
  btn-hover: "#89b4fa"
  input-border: "#3b4261"
  input-focus: "#7aa2f7"
  tldr-border: "#3b4261"
  tldr-desc: "#a9b1d6"
  toc-border: "#3b4261"
typography:
  body:
    fontFamily: monospace
    fontSize: 14px
    lineHeight: 1.5
  body-mobile:
    fontSize: 12px
    lineHeight: 1.4
  pre:
    fontFamily: inherit
    fontSize: inherit
    whiteSpace: pre
  pre-mobile:
    whiteSpace: pre-wrap
  h1:
    fontSize: 14px
  h2:
    fontSize: 14px
    color: "#7aa2f7"
    borderBottom: "1px solid #3b4261"
  code:
    fontSize: 14px
    color: "#9ece6a"
  input:
    fontSize: 14px
    fontFamily: inherit
  input-mobile:
    fontSize: 14px
    minHeight: 44px
  button:
    fontSize: 14px
    fontFamily: inherit
  button-mobile:
    fontSize: 14px
    minHeight: 44px
  link-label:
    fontSize: 12px
rounded:
  sm: 2px
  md: 3px
  lg: 4px
  xl: 6px
  full: 50%
spacing:
  xs: 4px
  sm: 6px
  md: 8px
  lg: 12px
  xl: 16px
  xxl: 20px
layout:
  maxWidth: 90%
  sidebarWidth: 200px
  sidebarMobileWidth: 220px
  alphaSidebarWidth: 30px
  sidebarOffset: 230px
  contentMargin: 0
  contentPaddingMobile: "0 8px"
  breakpoint: 1024px
---

## Overview

phpMan is a single-file PHP web application that wraps Unix `man`, `perldoc`, `info`,
`pydoc3`, and `ri` commands into HTML, Markdown, JSON, and MCP responses.

**Design philosophy**: Dark, high-contrast terminal aesthetic with functional
readability. Every design choice defaults to respecting the user's mental model
of Unix documentation — monospace type, terminal color semantics (green =
underline/hyperlink, gold = bold/emphasis), and minimal chrome.

**Theme**: Tokyo Night — a dark blue-black palette optimized for long reading
sessions of technical documentation.

## Colors

Tokyo Night provides a low-blue-light dark background with warm semantic
accents. All colors derive from the Tokyo Night palette:

| Token | Hex | Role |
|-------|-----|------|
| `bg-main` | `#1a1b26` | Page background, code block background |
| `bg-sidebar` | `#24283b` | TOC sidebar, TLDR block, alphabet index, input fields |
| `bg-tldr-header` | `#1f2335` | TLDR block header (slightly darker) |
| `text-body` | `#c0caf5` | Primary body text |
| `text-secondary` | `#a9b1d6` | Sidebar links, secondary labels |
| `text-muted` | `#787c99` | TLDR source label, sub-items |
| `text-link` | `#7aa2f7` | Hyperlinks, H2 headings, buttons |
| `text-bold` | `#e0af68` | Bold text (`<b>`) — warm gold |
| `text-underline` | `#9ece6a` | Underline text (`<u>`) — terminal green |
| `border-primary` | `#3b4261` | All borders (sidebar, TLDR, inputs, fieldset) |
| `button-bg` | `#7aa2f7` | Submit button, back-to-top button |
| `button-hover` | `#89b4fa` | Button/link hover state |

**Rationale**: Gold bold and green underline mimic terminal rendering conventions
from man page output. Blue links maintain web usability expectations without
breaking the dark theme.

## Typography

| Token | Value | Usage |
|-------|-------|-------|
| `body` | monospace 14px / 1.5 | All pages |
| `body-mobile` | monospace 12px / 1.4 | Screens ≤1024px |
| `pre` | inherit / pre | Man page content — preserves whitespace |
| `pre-mobile` | pre-wrap | Mobile — wraps long lines |
| `h2` | 14px, `#7aa2f7`, bottom border | Section headers in search results |
| `code` | 14px, `#9ece6a` | TLDR example commands |
| `input` | inherit 14px | Search input |
| `input-mobile` | 14px / min-height 44px | Touch-friendly input sizing |
| `button` | inherit 14px | Submit buttons |
| `button-mobile` | 14px / min-height 44px | Touch-friendly button sizing |
| `link-label` | 12px | Navigation mode labels |

**Rationale**: Monospace is non-negotiable — man pages are monospace documents.
Single font size (14px) with line-height 1.5 balances density and readability.
Mobile breakpoint drops to 12px to fit content on narrow screens. Minimum 44px
touch targets on mobile per WCAG.

## Layout

| Token | Value | Usage |
|-------|-------|-------|
| `maxWidth` | 90% | Content area maximum width |
| `sidebarOffset` | 230px | Right margin for content area to clear sidebar |
| `sidebarWidth` | 200px | Desktop TOC sidebar width |
| `sidebarMobileWidth` | 220px | Mobile TOC sidebar width |
| `alphaSidebarWidth` | 30px | Alphabet index sidebar (compact vertical) |
| `breakpoint` | 1024px | Narrow/wide screen threshold |

Desktop layout: content left + TOC sidebar fixed right (200px) + optional
alphabet index sidebar (30px) between them. Mobile: TOC collapses to a title
row; tap to expand overlay sidebar with drop shadow.

## Elevation & Depth

Minimal depth — two z-index layers:

| Layer | z-index | Element |
|-------|---------|---------|
| Content | 0 | Page content, form |
| Overlay | 100 | TOC sidebar, alphabet index |
| Top | 200 | Mobile expanded TOC (above other sidebars) |
| Topmost | 210 | Back-to-top button (above expanded TOC) |

No shadows on desktop (flat layout). Mobile expanded TOC gets `box-shadow:
-2px 2px 8px rgba(0,0,0,.4)` for depth cue.

## Shapes

| Token | Value | Usage |
|-------|-------|-------|
| `sm` | 2px | TOC links, code blocks |
| `md` | 3px | Submit button |
| `lg` | 4px | TLDR block, alpha index, TOC sidebar |
| `xl` | 6px | Back-to-top button |
| `full` | 50% | (reserved) |

Borders are consistently `1px solid {border-primary}`. No box-shadows on desktop
elements — the dark background provides sufficient contrast.

## Components

### Search Form
- `backgroundColor`: `transparent`
- `input`: `backgroundColor: {bg-input}`, `border: 1px solid {border-primary}`, `color: {text-body}`, `fontFamily: inherit`, `fontSize: 14px`, `padding: 4px 6px`
- `input-mobile`: `width: 100%`, `fontSize: 14px`, `padding: 8px`, `boxSizing: border-box`
- `button`: `backgroundColor: {button-bg}`, `color: {text-button}`, `border: none`, `padding: 4px 12px`, `fontFamily: inherit`, `fontSize: 14px`, `borderRadius: {md}`
- `button-mobile`: `fontSize: 14px`, `padding: 10px 20px`, `minHeight: 44px`
- `button-hover`: `backgroundColor: {button-hover}`
- `radio`: `accentColor: {text-link}`
- `fieldset`: `border: 1px solid {border-primary}`
- `legend`: `color: {text-secondary}`

### TOC Sidebar
- `position`: fixed, `top: 20px`, `right: 10px`
- `width`: 200px desktop, 220px mobile
- `maxHeight`: 90vh, `overflowY`: auto
- `backgroundColor`: `{bg-sidebar}`, `border: 1px solid {border-primary}`, `padding: {md}`
- `links`: `display: block`, `whiteSpace: nowrap`, `overflow: hidden`, `textOverflow: ellipsis`, `color: {text-secondary}`, `padding: 2px 4px`, `borderRadius: {sm}`
- `links-hover`: `backgroundColor: {border-primary}`, `color: {text-body}`
- `sub-links`: `paddingLeft: 18px`, `color: {text-muted}`
- `title`: `fontWeight: bold`, `borderBottom: 1px solid {border-primary}`, `color: {text-body}`
- Mobile toggle: `body.toc-open` class reveals links; toggle icons: `□` (expand) / `✕` (close)

### TLDR Block
- `backgroundColor`: `{bg-tldr}`, `border: 1px solid {tldr-border}`, `borderRadius: {lg}`, `margin: 8px 0 16px 0`
- `header`: `padding: {lg}`, `fontWeight: bold`, `fontSize: 14px`, `color: {text-body}`, `backgroundColor: {bg-tldr-header}`
- `source`: `fontWeight: normal`, `fontSize: 12px`, `color: {text-muted}`, `marginLeft: {sm}`
- `body`: `padding: {xs} {lg} {md} {lg}`
- `description`: `color: {text-secondary}`, `fontStyle: italic`
- `examples`: list-style none, `margin: {sm} 0`
- `code`: `backgroundColor: {bg-code}`, `color: {text-underline}`, `border: 1px solid {border-primary}`, `borderRadius: {sm}`, `padding: 1px 4px`
- `code-bold`: `color: {text-bold}`

### Back to Top
- `position`: fixed, `bottom: 20px`, `right: 20px`
- `backgroundColor`: `{button-bg}`, `color: {text-button}`, `padding: {md} 14px`, `borderRadius: {xl}`, `fontSize: 14px`, `fontFamily: monospace`
- `hover`: `backgroundColor: {button-hover}`
- Desktop: hidden by default, visible via `body.ext-nav`

### Alphabet Index
- `position`: fixed, `top: 20px`, `right: 10px`, `width: 30px`
- `container`: `backgroundColor: {bg-sidebar}`, `border: 1px solid {border-primary}`, `borderRadius: {lg}`, `display: flex`, `flexDirection: column`
- `links`: `textAlign: center`, `padding: 0 2px`, `fontSize: 12px`, `color: {text-link}`, `lineHeight: 1.7`
- `links-hover`: `backgroundColor: {border-primary}`, `color: {text-body}`
- `empty`: `color: {border-primary}`, `pointerEvents: none`

### Profiling (debug)
- `margin: {xl} 0`, `padding: {md}`, `border: 1px solid #e0e0e0`, `backgroundColor: #f5f5f5`, `fontSize: 12px`, `color: #333`, `fontFamily: monospace`
- Note: Light theme — profiling block is admin-only, intentionally distinct from dark page theme.

## Do's and Don'ts

### DO
- Use Tokyo Night colors from tokens — never hardcode hex values in new CSS
- Keep monospace everywhere — man page content and UI
- Test at ≤1024px for mobile layout (collapsed TOC, stacked form)
- Use `rem` for new type sizes to respect user font preferences
- Keep XHTML 1.0 Transitional compliance — no HTML5 semantic tags (`<nav>`, `<section>`)
- Use `body.toc-open` / `body.ext-nav` class-based state toggles

### DON'T
- Don't add `og:` meta tags — `property` attribute incompatible with XHTML
- Don't use `em` for spacing — not needed for monospace contexts
- Don't add shadows on desktop — the dark theme provides sufficient contrast without depth cues
- Don't change text-bold from gold (`#e0af68`) — it's semantic: bold = emphasis in terminal output
- Don't change text-underline from green (`#9ece6a`) — it's semantic: underline = hyperlink/annotation
- Don't add new font families — monospace is the identity
- Don't use `border-radius` > 6px except for circular elements
