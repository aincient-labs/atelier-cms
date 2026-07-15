/**
 * @file
 * Make the FlowDrop editor / playground follow the console's light/dark choice.
 *
 * The React console remembers the operator's theme in `localStorage`
 * (`aincient-theme` = "light" | "dark") and applies it as `data-ain-theme` on
 * `#aincient-chat-root`. The FlowDrop fullscreen surfaces are a separate page
 * with their OWN theme system: FlowDrop keys design-tokens off `data-theme` on
 * `<html>` and, on mount, sets it from its configured preference
 * (`flowdrop_ui_components.settings`) — it does not read the console key. So
 * the editor could open dark while the console was set to light.
 *
 * This behaviour bridges the two: it forces `<html data-theme>` to match the
 * console's `aincient-theme`, so the branded FlowDrop skin
 * (aincient_core/css/flowdrop-brand.css) resolves to the same mode the operator
 * last chose in the console. The FlowDrop settings gear no longer exposes a
 * theme toggle (exposed_categories drops `theme`), so the console is the single
 * source of truth.
 *
 * Order-independent: FlowDrop applies its own `data-theme` twice — once in
 * initializeSettings() at attach and again asynchronously once the Svelte app
 * mounts. Rather than depend on our behaviour running after both writes, we
 * install a short-lived MutationObserver that re-asserts the console theme
 * whenever `data-theme` drifts, then disconnect it once the app has settled.
 * A permanent `storage` listener keeps the surface in step if the operator
 * flips the theme in a console tab while the editor is open.
 */

((Drupal, once) => {
  const CONSOLE_KEY = 'aincient-theme';

  /**
   * The console's current theme, normalised to FlowDrop's two modes.
   *
   * The console toggle only ever stores "light" or "dark" (its config default
   * is "light"); anything else falls back to light — FlowDrop's own default.
   *
   * @return {string} "light" or "dark".
   */
  function consoleTheme() {
    return localStorage.getItem(CONSOLE_KEY) === 'dark' ? 'dark' : 'light';
  }

  /**
   * Force <html data-theme> to the console theme, if it has drifted.
   *
   * Only writes when the value actually differs, so the MutationObserver that
   * calls this on `data-theme` changes settles in one bounce instead of looping.
   */
  function apply() {
    const want = consoleTheme();
    const root = document.documentElement;
    if (root.getAttribute('data-theme') !== want) {
      root.setAttribute('data-theme', want);
    }
  }

  Drupal.behaviors.aincientFlowdropThemeSync = {
    attach() {
      // Bind the page-level machinery exactly once.
      once('ain-fd-theme-sync', 'html', document).forEach(() => {
        apply();

        // Catch FlowDrop's async post-mount `data-theme` write (and any other
        // drift) during the mount window, then stop — the app has settled and
        // there is no in-editor theme control left to fight.
        const observer = new MutationObserver(apply);
        observer.observe(document.documentElement, {
          attributes: true,
          attributeFilter: ['data-theme'],
        });
        window.setTimeout(() => observer.disconnect(), 4000);

        // Cross-tab: reflect a console theme change made in another tab while
        // this editor stays open.
        window.addEventListener('storage', (event) => {
          if (event.key === CONSOLE_KEY) {
            apply();
          }
        });
      });
    },
  };
})(Drupal, once);
