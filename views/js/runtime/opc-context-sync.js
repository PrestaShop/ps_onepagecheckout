/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */

/**
 * Keeps the front "context" snapshot in sync after an OPC AJAX update.
 *
 * Core emits window.prestashop and the <body> classes (country-/currency-) only on a full
 * page render. OPC never reloads, so after an address/carrier/cart change these values would
 * stay frozen at their page-load value. This helper patches them client-side from the
 * `context_refresh` block that the OPC controllers now attach to every JSON response.
 *
 * IMPORTANT — ordering guarantee: synchronisation happens SYNCHRONOUSLY *before* the OPC
 * event is emitted (see emitWithContext). This ensures that any third-party module listening
 * to opcPaymentMethodsRefreshed / opcPaymentMethodsUpdated / opcCarriersUpdated /
 * opcAddressPersisted reads up-to-date window.prestashop.* and body classes while handling the
 * event — regardless of listener registration order (no race with our own subscription).
 */

function swapBodyClass(prefix, isoCode) {
  if (!isoCode || typeof document === 'undefined' || !document.body) {
    return;
  }

  const body = document.body;
  [...body.classList]
    .filter((className) => className.startsWith(prefix))
    .forEach((className) => body.classList.remove(className));

  // iso_code keeps the server casing: country-XX / currency-XX are UPPERCASE, lang-xx lowercase.
  body.classList.add(prefix + isoCode);
}

export function extractContextRefresh(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  if (payload.context_refresh && typeof payload.context_refresh === 'object') {
    return payload.context_refresh;
  }

  if (payload.resp && typeof payload.resp === 'object' && payload.resp.context_refresh) {
    return payload.resp.context_refresh;
  }

  return null;
}

/**
 * Synchronously refresh window.prestashop.* globals and the matching <body> classes.
 * Unchanged values are a no-op (re-adding the same class / merging identical fields).
 *
 * @param {object|null} context
 */
export function syncPrestashopContext(context) {
  const prestashop = typeof window !== 'undefined' ? window.prestashop : null;

  if (!prestashop || !context || typeof context !== 'object') {
    return;
  }

  if (context.country && context.country.iso_code) {
    prestashop.country = {...(prestashop.country || {}), ...context.country};
    swapBodyClass('country-', context.country.iso_code);
  }

  if (context.currency && context.currency.iso_code) {
    prestashop.currency = {...(prestashop.currency || {}), ...context.currency};
    swapBodyClass('currency-', context.currency.iso_code);
  }

  if (context.cart && typeof context.cart === 'object') {
    // Deep-merge `totals` so refreshing total_including_tax does not drop sibling fields
    // (total, total_excluding_tax, total_shipping...) that a window.prestashop.cart.totals reader expects.
    const previousCart = prestashop.cart || {};
    const mergedTotals = context.cart.totals && typeof context.cart.totals === 'object'
      ? {...(previousCart.totals || {}), ...context.cart.totals}
      : previousCart.totals;

    prestashop.cart = {
      ...previousCart,
      ...context.cart,
      ...(mergedTotals ? {totals: mergedTotals} : {}),
    };
  }
}

/**
 * Sync the context FIRST, then emit — so listeners see fresh values while handling the event.
 *
 * @param {string} eventName
 * @param {object} response  The raw AJAX response (carries `context_refresh`).
 */
export function emitWithContext(eventName, response) {
  syncPrestashopContext(extractContextRefresh(response));

  const prestashop = typeof window !== 'undefined' ? window.prestashop : null;
  if (prestashop && typeof prestashop.emit === 'function') {
    prestashop.emit(eventName, response);
  }
}
