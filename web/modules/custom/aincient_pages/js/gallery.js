/**
 * @file
 * Gallery island — progressive enhancement for the gallery component's
 * carousel variant and lightbox (DECISIONS 0523; plans/gallery-carousel.md).
 *
 * Without this script the carousel is a scroll-snap track that still swipes
 * and scrolls, and each lightbox image is a plain link to the full-size file.
 * This adds: prev/next buttons + a "3 / 12" counter on the carousel, and a
 * full-size viewer (native <dialog>) with arrow keys, Esc, and focus returned
 * to the image that opened it.
 *
 * Vanilla, no framework, no build step. Every class it relies on is already in
 * gallery.twig, so the compiled Tailwind needs nothing new.
 */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    }
    else {
      fn();
    }
  }

  /** The slide nearest the track's left edge. */
  function currentIndex(track, slides) {
    var left = track.scrollLeft;
    var best = 0;
    var bestDistance = Infinity;
    for (var i = 0; i < slides.length; i++) {
      var distance = Math.abs(slides[i].offsetLeft - track.offsetLeft - left);
      if (distance < bestDistance) {
        bestDistance = distance;
        best = i;
      }
    }
    return best;
  }

  function initCarousel(section) {
    var track = section.querySelector('[data-gallery-track]');
    var controls = section.querySelector('[data-gallery-controls]');
    if (!track || !controls) {
      return null;
    }
    var slides = track.querySelectorAll('[data-gallery-slide]');
    var prev = controls.querySelector('[data-gallery-prev]');
    var next = controls.querySelector('[data-gallery-next]');
    var counter = controls.querySelector('[data-gallery-counter]');

    function goTo(index) {
      var target = slides[Math.max(0, Math.min(index, slides.length - 1))];
      if (target) {
        track.scrollTo({left: target.offsetLeft - track.offsetLeft, behavior: reduceMotion ? 'auto' : 'smooth'});
      }
    }

    function update() {
      var index = currentIndex(track, slides);
      prev.disabled = index === 0;
      next.disabled = index === slides.length - 1;
      counter.textContent = (index + 1) + ' / ' + slides.length;
    }

    var pending = false;
    track.addEventListener('scroll', function () {
      if (!pending) {
        pending = true;
        window.requestAnimationFrame(function () {
          pending = false;
          update();
        });
      }
    }, {passive: true});
    prev.addEventListener('click', function () { goTo(currentIndex(track, slides) - 1); });
    next.addEventListener('click', function () { goTo(currentIndex(track, slides) + 1); });
    track.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        goTo(currentIndex(track, slides) + (event.key === 'ArrowRight' ? 1 : -1));
      }
    });

    controls.classList.remove('hidden');
    controls.classList.add('flex');
    // The buttons replace the scrollbar (it stays for the no-JS track).
    track.setAttribute('data-enhanced', '');
    update();
    return goTo;
  }

  function initLightbox(section, goToSlide) {
    var dialog = section.querySelector('[data-gallery-dialog]');
    var openers = section.querySelectorAll('[data-gallery-open]');
    if (!dialog || !openers.length || typeof dialog.showModal !== 'function') {
      return;
    }
    var full = dialog.querySelector('[data-gallery-full]');
    var caption = dialog.querySelector('[data-gallery-full-caption]');
    var counter = dialog.querySelector('[data-gallery-full-counter]');
    var prev = dialog.querySelector('[data-gallery-full-prev]');
    var next = dialog.querySelector('[data-gallery-full-next]');
    var close = dialog.querySelector('[data-gallery-close]');
    var index = 0;
    var opener = null;

    var items = Array.prototype.map.call(openers, function (link) {
      var img = link.querySelector('img');
      var holder = link.closest('[data-gallery-slide], figure');
      var text = holder ? holder.querySelector('[data-gallery-caption]') : null;
      return {
        src: link.getAttribute('href'),
        alt: img ? img.getAttribute('alt') || '' : link.getAttribute('aria-label') || '',
        caption: text ? text.textContent.trim() : '',
      };
    });

    function show(i) {
      index = Math.max(0, Math.min(i, items.length - 1));
      var item = items[index];
      full.src = item.src;
      full.alt = item.alt;
      caption.textContent = item.caption;
      caption.hidden = item.caption === '';
      if (counter) {
        counter.textContent = (index + 1) + ' / ' + items.length;
      }
      if (prev) {
        prev.disabled = index === 0;
      }
      if (next) {
        next.disabled = index === items.length - 1;
      }
    }

    Array.prototype.forEach.call(openers, function (link, i) {
      link.addEventListener('click', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) {
          return;
        }
        event.preventDefault();
        opener = link;
        show(i);
        document.documentElement.style.overflow = 'hidden';
        dialog.showModal();
        close.focus();
      });
    });

    if (prev) {
      prev.addEventListener('click', function () { show(index - 1); });
    }
    if (next) {
      next.addEventListener('click', function () { show(index + 1); });
    }
    close.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
      // A click on the dark backdrop (the dialog itself or its layout wrapper,
      // never the image or a button) closes the viewer.
      if (event.target === dialog || event.target === dialog.firstElementChild) {
        dialog.close();
      }
    });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        show(index + (event.key === 'ArrowRight' ? 1 : -1));
      }
    });
    dialog.addEventListener('close', function () {
      document.documentElement.style.overflow = '';
      full.removeAttribute('src');
      // Leave the carousel on the image the visitor ended on.
      if (goToSlide) {
        goToSlide(index);
      }
      var target = openers[index] || opener;
      if (target) {
        target.focus();
      }
    });
  }

  ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-gallery]'), function (section) {
      var goToSlide = section.getAttribute('data-gallery') === 'carousel' ? initCarousel(section) : null;
      initLightbox(section, goToSlide);
    });
  });
})();
