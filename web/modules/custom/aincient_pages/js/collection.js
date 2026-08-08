/**
 * @file
 * Collection island — progressive enhancement over a correct, crawlable list
 * (DECISIONS 0329; plans/collection-listings.md).
 *
 * The server already rendered the first page of tiles and a real archive link,
 * so the list works with no JavaScript. This attaches ONLY to index-mode
 * collections and upgrades the archive link into "Load more": it fetches the
 * pre-resolved JSON index ONCE (the same file the page prefetched, so it comes
 * from the HTTP cache) and appends the remaining tiles in page-sized chunks.
 *
 * Vanilla, no framework, no build step. Tiles are built from the SAME markup +
 * classes as aincient_pages:article-teaser, so they inherit the compiled
 * Tailwind + brand tokens with no drift and nothing new for the CSS to compile.
 *
 * NB filter state (location.hash / popstate) is intentionally not wired yet:
 * there are no facets until taxonomy lands (the plan defers it), so there is
 * nothing to put in the hash. "Load more" is the whole enhancement for now.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(function () {
    var sections = document.querySelectorAll('[data-collection="index"]');
    Array.prototype.forEach.call(sections, init);
  });

  function init(section) {
    var src = section.getAttribute('data-collection-src');
    var perPage = parseInt(section.getAttribute('data-collection-per-page'), 10) || 12;
    var total = parseInt(section.getAttribute('data-collection-total'), 10) || 0;
    var list = section.querySelector('[data-collection-list]');
    var moreWrap = section.querySelector('[data-collection-more]');
    if (!src || !list || !moreWrap) {
      return;
    }
    // Tiles the server already rendered (the bounded first page).
    var rendered = list.children.length;
    // Everything already shown — leave the real archive link exactly as it is.
    if (rendered >= total) {
      return;
    }

    var archive = moreWrap.querySelector('a');

    // A polite live region so a screen reader hears the count change.
    var status = document.createElement('p');
    status.className = 'sr-only';
    status.setAttribute('aria-live', 'polite');
    announce(status, rendered, total);
    moreWrap.appendChild(status);

    // Upgrade the archive link to a Load-more button — same classes, so it
    // inherits the compiled brand styling. The <a> stays in the DOM (hidden)
    // as the no-JS fallback and is restored once the list is exhausted.
    var button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Load more';
    if (archive) {
      button.className = archive.className;
      archive.hidden = true;
    }
    moreWrap.insertBefore(button, status);

    var cache = null;
    button.addEventListener('click', function () {
      button.disabled = true;
      load(src, cache).then(function (items) {
        cache = items;
        var next = items.slice(rendered, rendered + perPage);
        for (var i = 0; i < next.length; i++) {
          list.appendChild(tile(next[i]));
        }
        // Move keyboard focus to the first newly-appended tile's heading link,
        // so a keyboard user is not dropped back at the top on each click.
        if (next.length) {
          var firstNew = list.children[rendered];
          var heading = firstNew && firstNew.querySelector('h2 a');
          if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus();
          }
        }
        rendered += next.length;
        announce(status, rendered, total);
        if (rendered >= total) {
          button.remove();
          if (archive) {
            archive.hidden = false;
          }
        } else {
          button.disabled = false;
        }
      }).catch(function () {
        // Fetch failed — restore the plain archive link, the resilient path.
        button.remove();
        if (archive) {
          archive.hidden = false;
        }
      });
    });
  }

  function load(src, cache) {
    if (cache) {
      return Promise.resolve(cache);
    }
    return fetch(src, { headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('bad status');
        }
        return response.json();
      })
      .then(function (data) {
        return (data && data.items) || [];
      });
  }

  function announce(el, shown, total) {
    el.textContent = 'Showing ' + shown + ' of ' + total + ' posts';
  }

  // Mirrors aincient_pages:article-teaser (its exact classes, so no CSS drift).
  function tile(record) {
    var article = document.createElement('article');
    article.className = 'group flex flex-col overflow-hidden border bg-card text-card-foreground transition rounded-[var(--card-radius)] border-[color:var(--card-border)] shadow-[var(--card-shadow)] hover:border-[color:var(--primary)] hover:shadow-[var(--shadow-md)]';

    var url = attr(record.url);
    var html = '';
    if (record.image) {
      html += '<a href="' + url + '" class="block aspect-[16/9] overflow-hidden bg-muted" tabindex="-1" aria-hidden="true">'
        + '<img src="' + attr(record.image) + '" alt="" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]"></a>';
    }
    html += '<div class="flex flex-col p-6 sm:p-7">';
    if (record.date) {
      html += '<div class="mb-3 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><time>' + text(record.date) + '</time></div>';
    }
    html += '<h2 class="font-display text-2xl [font-weight:var(--heading-weight)] leading-snug tracking-tight">'
      + '<a href="' + url + '" class="text-foreground no-underline transition group-hover:text-primary-on-surface">' + text(record.title) + '</a></h2>';
    if (record.teaser) {
      html += '<p class="mt-3 leading-relaxed opacity-70">' + text(record.teaser) + '</p>';
    }
    html += '<a href="' + url + '" class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-on-surface no-underline hover:underline">'
      + 'Read more<span aria-hidden="true">→</span></a>';
    html += '</div>';
    article.innerHTML = html;
    return article;
  }

  function text(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function attr(value) {
    return text(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
})();
