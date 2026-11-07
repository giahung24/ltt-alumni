# Design System — Kỷ yếu lớp Pháp (L'Édition Classique)

## Aesthetic Direction
A quiet, scholarly Francophone editorial yearbook: crisp cool-alabaster paper ground (`#f6f7fa`), pure white portrait cards (`#ffffff`), deep slate-ink typography (`#141c28`), and Bordeaux wine (`#80182a`) paired with warm antique bronze (`#824f22`) to distinguish academic formation from career milestones.

## Typography
Strict two-voice hierarchy with zero mid-line mixing:
- **Display & Literary Serif (`--font-serif`: `'Source Serif 4'`)** — reserved exclusively for page titles, the French literary epigraph, and each alumnus's full name (`h2.fullname`), with native Vietnamese diacritic support.
- **UI & Reading Sans (`--font-sans`: `'Be Vietnam Pro'`)** — used uniformly across all controls, cohort lines (`Promo YYYY – YYYY`), bilingual section labels (`FORMATION · HỌC VẤN`, `PROFESSION · CÔNG VIỆC`), timeline years, body copy, and tabular dates (`font-variant-numeric: tabular-nums`).

## Color Tokens
| Token | Value | Role |
| :--- | :--- | :--- |
| `--color-bg` | `#f6f7fa` | Page canvas |
| `--color-surface` | `#ffffff` | Card, header, and drawer surface |
| `--color-text` | `#141c28` | Primary reading ink (16.8:1) |
| `--color-text-muted` | `#414f63` | Secondary copy & controls (>= 7.7:1) |
| `--color-text-subtle` | `#556377` | Placeholders, timestamps, Vietnamese sub-labels (>= 5.4:1) |
| `--color-primary` | `#80182a` | Bordeaux accent (Promo, Formation, links, primary CTA) |
| `--color-accent` | `#824f22` | Antique bronze accent (Profession milestones) |
| `--color-border` | `#d8dfe9` | Hairline structural dividers |

## Component & Layout Rules
- **Portrait Header (`.entry-head`)**: `140px` (`4:5` aspect ratio, `112px` on compact mobile) portrait frame paired with a vertically centered identity block (`h2.fullname` -> `.promo` -> optional `.email`). Real portraits crop at `object-position: 50% 22%`; missing portraits fall back to the `4:5` *Gravure Beaux-Arts* illustration (`static/img/default.jpg`, centered via `.photo.is-default`).
- **Adaptive Timeline (`.tl-item`)**: Single-milestone entries render cleanly without decorative rails; multi-milestone entries (`2+`) render 7px ring nodes connected strictly between consecutive nodes.
- **Responsive Sticky Bar**: Single-row toolbar on desktop; compact two-row grid on mobile (`<= 820px`) keeping search full-width and cohort filter + actions aligned on one row.
