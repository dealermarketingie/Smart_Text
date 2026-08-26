/* ==========================================================================
   Mobile header menu.
   Below 860px the nav and the CTA collapse behind the hamburger (see
   styles.css); above it the toggle is hidden and none of this has any effect.

   app.js re-renders the header's contents on every state change, so nothing
   here may hold a reference to an element inside it — everything is delegated
   from the <header> itself, which persists. The open state lives as a class on
   that same element for the same reason.
   ========================================================================== */

(function () {
  var MOBILE_MAX = 860;

  var header = document.getElementById('site-header');
  if (!header) return;

  function isOpen() {
    return header.classList.contains('is-open');
  }

  function setOpen(open) {
    header.classList.toggle('is-open', open);
    var toggle = header.querySelector('.nav-toggle');
    if (toggle) toggle.setAttribute('aria-expanded', String(open));
  }

  header.addEventListener('click', function (e) {
    if (e.target.closest('.nav-toggle')) {
      setOpen(!isOpen());
      return;
    }

    if (!isOpen()) return;

    /* Following a link should leave the menu closed behind you. The Solutions
       trigger is the exception: it expands the industry list within the menu,
       so the menu has to stay open for the list to be usable. */
    if (e.target.closest('.nav-solutions-trigger')) return;
    if (e.target.closest('.nav-link, .nav-dropdown-item, .header-right .btn, .logo')) setOpen(false);
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
