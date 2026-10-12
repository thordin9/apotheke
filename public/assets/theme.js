/* Apotheke – theme bootstrap. Loaded synchronously in <head> (tiny, CSP-safe, no inline script).
   The server already renders <html data-theme> from the "theme" cookie; this only restores a
   localStorage preference when the cookie is missing (e.g. cleared), before first paint. */
(function () {
  try {
    var d = document.documentElement, ls = localStorage.getItem('theme');
    if (/^(auto|light|dark)$/.test(ls || '') && !/(?:^|;\s*)theme=/.test(document.cookie)) {
      d.setAttribute('data-theme', ls);
      document.cookie = 'theme=' + ls + '; path=/; max-age=34560000; SameSite=Lax';
    }
  } catch (e) { /* storage blocked: cookie/server value stands */ }
})();
