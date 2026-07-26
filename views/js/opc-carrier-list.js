import {CORE_EVENTS, OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import OPC_OPTION_LIST_STATE from './runtime/opc-option-list-state';
import {emitWithContext} from './runtime/opc-context-sync';
import {
  AJAX_READY_STATE_DONE,
  AJAX_STATUS_ABORT,
  getAjaxErrorResponse,
  getConfiguredOpcMessage,
  getConfiguredOpcUrl,
  normalizeErrorResponse,
  updateCartSummary,
} from './runtime/opc-runtime';
import {
  collectVisibleAddressContext,
  getPersistedInlineAddressId,
  getSelectedAddressId,
  getUseSameAddressValue,
  hasAddressPersistFailed,
  hasPendingInlineDraft,
  INVOICE_ADDRESS_CONTEXT_FIELDS,
  isBuyerIdentified,
  isCarrierSectionReady,
  isInlineAutosaveActive,
} from './runtime/address/opc-address-context';
import {createSectionReadiness, renderAwaitingAddress} from './runtime/address/opc-section-readiness';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcCarrierListRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const CONTAINER_SELECTOR = OPC_SELECTORS.opc.deliveryMethods;
const URL_KEY = 'carriers';
const CHECKOUT_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const FAILED_EVENT_NAME = OPC_EVENTS.opcCarriersFailed;
let selectedDeliveryAddressId = null;
let fetchCarriersGeneration = 0;
let activeFetchCarriersRequest = null;
// A trigger fired while a carriers request was already in flight. Aborting-and-refiring would NOT
// stop the server (an aborted opcCarriers call still executes — and WRITES cart pointers and temp
// addresses), so concurrent triggers used to race concurrent writes on the same cart. Instead the
// fetch is single-flight: triggers during flight coalesce into ONE trailing refetch that rebuilds
// its URL from the freshest state once the in-flight request completes.
let pendingCarriersRefetch = false;
// Inline delivery COUNTRY change: hold the carrier list on a loader until the new country is persisted
// onto the cart, then fetch against the persisted cart (so third-party carrier/payment modules read the
// fresh delivery country server-side). Driven by opcDeliveryCountryChanging -> opcAddressPersisted.
let pendingCountryChangeRefresh = false;
let pendingCountryChangeTimer = null;
// Final safety net: a deferred loader must never outlive its persist confirmation. Normal autosave
// persist completes in ~1s, so 5s is a wide margin while keeping a worst-case race from ever looking
// like an "infinite" loader. Each new country change re-arms it (see beginPendingCountryChangeRefresh).
const PENDING_COUNTRY_CHANGE_BACKSTOP_MS = 5000;
// The single-flight guard WAITS for the in-flight request instead of aborting it (an aborted
// opcCarriers call still executes — and writes — server-side). A request that never completes
// (black-holed connection) would therefore freeze BOTH option sections with no recovery path:
// cap it. On timeout the normal .fail path renders the error + retry (jQuery reports 'timeout',
// which the abort guard lets through) and the trailing coalesced refetch still runs. Nominal
// rounds complete in ~1-2s; this only ever fires on a dead socket, not a slow-but-alive response.
const CARRIERS_REQUEST_TIMEOUT_MS = 20000;

// The order-options block (delivery comment / recyclable packaging / gift wrapping) belongs to the
// delivery section and must stay hidden until a valid delivery address reveals the carriers, 
// it is shown only once carriers are available.
// NOTE: third-party hook content (displayPaymentTop / displayBeforeCarrier ...) is
// intentionally not gated here — that logic is owned by the payment/carrier modules, not the OPC.
function syncOrderOptionsVisibility(state) {
  const orderOptions = document.querySelector(OPC_SELECTORS.opc.orderOptions);

  if (orderOptions instanceof HTMLElement) {
    orderOptions.classList.toggle('d-none', state !== OPC_OPTION_LIST_STATE.AVAILABLE);
  }
}

// Cross-section contract: the payment section reads this state straight from the DOM (its round
// guard, isCarriersRoundInFlight in opc-payment-list.js) instead of shadowing it from events —
// every state write MUST therefore happen BEFORE the event announcing that transition is emitted.
function setCarrierOptionsState(state) {
  const container = document.querySelector(CONTAINER_SELECTOR);

  if (container instanceof HTMLElement) {
    container.dataset.opcCarriersState = state;
  }

  syncOrderOptionsVisibility(state);
}

function refreshCarrierOptionsState() {
  const container = document.querySelector(CONTAINER_SELECTOR);

  if (!(container instanceof HTMLElement)) {
    return;
  }

  setCarrierOptionsState(container.querySelector(OPC_SELECTORS.inputs.deliveryOption)
    ? OPC_OPTION_LIST_STATE.AVAILABLE
    : OPC_OPTION_LIST_STATE.EMPTY);
}

function getTemplateHtml(templateId) {
  const template = document.querySelector(`#${templateId}`);

  return template ? template.innerHTML : '';
}

function getCheckoutForm() {
  return document.querySelector(CHECKOUT_FORM_SELECTOR);
}

function getSelectedSavedDeliveryAddressId() {
  const selectedRadio = document.querySelector(
    `${OPC_SELECTORS.opc.deliveryList} ${OPC_SELECTORS.opc.addressRadio}[name="id_address_delivery"]:checked`
  );
  const selectedAddressId = selectedRadio ? String(selectedRadio.getAttribute('value') || '') : '';

  if (!selectedAddressId || selectedAddressId === 'new_address') {
    return '';
  }

  return selectedAddressId;
}

function getFormValue(form, name) {
  const direct = form.querySelector(`[name="${name}"]`);
  const prefixed = form.querySelector(`[name="delivery_${name}"]`);

  return ((direct && direct.value) || (prefixed && prefixed.value) || '').trim();
}

function buildCarriersUrl(baseUrl) {
  const form = getCheckoutForm();

  if (!form || !baseUrl) {
    return '';
  }

  const useSameAddress = getUseSameAddressValue();
  const idCountry = getFormValue(form, 'id_country');
  // Saved-address selection first; otherwise the autosave-persisted inline address id, so the
  // server takes its battle-tested id-branch (ownership check, cart pointer update,
  // pending-carrier restore) instead of mounting a temp placeholder. Raw fields below stay as
  // the fallback for the pre-persist window.
  const selectedSavedDeliveryAddressId = selectedDeliveryAddressId
    || getSelectedSavedDeliveryAddressId()
    || getPersistedInlineAddressId(OPC_SELECTORS.opc.deliveryFields, 'id_address_delivery');
  if (!selectedSavedDeliveryAddressId && !idCountry) {
    return '';
  }

  const url = new URL(baseUrl, window.location.origin);

  if (idCountry) {
    url.searchParams.set('id_country', idCountry);
  }

  if (useSameAddress === '0') {
    Object.entries(
      collectVisibleAddressContext(form, OPC_SELECTORS.opc.billingFields, INVOICE_ADDRESS_CONTEXT_FIELDS)
    ).forEach(([field, value]) => {
      url.searchParams.set(field, value);
    });

    // Same preference as the delivery side: the persisted separate-billing address id, so the
    // server skips the temp invoice mount (it already prefers a front-sent invoice id).
    const invoiceAddressId = getSelectedAddressId(OPC_SELECTORS.opc.billingList, 'id_address_invoice')
      || getPersistedInlineAddressId(OPC_SELECTORS.opc.billingFields, 'id_address_invoice');
    if (invoiceAddressId) {
      url.searchParams.set('id_address_invoice', invoiceAddressId);
    }
  }

  if (selectedSavedDeliveryAddressId) {
    url.searchParams.set('id_address_delivery', selectedSavedDeliveryAddressId);
  }

  const postcode = getFormValue(form, 'postcode');
  const idState = getFormValue(form, 'id_state');
  const city = getFormValue(form, 'city');

  if (postcode) {
    url.searchParams.set('postcode', postcode);
  }
  if (idState) {
    url.searchParams.set('id_state', idState);
  }
  if (city) {
    url.searchParams.set('city', city);
  }

  // Let the server honour the "use same address" choice when syncing the invoice address.
  url.searchParams.set('use_same_address', useSameAddress);

  return url.toString();
}

function syncSelectedDeliveryAddressContext() {
  const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
  const selectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();

  if (selectedSavedDeliveryAddressId) {
    selectedDeliveryAddressId = selectedSavedDeliveryAddressId;
    if (deliveryMethodsContainer) {
      deliveryMethodsContainer.setAttribute('data-id-address', selectedSavedDeliveryAddressId);
    }

    return;
  }

  selectedDeliveryAddressId = null;
  if (deliveryMethodsContainer) {
    deliveryMethodsContainer.removeAttribute('data-id-address');
  }
}

function renderCarrierAwaitingAddress() {
  renderAwaitingAddress($(CONTAINER_SELECTOR), OPC_SELECTORS.templates.carrierAwaitingAddress);
  setCarrierOptionsState(OPC_OPTION_LIST_STATE.AWAITING_ADDRESS);
}

function showCarrierLoading() {
  const $container = $(CONTAINER_SELECTOR);

  if (!$container.length) {
    return;
  }

  $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierLoader.replace('#', '')));
  setCarrierOptionsState(OPC_OPTION_LIST_STATE.LOADING);
}

function clearPendingCountryChangeRefresh() {
  pendingCountryChangeRefresh = false;
  if (pendingCountryChangeTimer) {
    window.clearTimeout(pendingCountryChangeTimer);
    pendingCountryChangeTimer = null;
  }
}

function beginPendingCountryChangeRefresh() {
  pendingCountryChangeRefresh = true;
  showCarrierLoading();
  // Mirror a normal carrier refresh: put the payment section in its loading state and disable the Pay
  // button (opc-submit listens to opcCarriersLoading) while we wait for the persist. The terminal event
  // (opcAddressPersisted -> fetch, or the awaiting paths) clears both.
  prestashop.emit(OPC_EVENTS.opcCarriersLoading, {});
  // Backstop so the deferred loader can NEVER outlive its persist confirmation: if no terminal event
  // (opcAddressPersisted / opcAddressPersistFailed / opcAddressPersistInconclusive) ever arrives, force
  // a refresh against the by-then-current cart instead of spinning forever.
  if (pendingCountryChangeTimer) {
    window.clearTimeout(pendingCountryChangeTimer);
  }
  pendingCountryChangeTimer = window.setTimeout(() => {
    pendingCountryChangeTimer = null;
    if (pendingCountryChangeRefresh) {
      pendingCountryChangeRefresh = false;
      fetchCarriers();
    }
  }, PENDING_COUNTRY_CHANGE_BACKSTOP_MS);
}

// After the inline country change is persisted: fetch against the freshly persisted cart even if the
// section was AVAILABLE (a country change genuinely changes eligibility, so refreshReadiness' AVAILABLE
// guard must be bypassed here). Reveal ONLY on a SUCCESSFUL persist of the NEW-country address — a
// rejected/invalid intermediate autosave (e.g. the postcode is not yet valid for the new country) must
// NOT fetch, because the cart still holds the previous country until a valid address is saved (fetching
// then would feed modules the stale country). A later valid persist reveals via refreshReadiness, which
// fetches because the section is no longer AVAILABLE. Note: isCarrierSectionReady alone is not enough —
// a persisted-inline-address id from the PREVIOUS country keeps it "ready" during the change.
function resolvePendingCountryChangeAfterPersist(response) {
  clearPendingCountryChangeRefresh();

  const persisted = Boolean(response && response.address_persisted);
  if (persisted && isCarrierSectionReady() && isBuyerIdentified()) {
    fetchCarriers();
  } else {
    renderCarrierAwaitingAddress();
    prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {countryChange: true});
  }
}

const carrierReadiness = createSectionReadiness({
  getContainer: () => $(CONTAINER_SELECTOR),
  isReady: isCarrierSectionReady,
  getState: () => {
    const container = document.querySelector(CONTAINER_SELECTOR);

    return container instanceof HTMLElement ? container.dataset.opcCarriersState : '';
  },
  renderAwaiting: renderCarrierAwaitingAddress,
  showLoading: showCarrierLoading,
  fetch: () => fetchCarriers(),
});

function fetchCarriers() {
  const $container = $(CONTAINER_SELECTOR);

  if (!$container.length) {
    return;
  }

  // Identity gate at the single fetch chokepoint: options are only ever shown to an identified buyer
  // (logged-in, or a guest who entered a valid email + required consent so guest-init can attach the
  // address to a real customer). Without this, a non-readiness trigger — a cart mutation (updatedCart)
  // or a delivery-address-updated event — would fetch+reveal the carriers for a not-yet-identified
  // guest whose complete address is not yet persisted to any customer. The reveal then happens on
  // guest-init via the persist confirmation (opcAddressPersisted -> refreshReadiness).
  if (!isCarrierSectionReady() || !isBuyerIdentified()) {
    renderCarrierAwaitingAddress();
    // A carrier fetch was triggered by an address/cart change (e.g. deleting the last saved address)
    // but withheld because no usable delivery address remains. Notify the payment section so it
    // re-evaluates and withholds too — driven AFTER the carrier (preserving the carrier -> payment
    // order), rather than the payment listening to the raw address-update event in parallel.
    prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {});

    return;
  }

  // Persist-first: the typed inline address has a persist in flight and no persisted id
  // yet (first fill) — fetching now would price raw fields against nothing. Show the
  // loader and let the EXISTING persist rails follow up: refreshReadiness (driven by
  // opcAddressPersisted) fetches any non-AVAILABLE section, and the persist-failed /
  // inconclusive paths retract the loader. No extra listener — a second driver here
  // would double the round (one fetch per section per round is a pinned contract).
  if (
    hasPendingInlineDraft(OPC_SELECTORS.opc.deliveryFields)
    && !getSelectedAddressId(OPC_SELECTORS.opc.deliveryList, 'id_address_delivery')
    && !getPersistedInlineAddressId(OPC_SELECTORS.opc.deliveryFields, 'id_address_delivery')
  ) {
    showCarrierLoading();

    return;
  }

  const carriersUrl = buildCarriersUrl(getConfiguredOpcUrl(URL_KEY));
  const fallbackMessage = getConfiguredOpcMessage('loadCarriersFailed', 'Unable to load delivery methods.');

  if (!carriersUrl) {
    // No usable delivery country/address to query (form mid-re-render, or the address was cleared).
    // Never leave a previously-shown loader (e.g. a country-change defer, or the backstop calling here)
    // spinning: settle to the awaiting hint instead of returning silently.
    renderCarrierAwaitingAddress();
    prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {});

    return;
  }

  if (activeFetchCarriersRequest && activeFetchCarriersRequest.readyState !== AJAX_READY_STATE_DONE) {
    pendingCarriersRefetch = true;

    return;
  }

  const generation = ++fetchCarriersGeneration;

  $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierLoader.replace('#', '')));
  setCarrierOptionsState(OPC_OPTION_LIST_STATE.LOADING);
  prestashop.emit(OPC_EVENTS.opcCarriersLoading, {});

  const request = $.ajax({url: carriersUrl, timeout: CARRIERS_REQUEST_TIMEOUT_MS});
  activeFetchCarriersRequest = request;

  request
    .done((response) => {
      if (generation !== fetchCarriersGeneration) {
        return;
      }

      // Superseded mid-flight: the trailing refetch (armed below) renders against the freshest
      // state — rendering and emitting for THIS response would publish a stale round (and its
      // opcCarrierSelected would trigger a payment fetch for it).
      if (pendingCarriersRefetch) {
        return;
      }

      if (!response || response.success === false) {
        const resp = normalizeErrorResponse(response, fallbackMessage);
        $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierError.replace('#', '')));
        setCarrierOptionsState(OPC_OPTION_LIST_STATE.FAILED);
        prestashop.emit(FAILED_EVENT_NAME, {resp});
        prestashop.emit('handleError', {eventType: 'opcCarriers', resp});
        return;
      }

      $container.html(response.carriers_html || '');
      refreshCarrierOptionsState();
      if (typeof response.id_address_delivery !== 'undefined') {
        if (response.id_address_delivery) {
          $container.attr('data-id-address', String(response.id_address_delivery));
        } else {
          $container.removeAttr('data-id-address');
        }
      }
      if (response.preview) {
        updateCartSummary(response.preview, response.totals);
      }
      emitWithContext(OPC_EVENTS.opcCarriersUpdated, response);

      const checkedCarrier = $container.find(`${OPC_SELECTORS.inputs.deliveryOption}:checked`).get(0);
      if (checkedCarrier) {
        const selectedDeliveryOption = String(checkedCarrier.value || '');

        $container.attr('data-confirmed-delivery-option', selectedDeliveryOption);
        prestashop.emit(OPC_EVENTS.opcCarrierSelected, {
          selectedDeliveryOption,
          response,
        });
      } else {
        $container.removeAttr('data-confirmed-delivery-option');
      }
    })
    .fail((jqXHR, textStatus) => {
      if (generation !== fetchCarriersGeneration) {
        return;
      }

      if (textStatus === AJAX_STATUS_ABORT) {
        return;
      }

      // Superseded mid-flight: skip the error render — the trailing refetch retries anyway.
      if (pendingCarriersRefetch) {
        return;
      }

      const resp = getAjaxErrorResponse(jqXHR, fallbackMessage);
      $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierError.replace('#', '')));
      setCarrierOptionsState(OPC_OPTION_LIST_STATE.FAILED);
      prestashop.emit(FAILED_EVENT_NAME, {resp});
      prestashop.emit('handleError', {eventType: 'opcCarriers', resp});
    })
    .always(() => {
      if (activeFetchCarriersRequest === request) {
        activeFetchCarriersRequest = null;
      }

      if (pendingCarriersRefetch) {
        pendingCarriersRefetch = false;
        fetchCarriers();
      }
    });
}

$(function () {
  const form = getCheckoutForm();

  if (!form) {
    return;
  }

  fetchCarriers();
});

$(document).on('click', '[data-opc-action="retry-carriers"]', (event) => {
  event.preventDefault();
  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcCarriersRetry, fetchCarriers);

prestashop.on(OPC_EVENTS.opcDeliveryCountryChanging, () => {
  // Inline country change with the autosave active: the new country is not yet persisted. Hold on a
  // loader and wait for the persist confirmation (opcAddressPersisted) before fetching, so the list is
  // computed against the persisted cart. Guard so we never loader-spin for an address that will not
  // produce a persist confirmation (not identified / incomplete / a prior persist already failed) —
  // show the awaiting hint instead. When the autosave is inactive (saved-address flow) do nothing here;
  // the normal opcDeliveryAddressUpdated fetch handles that case.
  //
  // Virtual cart: there is NO delivery-methods section (the template omits it). The carrier-driven
  // defer must not engage at all — otherwise it would force the payment section to awaiting on a
  // country change; payment refreshes on its own (updatedCart) with the new country.
  if (!$(CONTAINER_SELECTOR).length || !isInlineAutosaveActive()) {
    return;
  }

  if (!isCarrierSectionReady() || !isBuyerIdentified() || hasAddressPersistFailed()) {
    clearPendingCountryChangeRefresh();
    renderCarrierAwaitingAddress();
    prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {countryChange: true});
    return;
  }

  beginPendingCountryChangeRefresh();
});

prestashop.on(OPC_EVENTS.opcDeliveryAddressUpdated, () => {
  syncSelectedDeliveryAddressContext();

  // While an inline country change is deferring, the eager fetch is suppressed — the refresh happens on
  // the persist confirmation (below), against the persisted cart. Modal / saved-address saves persist
  // BEFORE they emit and never arm this flag, so they keep fetching immediately here.
  if (pendingCountryChangeRefresh) {
    // The new-country form has just re-rendered (this event fires right after the swap + id clear). If
    // it is NOT usable yet — a country that now requires a field left empty (e.g. a US state) or an
    // otherwise incomplete/invalid address — settle to the awaiting hint NOW instead of holding the
    // loader until the autosave's terminal persist event. That event races the re-render (a debounced
    // autosave vs the addressform swap) and a fast-timing interleaving can delay or drop it, leaving the
    // carrier loader spinning until the backstop — the "infinite loader on France -> United States"
    // symptom. A usable form keeps deferring, so the autosave persist still reveals it against the
    // fresh cart (no behaviour change for a valid new country).
    if (!isCarrierSectionReady()) {
      clearPendingCountryChangeRefresh();
      renderCarrierAwaitingAddress();
      prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {countryChange: true});
    }

    return;
  }

  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcDeliveryAddressSelected, ({idAddress}) => {
  selectedDeliveryAddressId = String(idAddress || '');
  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcAddressesUpdated, () => {
  // An address delete / list refresh re-renders the address section WITHOUT firing input/change on
  // the old fields, so the debounced readiness listener misses it. Re-evaluate: when no usable
  // address remains for the carrier section — notably the last SEPARATE BILLING address was deleted
  // (use_same off gates the carriers on the billing address too) — retract to awaiting and emit so
  // the payment section follows (it chains off opcCarriersAwaiting). The AVAILABLE-guard inside
  // syncReadiness keeps ordinary list refreshes from re-fetching or downgrading a settled section.
  if (!$(CONTAINER_SELECTOR).length) {
    return;
  }

  carrierReadiness.syncReadiness();

  if (!isCarrierSectionReady()) {
    prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {});
  }
});

prestashop.on(OPC_EVENTS.opcGuestInitSuccess, () => {
  syncSelectedDeliveryAddressContext();
  // Route through the readiness sync (NOT a direct fetch): when the inline autosave is active the
  // reveal must WAIT for the server's persist+validation confirmation (opcAddressPersisted) instead
  // of racing it. Otherwise identifying (email+consent) right after editing a field to an invalid
  // value — e.g. a bad postcode — reveals the carriers before the validation error lands. A saved
  // address (no autosave) still fetches directly via syncReadiness's else-branch (no behaviour change).
  carrierReadiness.syncReadiness();
});

prestashop.on(CORE_EVENTS.updatedCart, () => {
  // Cart mutations can change shipping eligibility and carrier prices.
  fetchCarriers();
});

// On every address-field edit, keep the awaiting/loading state in sync (which required fields are
// still missing, or a loader once complete). The actual reveal/fetch waits for the server to confirm
// the address is persisted & valid (opcAddressPersisted), so the list is computed against the
// persisted cart and never revealed for an address that is about to be rejected by validation.
let carrierReadinessTimer = null;
$('body').on(
  'input change',
  // Contact inputs (email + required consent) are included: they gate guest-init, so changing them
  // must re-evaluate readiness — otherwise the awaiting hint stays stale (e.g. still "enter your
  // email" after the email is typed, or before a required consent box is ticked).
  // The use_same_address checkbox is included (like the payment listener): a separate billing gates the
  // carrier section too (hasUsableSeparateBillingAddress), so toggling it must re-evaluate readiness —
  // retract when a separate billing is required but incomplete, reveal again when it is re-checked. It
  // sits outside the delivery/billing field wrappers, so without it the carrier section would go out of
  // sync with the payment section under PS_TAX_ADDRESS_TYPE=id_address_delivery (no carrier re-fetch on
  // the toggle, since carrier prices do not depend on billing there).
  `${OPC_SELECTORS.opc.deliveryFields} input, ${OPC_SELECTORS.opc.deliveryFields} select, ${OPC_SELECTORS.opc.billingFields} input, ${OPC_SELECTORS.opc.billingFields} select, ${OPC_SELECTORS.opc.useSameAddress}, ${OPC_SELECTORS.opc.contactSection} input`,
  () => {
    window.clearTimeout(carrierReadinessTimer);
    carrierReadinessTimer = window.setTimeout(carrierReadiness.syncReadiness, 250);
  }
);

prestashop.on(OPC_EVENTS.opcAddressPersisted, (response) => {
  syncSelectedDeliveryAddressContext();

  // A deferred inline country change resolves here — but only a SUCCESSFUL persist reveals; a rejected
  // intermediate autosave stays awaiting so a later valid persist reveals via refreshReadiness below.
  if (pendingCountryChangeRefresh) {
    resolvePendingCountryChangeAfterPersist(response);
    return;
  }

  carrierReadiness.refreshReadiness();
});

// Persistence failed (guest-init or autosave): re-evaluate so the section drops its loader for the
// recoverable error hint instead of spinning forever.
prestashop.on(OPC_EVENTS.opcAddressPersistFailed, () => {
  // During a pending country-change defer, syncReadiness would KEEP the loader (a previous-country
  // persisted-inline-id still reads as "ready" while the autosave is active), so force the awaiting hint
  // instead — the deferred loader must never spin forever. A later valid persist reveals via
  // refreshReadiness.
  if (pendingCountryChangeRefresh) {
    clearPendingCountryChangeRefresh();
    renderCarrierAwaitingAddress();
    prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {countryChange: true});
    return;
  }

  carrierReadiness.syncReadiness();
});

// A pending country-change defer whose autosave was inconclusive (no definitive persist result): clear
// the deferred loader to the awaiting hint instead of spinning forever. Ignored when nothing is pending.
prestashop.on(OPC_EVENTS.opcAddressPersistInconclusive, () => {
  if (!pendingCountryChangeRefresh) {
    return;
  }
  clearPendingCountryChangeRefresh();
  renderCarrierAwaitingAddress();
  prestashop.emit(OPC_EVENTS.opcCarriersAwaiting, {countryChange: true});
});

const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
const initiallySelectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();
if (deliveryMethodsContainer && initiallySelectedSavedDeliveryAddressId) {
  selectedDeliveryAddressId = initiallySelectedSavedDeliveryAddressId;
}
}());
