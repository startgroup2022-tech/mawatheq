# Execution 68 — legal library real-content screenshots

Captured from the **preview** database (`mawatheq_test`) after a real MOJ import
(`php cron/legal-library-sync.php --source=moj_decisions` with `MAWATHEQ_LL_CAPTURE_BODY=1`).
No demo/placeholder rows are present — all 188 items are live Ministry of Justice
judgements, 130+ of them carrying the full ruling text.

Regenerate with:

```bash
node tools/exec68_library_shots.js /tmp/exec68_shots
```

| File | Surface | Viewport |
| --- | --- | --- |
| `library-hub-desktop.png` | library hub | 1440×900 |
| `library-hub-mobile.png` | library hub | 390×844 @2x |
| `library-search-desktop.png` | search results for «المحكمة» | 1440×900 |
| `library-search-mobile.png` | search results | 390×844 @2x |
| `library-detail-desktop.png` | judgement detail (item 56, real ruling) | 1440×900 |
| `library-detail-mobile.png` | judgement detail | 390×844 @2x |

Typeface: the Arabic UI uses **Cairo** (headings) and **IBM Plex Sans Arabic**
(body), loaded from Google Fonts in `includes/header.php`. There is no Tajawal
family anywhere in the project.
