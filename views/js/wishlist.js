/**
 * Progressive enhancement for Summit Wishlist. Without JavaScript every control is a
 * plain form POST with server notifications. With it:
 * - [data-summitwishlist-action] (hearts, PDP button) posts in the background (one delegated
 *   listener); "toggle" saves, or removes when the variant is already saved,
 * - product-card hearts move into the card image area (idempotent, survives list refreshes),
 * - the wishlist page gets the "select available items" toolbar and a live bulk label.
 * SummitWishlist.add() is the contract a future list picker calls with an explicit wishlistId.
 */
(function () {
  'use strict';

  var HEART_OUTLINE = '';
  var HEART_FILLED = '';

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /** Theme toast when available (it inserts HTML, so text is escaped first), else a polite live region. */
  function notify(messages, success) {
    var text = messages.join(' ');
    var theme = window.Theme;
    if (theme && theme.components && typeof theme.components.useToast === 'function') {
      theme.components.useToast(escapeHtml(text), { type: success ? 'success' : 'danger', autohide: true, delay: 3000 }).show();
      return;
    }
    var region = document.querySelector('.sw-live');
    if (!region) {
      region = document.createElement('div');
      region.className = 'sw-live';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      document.body.appendChild(region);
    }
    var node = document.createElement('div');
    node.className = 'sw-live__message ' + (success ? 'is-success' : 'is-error');
    node.textContent = text;
    region.appendChild(node);
    setTimeout(function () { node.remove(); }, 4000);
  }

  function setCount(count) {
    if (typeof count !== 'number') {
      return;
    }
    document.querySelectorAll('.js-sw-count').forEach(function (el) { el.textContent = String(count); });
  }

  var TRIGGER = '[data-summitwishlist-action]';

  /** Sets saved/unsaved on every trigger for this exact variant, using the server-resolved IDs. */
  function setSaved(productId, attributeId, saved) {
    document.querySelectorAll(TRIGGER + '[data-sw-product="' + productId + '"]').forEach(function (trigger) {
      // PDP triggers carry the rendered combination; a refreshed PDP re-renders with server state anyway.
      if (Number(trigger.getAttribute('data-sw-attribute')) !== Number(attributeId)) {
        return;
      }
      trigger.classList.toggle('is-saved', saved);
      trigger.setAttribute('aria-pressed', saved ? 'true' : 'false');
      var icon = trigger.querySelector('.sw-heart-icon');
      if (icon) {
        icon.textContent = saved ? HEART_FILLED : HEART_OUTLINE;
      }
      var title = trigger.getAttribute(saved ? 'data-sw-title-remove' : 'data-sw-title-add');
      if (title) {
        trigger.setAttribute('title', title);
      }
      var label = trigger.querySelector('.sw-pdp__label');
      var text = trigger.getAttribute(saved ? 'data-sw-text-saved' : 'data-sw-text-add');
      if (label && text) {
        label.textContent = text;
      }
    });
  }

  /**
   * @param {string} url  module item endpoint (taken from the rendered markup, never hardcoded)
   * @param {FormData} data  swl_token, id_product and either group[] or swl_id_product_attribute
   * @param {{wishlistId?: number, op?: 'add'|'toggle'}} [options]
   *        op defaults to "add" (idempotent); triggers use "toggle". wishlistId is for the future list picker.
   */
  function add(url, data, options) {
    data.set('op', (options && options.op) || 'add');
    data.set('ajax', '1');
    if (options && options.wishlistId) {
      data.set('id_wishlist', String(options.wishlistId));
    }

    return fetch(url, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        // Trust the application result, not the HTTP status.
        notify(json.messages || [], json.success === true);
        if (json.success === true) {
          setCount(json.count);
          if (json.id_product) {
            setSaved(json.id_product, json.id_product_attribute, json.saved === true);
          }
        }
        return json;
      });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest(TRIGGER);
    if (!trigger) {
      return;
    }
    var form = trigger.form || trigger.closest('form');
    if (!form) {
      return;
    }
    event.preventDefault();

    var url = trigger.getAttribute('formaction') || form.getAttribute('action');
    var data = new FormData(form);
    // On the PDP the trigger lives inside the core cart form; drop the core-only fields.
    data.delete('token');
    data.delete('qty');

    trigger.disabled = true;
    add(url, data, { op: trigger.getAttribute('data-summitwishlist-action') })
      .catch(function () { notify([(window.summitWishlistI18n || {}).error || 'Error'], false); })
      .finally(function () { trigger.disabled = false; });
  });

  // Product cards: move the heart into the image area. Idempotent and scoped to module markup.
  function placeHearts(root) {
    (root || document).querySelectorAll('.product-miniature .sw-card-heart:not([data-sw-placed])').forEach(function (heart) {
      var card = heart.closest('.product-miniature');
      var top = card && card.querySelector('.product-miniature__top');
      heart.setAttribute('data-sw-placed', '1');
      if (!top) {
        return;
      }
      top.classList.add('sw-card-heart-host');
      top.appendChild(heart);
    });
  }

  function observeListings() {
    if (!('MutationObserver' in window)) {
      return;
    }
    var pending = false;
    new MutationObserver(function (mutations) {
      // Only react to inserted nodes that contain unplaced hearts; moving a heart doesn't re-trigger work.
      var relevant = mutations.some(function (m) {
        return Array.prototype.some.call(m.addedNodes, function (n) {
          return n.nodeType === 1 && (n.matches('.sw-card-heart:not([data-sw-placed])') || n.querySelector('.sw-card-heart:not([data-sw-placed])'));
        });
      });
      if (relevant && !pending) {
        pending = true;
        requestAnimationFrame(function () { pending = false; placeHearts(); });
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  // Wishlist page: selection toolbar and bulk label.
  function initSelection(form) {
    var selectAll = form.querySelector('[data-sw-select-all]');
    var bulk = form.querySelector('[data-sw-bulk]');
    var counter = form.querySelector('[data-sw-selected-count]');
    var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('[data-sw-select]:not(:disabled)')); };

    function sync() {
      var all = boxes();
      var selected = all.filter(function (b) { return b.checked; }).length;
      if (selectAll) {
        selectAll.checked = all.length > 0 && selected === all.length;
        selectAll.indeterminate = selected > 0 && selected < all.length;
      }
      if (bulk) {
        bulk.disabled = selected === 0;
        bulk.textContent = selected === 0
          ? bulk.getAttribute('data-sw-label-none')
          : bulk.getAttribute('data-sw-label-some').replace('%count%', String(selected));
      }
      if (counter) {
        counter.textContent = selected ? counter.getAttribute('data-sw-label').replace('%count%', String(selected)) : '';
      }
    }

    form.addEventListener('change', function (event) {
      if (event.target === selectAll) {
        boxes().forEach(function (b) { b.checked = selectAll.checked; });
      }
      sync();
    });

    // Block double submits; the submitter's name/value is captured before disabling.
    form.addEventListener('submit', function () {
      setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });

    sync();
  }

  // Native <dialog>: showModal() traps focus and handles Escape; the opener gets focus back.
  // Without dialog support (or JS) the confirm buttons simply submit, and the post-cart
  // prompt stays as the inline panel it is rendered as.
  var supportsDialog = typeof HTMLDialogElement === 'function' && 'showModal' in HTMLDialogElement.prototype;

  function openModal(dialog, opener) {
    if (dialog.open) {
      // Server-rendered open (no-JS panel). Drop the attribute rather than close(): close()
      // queues a "close" event that would fire the listener below before the user acts.
      dialog.removeAttribute('open');
    }
    dialog.showModal();
    dialog.addEventListener('close', function onClose() {
      dialog.removeEventListener('close', onClose);
      if (opener && typeof opener.focus === 'function') {
        opener.focus();
      }
      if (dialog.id === 'sw-post-cart') {
        forgetJustAdded();
      }
    });
  }

  /** Drops swl_added from the URL so a reload or "back" doesn't ask again. */
  function forgetJustAdded() {
    if (!window.history || !window.history.replaceState) {
      return;
    }
    var url = new URL(window.location.href);
    if (url.searchParams.has('swl_added')) {
      url.searchParams.delete('swl_added');
      window.history.replaceState(null, '', url.toString());
    }
  }

  function initDialogs() {
    if (!supportsDialog) {
      return;
    }
    document.addEventListener('click', function (event) {
      var opener = event.target.closest('[data-sw-confirm]');
      if (opener) {
        var dialog = document.getElementById(opener.getAttribute('data-sw-confirm'));
        if (dialog) {
          event.preventDefault();
          openModal(dialog, opener);
        }
        return;
      }
      var closer = event.target.closest('[data-sw-dialog-close]');
      if (closer) {
        var owner = closer.closest('dialog');
        if (owner) {
          event.preventDefault(); // "Keep in wishlist" is a link for the no-JS case
          owner.close();
        }
      }
    });

    document.querySelectorAll('dialog[data-sw-autoshow]').forEach(function (dialog) {
      dialog.classList.remove('sw-dialog--inline');
      openModal(dialog, null);
      var first = dialog.querySelector('.btn');
      if (first) {
        first.focus();
      }
    });
  }

  function init() {
    placeHearts();
    observeListings();
    initDialogs();
    document.querySelectorAll('[data-sw-wishlist-form]').forEach(initSelection);
    if (window.prestashop && typeof window.prestashop.on === 'function') {
      window.prestashop.on('updateProductList', function () { placeHearts(); });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.SummitWishlist = { add: add };
})();
