'use strict';

/**
 * Story 4.2 — the Watchlist star's optimistic toggle. The one hand-written
 * JS file in the system (AD-12): no build step, no framework, plain
 * vanilla JS served as-is by .htaccess (it matches an existing file, so the
 * rewrite-to-index.php rule never touches it).
 *
 * Behavior:
 *  - Click flips the star immediately (optimistic UI, EXPERIENCE.md's
 *    Watchlist star pattern: no confirmation dialog).
 *  - 200 confirms/corrects the optimistic guess from the server's actual new
 *    state (in case of a race with another tab).
 *  - 401 (expired/invalid session — AuthController's session check, not the
 *    login page's HTML, per AD-12) triggers a full navigate to /login so the
 *    dead session is replaced with a real one.
 *  - Any other failure (network error, 404, 500) reverts the optimistic
 *    guess and shows a short message in the page's aria-live toast (design
 *    handbook §9 "Stjärnfel") instead of silently keeping a state the server
 *    never confirmed.
 *  - The button is disabled for the duration of its own in-flight request
 *    (re-enabled once fetch() settles, success or failure) so a rapid
 *    double-click/double-tap can't fire two concurrent toggles for the same
 *    isin — the practical trigger for a server-side toggle race.
 */
(function () {
  var TOAST_VISIBLE_MS = 4000;
  var toastTimer = null;

  function setStarState(button, starred) {
    button.setAttribute('aria-pressed', starred ? 'true' : 'false');
    button.textContent = starred ? '★' : '☆';
    button.classList.toggle('star--filled', starred);
    button.classList.toggle('star--empty', !starred);
  }

  function showToast(message) {
    var toast = document.getElementById('wl-toast');
    if (!toast) {
      return;
    }

    toast.textContent = message;
    toast.classList.add('is-visible');

    if (toastTimer) {
      clearTimeout(toastTimer);
    }
    toastTimer = setTimeout(function () {
      toast.classList.remove('is-visible');
    }, TOAST_VISIBLE_MS);
  }

  function handleClick(event) {
    var button = event.target.closest('.star');
    if (!button || button.disabled) {
      return;
    }

    var isin = button.getAttribute('data-isin');
    if (!isin) {
      return;
    }

    var wasStarred = button.classList.contains('star--filled');
    setStarState(button, !wasStarred);
    button.disabled = true;

    fetch('/watchlist/toggle', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ isin: isin }),
    })
      .then(function (response) {
        if (response.status === 401) {
          window.location.href = '/login';
          return null;
        }

        if (!response.ok) {
          setStarState(button, wasStarred);
          showToast('Kunde inte uppdatera bevakningen. Försök igen.');
          return null;
        }

        return response.json();
      })
      .then(function (data) {
        if (data && typeof data.starred === 'boolean') {
          setStarState(button, data.starred);
        }
      })
      .catch(function () {
        setStarState(button, wasStarred);
        showToast('Kunde inte uppdatera bevakningen. Kontrollera anslutningen.');
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  document.addEventListener('click', handleClick);
})();
