/* ==========================================================================
   Mobile header menu.
   Shared by index.html and agents.html: both use the same .site-header
   markup, and neither page's own script owns the header. Below 860px the
   nav and the CTA collapse behind the hamburger (see styles.css); above it
   the toggle is hidden and none of this has any effect.
   ========================================================================== */

(function () {
  var MOBILE_MAX = 860;

  var header = document.querySelector('.site-header');
  if (!header) return;

  var toggle = header.querySelector('.nav-toggle');
  if (!toggle) return;

  function setOpen(open) {
    header.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
  }

  function isOpen() {
    return header.classList.contains('is-open');
  }

  toggle.addEventListener('click', function () {
    setOpen(!isOpen());
  });

  /* Following a link should leave the menu closed behind you. The Solutions
     trigger is the exception: it expands the industry list within the menu,
     so the menu has to stay open for the list to be usable. */
  header.addEventListener('click', function (e) {
    if (!isOpen()) return;
    if (e.target.closest('.nav-solutions-trigger')) return;
    if (e.target.closest('.nav-link, .nav-dropdown-item, .header-right .btn')) setOpen(false);
  });

  /* Tapping the page behind an open menu closes it, matching how the
     Solutions dropdown already behaves. */
  document.addEventListener('click', function (e) {
    if (isOpen() && !e.target.closest('.site-header')) setOpen(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) setOpen(false);
  });

  /* Widening to desktop hides the toggle, so without this the menu could be
     left open with no way to close it. */
  window.addEventListener('resize', function () {
    if (window.innerWidth > MOBILE_MAX && isOpen()) setOpen(false);
  });
})();
