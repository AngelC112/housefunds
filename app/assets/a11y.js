/* ============================================================
   Accessibility helpers shared by every page.

   Modals in this app are a container holding a ".modal-overlay"
   element (the dark backdrop) and a panel. Clicking the backdrop
   runs the modal's own close code. This script:
   - marks each modal container as a dialog for screen readers,
     named after the first heading inside it;
   - closes the topmost open modal on Escape by clicking its
     backdrop, so every modal keeps its own close logic. A modal
     whose backdrop does nothing (for example a choice the user
     must make) stays open.
   Modals created later by JavaScript are handled too.
   ============================================================ */
(function () {
  'use strict';

  let contatoreId = 0;

  function contenitoreModale(overlay) {
    return overlay.parentElement;
  }

  function marcaModale(contenitore) {
    if (!contenitore || contenitore.hasAttribute('role')) return;
    contenitore.setAttribute('role', 'dialog');
    contenitore.setAttribute('aria-modal', 'true');
    const titolo = contenitore.querySelector('h1, h2, h3');
    if (titolo) {
      if (!titolo.id) titolo.id = 'a11y-titolo-modale-' + (++contatoreId);
      contenitore.setAttribute('aria-labelledby', titolo.id);
    }
  }

  function marcaTutte(radice) {
    if (!radice || !radice.querySelectorAll) return;
    if (radice.classList && radice.classList.contains('modal-overlay')) {
      marcaModale(contenitoreModale(radice));
    }
    radice.querySelectorAll('.modal-overlay').forEach(o => marcaModale(contenitoreModale(o)));
  }

  function eVisibile(el) {
    return !el.classList.contains('hidden') && el.getClientRects().length > 0;
  }

  function modaleInCima() {
    let migliore = null;
    let zMigliore = -Infinity;
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
      const contenitore = contenitoreModale(overlay);
      if (!contenitore || !eVisibile(contenitore)) return;
      const z = parseInt(getComputedStyle(contenitore).zIndex, 10) || 0;
      // Same z-index: the later one in the page is drawn on top.
      if (z >= zMigliore) {
        zMigliore = z;
        migliore = overlay;
      }
    });
    return migliore;
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || e.defaultPrevented) return;
    const overlay = modaleInCima();
    if (!overlay) return;
    e.preventDefault();
    overlay.click();
  });

  function avvia() {
    marcaTutte(document.body);
    new MutationObserver(mutazioni => {
      mutazioni.forEach(m => m.addedNodes.forEach(n => {
        if (n.nodeType === 1) marcaTutte(n);
      }));
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', avvia);
  } else {
    avvia();
  }
})();
