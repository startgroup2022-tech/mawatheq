// تفاعلات بسيطة للموقع العام
document.addEventListener('DOMContentLoaded', () => {
  // نموذج المساعد الذكي التجريبي في الصفحة الرئيسية (Hero)
  const heroForm = document.getElementById('hero-ai-form');
  if (heroForm) {
    heroForm.addEventListener('submit', (e) => {
      e.preventDefault();
      window.location.href = '/register.php?next=ai-assistant';
    });
  }

  /* ---------------------------------------------------------- public nav ----
     The marketing header's mobile menu. This lives here, not only in
     enterprise.js, because the enhancement layer is not guaranteed to load
     (production once served it 403). Without this the menu button was inert and
     the navigation was unreachable on a phone. */
  const navBtn = document.querySelector('[data-nav-toggle]');
  const nav = document.querySelector('.main-nav');
  if (navBtn && nav && nav.dataset.navBound !== '1') {
    // Must match the @media (max-width: 1140px) block in style.css.
    const PANEL_BREAKPOINT = 1140;
    const scrim = document.querySelector('[data-nav-scrim]');
    const setOpen = (open) => {
      nav.classList.toggle('is-open', open);
      navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.classList.toggle('nav-open', open);
    };
    navBtn.addEventListener('click', () => setOpen(!nav.classList.contains('is-open')));
    // enterprise.js may have loaded first (it is a deferred script, so it runs
    // before DOMContentLoaded). Claim the toggle so only one script drives it —
    // two handlers would open and immediately close the panel.
    nav.dataset.navBound = '1';
    if (scrim) scrim.addEventListener('click', () => { setOpen(false); navBtn.focus(); });
    // Following a link closes the panel; the page navigates to the anchor.
    nav.addEventListener('click', (e) => { if (e.target.closest('a')) setOpen(false); });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) { setOpen(false); navBtn.focus(); }
    });
    // Crossing back to the desktop layout must not leave a stale open panel.
    window.addEventListener('resize', () => {
      if (window.innerWidth > PANEL_BREAKPOINT && nav.classList.contains('is-open')) setOpen(false);
    });
  }

  /* ------------------------------------------------------- sticky header ----
     Compress the bar once the page scrolls. Purely additive: without the class
     the header keeps its full height, which is also correct. */
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 12);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }
});
