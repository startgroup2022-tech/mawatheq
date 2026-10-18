# Execution 32 visual audit artifacts

Before/after captures at 1440, 1280, 768, 390 and 360 px, Arabic (RTL) and English (LTR).

- `header-{before,after}-{ar,en}-{width}.png` — the global header on the home page
- `landing-hero-*` / `landing-apply-*` — the affiliate landing page hero and application form
- `audit-results.json` — raw geometry (nav rows, header height, link heights, offsets, overflow)
- `visual-defects.json` — sibling-overlap and text-clipping measurements
- `comparison.html` — side-by-side contact sheet

"before" is a git worktree of `1c750e1` (the pre-fix tree) served on a separate port against
the same database and configuration, not a mock-up. Regenerate with:

    git worktree add /tmp/mawatheq-before 1c750e1
    php -S 127.0.0.1:12002 -t /tmp/mawatheq-before &
    node tools/visual_audit_exec32.js && node tools/visual_defects_exec32.js && node tools/visual_sheet_exec32.js
