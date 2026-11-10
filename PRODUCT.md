# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Alumni, teachers, current students, and faculty of the French bilingual program ("Lớp Pháp") at Ly Tu Trong High School for the Gifted (THPT Chuyên Lý Tự Trọng, Cần Thơ, Vietnam). They visit to reconnect, find peers across promotion years, explore study abroad and career trajectories (especially in France, Francophone nations, and Vietnam), and update their own profiles.

## Product Purpose

A digital yearbook and alumni directory ("Kỷ yếu cựu học sinh Lớp Pháp - Chuyên Lý Tự Trọng") that celebrates the community's shared heritage, preserves memories, showcases educational and professional journeys, and facilitates networking across generations of graduates.

## Positioning

Unlike generic LinkedIn networks or private spreadsheets, this is an intimate, bilingual (Vietnamese–French) collective archive capturing the specific cultural and academic identity of the French bilingual classes of Chuyên Lý Tự Trọng Cần Thơ.

## Operating Context

- Browsed on desktop and mobile by alumni worldwide (Vietnam, France, Canada, Europe, etc.).
- PHP page (`index.php`, hosted at https://12p-ltt.page.gd) embedding records from MySQL via a server-side file cache; filtering and search stay client-side.
- Contributions and updates submitted on the page itself through an email-OTP modal; the latest submission per email is displayed, history is kept.

## Capabilities and Constraints

- **Preserve 100% frontend logic**: Dynamic filtering by graduation year ("Năm tốt nghiệp"), quick roster index navigation with anchor links, alphabetical and chronological sorting, education/work timeline rendering with year-range detection, email visibility toggle, fallback photo loading (local -> Drive thumbnail -> default portrait).
- **Zero build requirement**: Pure modern vanilla HTML5, CSS3, and ES6 JavaScript. No framework lock-in or heavy runtime dependencies.
- **Bilingual nuance**: Seamlessly honors the dual Vietnamese & French linguistic heritage (e.g., "Promotion", "Formation", "Profession", literary epigraph).

## Evidence on Hand

- Production alumni dataset (84 profiles, seeded from `data/alumni.json` into MySQL) spanning promotion years from 2006 through 2023.
- Local avatar assets in `static/img/`.
- Working text utilities in `assets/js/text.js` and renderer in `assets/js/app.js`.

## Product Principles

1. **Warmth and Dignity**: A yearbook is a vessel of cherished youth and lifelong bonds; it should feel distinguished, nostalgic, and scholarly rather than corporate or clinical.
2. **Effortless Discovery**: Finding a classmate or browsing a cohort should be immediate, with fluid filtering, search/roster wayfinding, and clear timeline typography.
3. **Typography-First Craft**: Honor the Franco-Vietnamese bilingual editorial tradition with impeccable typographic hierarchy, diacritic support, and balanced proportions.
