/**
 * WP Analytics DataLayer — Front-end event tracking
 *
 * Handles client-side GA4 events:
 *   - select_item            (product click in loops)
 *   - add_shipping_info      (WooCommerce checkout — shipping method selected)
 *   - add_payment_info       (WooCommerce checkout — payment method selected)
 *   - view_promotion         (IntersectionObserver on [data-wadl-promo])
 *   - select_promotion       (click on [data-wadl-promo])
 *
 * Configuration is injected server-side via wp_localize_script as `wadlConfig`.
 * Product data is injected server-side as `wadlProducts` (id → item object map).
 */
(function () {
  'use strict';

  /** Push a single (non-ecommerce) event to the dataLayer. */
  function push(event) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(event);
  }

  /** Push a GA4 ecommerce event: clears previous ecommerce object first. */
  function pushEcommerce(eventName, ecommerceData) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ ecommerce: null });
    window.dataLayer.push({ event: eventName, ecommerce: ecommerceData });
  }

  /** Read a data attribute from the closest matching ancestor. */
  function closest(el, selector) {
    if (el.closest) return el.closest(selector);
    var node = el;
    while (node && node !== document) {
      if (node.matches && node.matches(selector)) return node;
      node = node.parentNode;
    }
    return null;
  }

  /** Extract product ID from a WooCommerce product element.
   *  Supports: post-{id} class, data-product_id attr, product_id in URL query. */
  function extractProductId(el) {
    // 1. data-product_id attribute (add-to-cart buttons)
    if (el.dataset && el.dataset.product_id) return el.dataset.product_id;

    // 2. Closest .product element with post-{id} class
    var productEl = closest(el, '.product, li[class*="product-"]');
    if (productEl) {
      var classes = productEl.className.split(' ');
      for (var i = 0; i < classes.length; i++) {
        var m = classes[i].match(/^post-(\d+)$/);
        if (m) return m[1];
      }
      if (productEl.dataset && productEl.dataset.product_id) return productEl.dataset.product_id;
    }

    // 3. href query: ?add-to-cart=123 or /product?p=123
    var href = el.href || '';
    var m2 = href.match(/add-to-cart=(\d+)/);
    if (m2) return m2[1];

    return null;
  }

  var cfg = window.wadlConfig || {};
  var events = cfg.events || {};

  // ------------------------------------------------------------------
  // add_to_cart (AJAX) — PHP static variable → fragments → JS push
  //
  // event_add_to_cart() (PHP) captures the product data during the AJAX request
  // and injects it into fragments.wadl_add_to_cart. No WC session — per-request
  // static variable only, so no cross-tab or background-refresh issues.
  //
  // remove_from_cart — PHP WC session → fragments → JS push
  // update_cart      — PHP cart payload → fragments → JS push
  // ------------------------------------------------------------------
  if ( ( events.add_to_cart || events.remove_from_cart || events.update_cart ) && typeof jQuery !== 'undefined' ) {

    jQuery( document.body ).on( 'added_to_cart', function ( e, fragments ) {
      // add_to_cart: ecommerce data prepared server-side for this AJAX request
      if ( events.add_to_cart && fragments && fragments.wadl_add_to_cart ) {
        pushEcommerce( 'add_to_cart', fragments.wadl_add_to_cart );
      }
      // update_cart: full cart state after the add
      if ( events.update_cart && fragments && fragments.wadl_update_cart ) {
        push( { event: 'update_cart', cart: fragments.wadl_update_cart } );
      }
    } );

    jQuery( document.body ).on( 'removed_from_cart', function ( e, fragments ) {
      // remove_from_cart: queued server-side, delivered via fragments
      if ( events.remove_from_cart && fragments && Array.isArray( fragments.wadl_events ) ) {
        fragments.wadl_events.forEach( function ( ev ) {
          if ( ! ev.event_name || ! ev.ecommerce ) return;
          var data = { event: ev.event_name, ecommerce: ev.ecommerce };
          var extra = ev.extra || {};
          Object.keys( extra ).forEach( function ( k ) { data[ k ] = extra[ k ]; } );
          window.dataLayer = window.dataLayer || [];
          window.dataLayer.push( { ecommerce: null } );
          window.dataLayer.push( data );
        } );
      }
      // update_cart: full cart state after the remove
      if ( events.update_cart && fragments && fragments.wadl_update_cart ) {
        push( { event: 'update_cart', cart: fragments.wadl_update_cart } );
      }
    } );
  }

  document.addEventListener('DOMContentLoaded', function () {

    // ------------------------------------------------------------------
    // add_to_cart — single product page form submit interceptor
    // Fires at click/submit time, before the WooCommerce form POST redirect.
    // update_cart is not fired here (form POST flow — no AJAX fragments).
    // ------------------------------------------------------------------
    if (events.add_to_cart && window.wadlProducts) {
      var cartForm = document.querySelector('form.cart');
      if (cartForm) {
        cartForm.addEventListener('submit', function () {
          var input = cartForm.querySelector('[name="add-to-cart"]');
          var productId = input ? String(input.value) : null;
          if (!productId) return;
          var item = window.wadlProducts[productId];
          if (!item) return;
          var qtyInput = cartForm.querySelector('[name="quantity"]');
          var qty = parseInt(qtyInput ? qtyInput.value : '1', 10) || 1;

          pushEcommerce('add_to_cart', {
            currency: cfg.currency || '',
            value: parseFloat(item.price || 0) * qty,
            items: [ Object.assign({}, item, { quantity: qty }) ],
          });
        });
      }
    }

    // ------------------------------------------------------------------
    // select_item
    // ------------------------------------------------------------------
    if (events.select_item && window.wadlProducts) {
      document.addEventListener('click', function (e) {
        var link = closest(e.target, 'a');
        if (!link) return;

        // Only trigger inside a WooCommerce product loop
        if (!closest(link, '.products, .woocommerce-loop-product__title, ul.products')) return;

        var id = extractProductId(link);
        if (!id) return;

        var item = window.wadlProducts[String(id)];
        if (!item) return;

        pushEcommerce('select_item', { items: [item] });
      });
    }

    // ------------------------------------------------------------------
    // add_shipping_info — fires once when a shipping method is chosen
    // ------------------------------------------------------------------
    if (events.add_shipping_info) {
      var shippingPushed = false;

      function onShippingChange() {
        if (shippingPushed) return;
        var selected = document.querySelector('input[name="shipping_method[0]"]:checked')
          || document.querySelector('.wc-block-components-shipping-rates-control input:checked');
        if (!selected) return;

        shippingPushed = true;
        pushEcommerce('add_shipping_info', {
          currency: cfg.currency || '',
          shipping_tier: selected.value || '',
        });
      }

      // WooCommerce classic checkout
      document.addEventListener('change', function (e) {
        if (
          e.target.name === 'shipping_method[0]' ||
          (e.target.closest && e.target.closest('.wc-block-components-shipping-rates-control'))
        ) {
          onShippingChange();
        }
      });

      // Fire on load if a method is already pre-selected
      window.addEventListener('load', function () {
        if (
          document.querySelector('form.woocommerce-checkout') ||
          document.querySelector('[data-block-name="woocommerce/checkout"]')
        ) {
          // Small delay to let WooCommerce initialize shipping rates
          setTimeout(onShippingChange, 800);
        }
      });
    }

    // ------------------------------------------------------------------
    // add_payment_info — fires once when a payment method is selected
    // ------------------------------------------------------------------
    if (events.add_payment_info) {
      var paymentPushed = false;

      document.addEventListener('change', function (e) {
        if (paymentPushed) return;

        var isPaymentInput =
          e.target.name === 'payment_method' ||
          (e.target.closest && e.target.closest('.wc-block-components-radio-control--payment-method'));

        if (!isPaymentInput) return;

        paymentPushed = true;
        pushEcommerce('add_payment_info', {
          currency: cfg.currency || '',
          payment_type: e.target.value || '',
        });
      });
    }

    // ------------------------------------------------------------------
    // view_promotion — IntersectionObserver on [data-wadl-promo]
    // ------------------------------------------------------------------
    if (events.view_promotion && 'IntersectionObserver' in window) {
      var viewedPromos = {};

      var promoObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          var el = entry.target;
          var promoId = el.dataset.wadlPromoId || '';
          if (viewedPromos[promoId]) return;
          viewedPromos[promoId] = true;

          pushEcommerce('view_promotion', {
            promotion_id:   el.dataset.wadlPromoId   || '',
            promotion_name: el.dataset.wadlPromoName || '',
            creative_name:  el.dataset.wadlCreative  || '',
            creative_slot:  el.dataset.wadlSlot      || '',
          });
        });
      }, { threshold: 0.5 });

      document.querySelectorAll('[data-wadl-promo-id]').forEach(function (el) {
        promoObserver.observe(el);
      });
    }

    // ------------------------------------------------------------------
    // select_promotion — click on [data-wadl-promo-id]
    // ------------------------------------------------------------------
    if (events.select_promotion) {
      document.addEventListener('click', function (e) {
        var promo = closest(e.target, '[data-wadl-promo-id]');
        if (!promo) return;

        pushEcommerce('select_promotion', {
          promotion_id:   promo.dataset.wadlPromoId   || '',
          promotion_name: promo.dataset.wadlPromoName || '',
          creative_name:  promo.dataset.wadlCreative  || '',
          creative_slot:  promo.dataset.wadlSlot      || '',
        });
      });
    }

  }); // end DOMContentLoaded

})();
