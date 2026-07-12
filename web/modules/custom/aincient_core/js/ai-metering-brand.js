/**
 * @file
 * Re-themes the AI Metering Chart.js canvases to the Atelier palette.
 *
 * The dashboard charts are coloured by a hardcoded Bootstrap palette inside
 * contrib `js/ai-metering-dashboard.js` (#0d6efd blue, #198754 green, …) —
 * colours that live in JS, not CSS, so the ai-metering-brand.css re-skin
 * can't reach them. Rather than fork the contrib JS (which composer would
 * overwrite), we let it build the charts as-is, then remap each dataset's
 * colours onto the live `--ain-*` tokens: cinnabar for the primary series,
 * the muted earth set (verdigris / ochre / brick) for state — violet never
 * survives (the spectrum belongs to the mark, not chrome; docs/brand.md §10).
 *
 * Remapping the EXISTING colours (vs. re-deriving them) keeps the contrib
 * threshold logic intact: whatever it painted amber/red for the quota chart
 * simply becomes ochre/brick. Reading the tokens at attach time (not
 * hardcoding) means the charts follow the light/dark theme for free; the hex
 * fallbacks only fire if a token is missing.
 */

((Drupal, once) => {
  'use strict';

  /**
   * Resolves the contrib-hex → Atelier-token map from the live theme.
   * OKLCH strings are valid canvas colours in every Chart.js-capable engine.
   */
  function buildPalette() {
    const styles = getComputedStyle(document.documentElement);
    const token = (name, fallback) =>
      (styles.getPropertyValue(name) || '').trim() || fallback;
    return {
      map: {
        '#0d6efd': token('--ain-accent', '#c2452a'), // primary series → cinnabar
        '#198754': token('--ain-jade', '#3e7d63'), // positive → verdigris
        '#fd7e14': token('--ain-accent-2', '#a4763b'), // warning → ochre
        '#dc3545': token('--ain-danger', '#a43a2a'), // exceeded → brick
        '#6f42c1': token('--ain-text-dim', '#6b675f'), // secondary → dim ink
      },
      grid: token('--ain-border', '#e7e4dd'),
      ticks: token('--ain-text-dim', '#6b675f'),
      mono: token(
        '--ain-mono',
        'ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace',
      ),
    };
  }

  function brandChart(id, palette) {
    if (typeof Chart === 'undefined') {
      return;
    }
    const canvas = document.getElementById(id);
    const chart = canvas && Chart.getChart(canvas);
    if (!chart) {
      return;
    }
    const remap = (c) => palette.map[String(c).toLowerCase()] || c;
    chart.data.datasets.forEach((ds) => {
      ds.backgroundColor = Array.isArray(ds.backgroundColor)
        ? ds.backgroundColor.map(remap)
        : remap(ds.backgroundColor);
      ds.borderRadius = 4; // the chip radius — light falls, nothing stamps
    });
    // Hairline grids + dim mono ticks, so the canvases sit on the same paper
    // as the cards around them.
    Object.values(chart.options.scales || {}).forEach((scale) => {
      if (scale.grid) {
        scale.grid.color = palette.grid;
      }
      if (scale.ticks) {
        scale.ticks.color = palette.ticks;
        scale.ticks.font = { family: palette.mono, size: 10 };
      }
    });
    chart.update('none');
  }

  Drupal.behaviors.aincientMeteringBrand = {
    attach(context) {
      // The contrib behaviour builds the charts synchronously this tick; defer
      // to the next frame so the instances exist regardless of behaviour order.
      once('ain-metering-brand', '#ai-chart-tokens', context).forEach(() => {
        requestAnimationFrame(() => {
          const palette = buildPalette();
          ['ai-chart-tokens', 'ai-chart-quota', 'ai-chart-calls'].forEach(
            (id) => brandChart(id, palette),
          );
        });
      });
    },
  };
})(Drupal, once);
