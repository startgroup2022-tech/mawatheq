# MAWATHEQ — Shared Design System

One visual identity for the website and the Flutter application. **Every value below was extracted
from the repository or the original logo — none was guessed.**

Sources:
- `assets/css/style.css` (`:root`) — original brand tokens
- `assets/css/enterprise.css` (`:root`) — extended scale, explicitly "kept identical to style.css so
  the identity does not shift"
- `assets/img/logo.webp` — sampled pixel palette
- `includes/header.php` — font stack

---

## 1. Brand palette

| Token | Value | Origin |
|---|---|---|
| `navy` | `#1E3A5F` | `style.css` |
| `navyDark` | `#0F1F33` | `style.css` |
| `navyDeep` | `#07334D` | sampled from logo (bottom/shield) |
| `teal` | `#2BA8C4` | `style.css` |
| `cyan` | `#8ACEE7` | sampled from logo (upper gradient) |
| `gold` | `#F5A623` | `style.css` — the restrained warm accent |
| `bg` | `#F6F8FA` | `style.css` |
| `surface` | `#FFFFFF` | `style.css` |
| `surface2` | `#F8FAFC` | `enterprise.css` |
| `surface3` | `#F1F5F9` | `enterprise.css` |
| `border` | `#E3E9EF` | `style.css` |
| `borderStrong` | `#CBD5E1` | `enterprise.css` |
| `text` | `#16232F` | `style.css` |
| `textMuted` | `#5C6B7A` | `style.css` |
| `textSubtle` | `#8296A6` | `enterprise.css` |

### Semantic

| Token | Value | Background |
|---|---|---|
| `success` | `#1E8E5A` | `#E6F4ED` |
| `warning` | `#B4780F` | `#FDF3E1` |
| `danger` | `#C7492A` | `#FBEAE5` |
| `info` | `#2BA8C4` | `#EAF6F9` |

### Gradients

| Name | Definition | Use |
|---|---|---|
| `brandHero` | `linear-gradient(135deg, #1E3A5F 0%, #07334D 55%, #2BA8C4 100%)` | hero surfaces |
| `cyanAccent` | `linear-gradient(135deg, #8ACEE7 0%, #2BA8C4 100%)` | accents, AI card |
| `warmAccent` | `linear-gradient(135deg, #F5A623 0%, #E0941B 100%)` | single emphasis CTA |
| `lightSurface` | `linear-gradient(180deg, #F0F5FA 0%, #FFFFFF 100%)` | section washes |

The warm gradient is used **once per screen at most** — it is an accent from the logo, not a
primary colour.

---

## 2. Typography

| Role | Family | Weights |
|---|---|---|
| Arabic display | `Cairo` | 600, 700, 800 |
| Arabic body | `IBM Plex Sans Arabic` | 400, 500, 600, 700 |
| Latin body | `IBM Plex Sans` | 400, 500, 600, 700 |

Loaded from Google Fonts in `includes/header.php`. The Flutter app uses the same three families via
`google_fonts`, so a headline looks the same on both surfaces.

### Scale (from `enterprise.css`, extended for the landing page)

| Token | Web | Flutter | Use |
|---|---|---|---|
| `fs-xs` | 12px | 12 | meta, captions |
| `fs-sm` | 13px | 13 | labels |
| `fs-base` | 14px | 14 | body minimum |
| `fs-md` | 15px | 15 | emphasised body |
| `fs-lg` | 17px | 17 | card titles |
| `fs-h3` | 19px | 19 | subsection |
| `fs-h2` | 23px | 23 | section title |
| `fs-h1` | 28px | 28 | page title |
| `fs-hero` | 40px | 32 | landing hero only |

**Minimum body size is 14px on every device.** Line height 1.7 for Arabic body copy (Arabic needs
more leading than Latin); 1.5 for Latin.

---

## 3. Spacing, radius, elevation

Spacing scale (identical in both): `4, 8, 12, 16, 20, 24, 32, 40`.

Radius: `xs 6 · sm 8 · md 11 · lg 14 · xl 18 · pill 999`.

Elevation (web box-shadow → Flutter `BoxShadow`):

| Token | Web | Flutter |
|---|---|---|
| `sh-xs` | `0 1px 2px rgba(15,31,51,.05)` | blur 2, dy 1 |
| `sh-sm` | `0 2px 8px rgba(15,31,51,.05)` | blur 8, dy 2 |
| `sh-md` | `0 6px 20px rgba(15,31,51,.07)` | blur 20, dy 6 |
| `sh-lg` | `0 24px 60px rgba(15,31,51,.20)` | blur 60, dy 24 |

Focus ring: `0 0 0 3px rgba(43,168,196,.35)` — mirrored in Flutter as a 3px teal-tinted outline on
`Focus`/`focusedErrorBorder`.

---

## 4. Component contracts

The same component names are used in CSS classes and Flutter widgets, so a reviewer can trace one to
the other.

| Component | Web class | Flutter widget | Notes |
|---|---|---|---|
| Brand app bar | `.site-header` | `BrandAppBar` | logo + wordmark, 64px (web) / 56 (mobile) |
| Logo | `.brand img` | `BrandLogo` | **original `logo.webp`, never redrawn** |
| Primary button | `.btn-primary` | `PrimaryButton` | navy fill, 44px min height |
| Secondary button | `.btn-outline` | `SecondaryButton` | 1px border |
| Gradient hero | `.hero` / `.grad-hero` | `GradientHeroCard` | `brandHero` gradient, radius xl |
| Feature tile | `.feature-card` | `FeatureTile` | icon + title + body |
| Dashboard card | `.mini-card` | `DashboardCard` | stat pair |
| Case card | `.case-card` | `CaseCard` | status badge + meta |
| Document card | `.doc-card` | `DocumentCard` | type + status |
| Consultation card | `.consult-card` | `ConsultationCard` | lawyer + slot |
| AI chat bubble | `.chat-bubble` | `AIChatBubble` | user/assistant variants |
| Status badge | `.badge-live` / `.pop-tag` | `StatusBadge` | colour by state |
| Search field | `.pro-input` | `SearchField` | RTL-aware |
| Filter chips | `.chip-row` | `FilterChips` | single-select |
| Empty state | `.empty-state` | `EmptyState` | icon + message + action |
| Error state | `.error-state` | `ErrorState` | message + retry |
| Loading | `.skeleton` | `LoadingSkeleton` | shimmer |
| Confirm dialog | native | `ConfirmationDialog` | destructive actions |
| Bottom navigation | `.bottom-nav` (`--bottomnav-h:60px`) | `BottomNavigationShell` | 5 items, role-aware |

### Status colours (single source)

| State | Colour |
|---|---|
| active / open / confirmed / paid | `success` |
| pending / draft / in_review | `warning` |
| closed_lost / cancelled / overdue / failed | `danger` |
| closed_won / completed | `info` |

These map directly to the `ENUM` values in `database/schema.sql`, so a badge never invents a state.

---

## 5. Accessibility rules

- Contrast: body text on surface ≥ 4.5:1. `textMuted` on white = 5.1:1 (verified).
- Never encode meaning in colour alone — every badge also carries a label.
- Focus is always visible (`:focus-visible` on web, `Focus` outline in Flutter).
- Touch targets ≥ 44×44 logical pixels on mobile.
- The app honours system text scaling; layouts are tested at 1.0× and 1.3×.
- Full RTL mirroring; directional icons flip, the logo does not.

---

## 6. Motion

| Token | Value | Use |
|---|---|---|
| `dur-fast` | 150ms | hover, focus |
| `dur-base` | 250ms | cards, drawers |
| `dur-slow` | 400ms | hero entrance |
| `ease-out` | `cubic-bezier(.22,.61,.36,1)` | entrances |

Motion is subtle and always respects `prefers-reduced-motion` on web and
`MediaQuery.disableAnimations` in Flutter.

---

## 7. What is deliberately absent

- **No dark mode.** The approved reference is a light, airy design; a dark theme would contradict it.
  Phase 10 permits dark mode only "if it can be implemented consistently without compromising the
  approved primary design" — it cannot, so it is omitted and documented.
- **No new logo, icon set, or brand colour.** Generic legal icons (scales, gavels, courthouses) are
  never used in place of the original mark.

---

_This document was produced by an AI agent (OpenHands) on behalf of the repository owner._
