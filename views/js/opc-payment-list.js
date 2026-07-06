import {CORE_EVENTS, OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import OPC_OPTION_LIST_STATE from './runtime/opc-option-list-state';
import {getAjaxErrorResponse, getConfiguredOpcMessage, getConfiguredOpcUrl, normalizeErrorResponse} from './runtime/opc-runtime';
import {emitWithContext} from './runtime/opc-context-sync';
import {
  collectVisibleAddressContext,
  getUseSameAddressValue,
  getSelectedOrInlineAddressId,
  INVOICE_ADDRESS_CONTEXT_FIELDS,
  isBuyerIdentified,
  isPaymentSectionReady,
} from './runtime/address/opc-address-context';
import {createSectionReadiness, renderAwaitingAddress} from './runtime/address/opc-section-readiness';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcPaymentListRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const CONTAINER_SELECTOR = OPC_SELECTORS.opc.paymentMethods;
const CHECKOUT_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const URL_KEY = 'paymentMethods';
let fetchGeneration = 0;
let lastFetchedPaymentListDom = null;

function setPaymentOptionsState(state) {
  const container = document.querySelector(CONTAINER_SELECTOR);

  if (container instanceof HTMLElement) {
    container.dataset.opcPaymentMethodsState = state;
  }
}

function refreshPaymentOptionsState() {
  const container = document.querySelector(CONTAINER_SELECTOR);

  if (!(container instanceof HTMLElement)) {
    return;
  }

  container.dataset.opcPaymentMethodsState = container.querySelector(OPC_SELECTORS.inputs.paymentOption)
    ? OPC_OPTION_LIST_STATE.AVAILABLE
    : OPC_OPTION_LIST_STATE.EMPTY;
}

function getTemplateHtml(templateId) {
  const template = document.querySelector(`#${templateId}`);

  return template ? template.innerHTML : '';
}

function getContainer() {
  return $(CONTAINER_SELECTOR);
}

function getCheckoutForm() {
  return document.querySelector(CHECKOUT_FORM_SELECTOR);
}

function hasSelectedCarrier() {
  const deliveryMethods = document.querySelector(OPC_SELECTORS.opc.deliveryMethods);

  if (!(deliveryMethods instanceof HTMLElement)) {
    return false;
  }

  return Boolean(
    deliveryMethods.querySelector(`${OPC_SELECTORS.inputs.deliveryOption}:checked`)
  );
}

function getLoaderOverlay() {
  return document.getElementById('opc-payment-methods-loader');
}

function showLoader() {
  const overlay = getLoaderOverlay();
  if (!overlay) {
    return;
  }

  const wasHidden = overlay.classList.contains('d-none');
  overlay.classList.remove('d-none');
  overlay.setAttribute('aria-hidden', 'false');

  if (wasHidden) {
    setPaymentOptionsState(OPC_OPTION_LIST_STATE.LOADING);
    prestashop.emit(OPC_EVENTS.opcPaymentMethodsLoading, {});
  }
}

function hideLoader() {
  const overlay = getLoaderOverlay();
  if (!overlay) {
    return;
  }
  overlay.classList.add('d-none');
  overlay.setAttribute('aria-hidden', 'true');
}

function buildPaymentMethodsUrl(baseUrl) {
  const form = getCheckoutForm();

  if (!form || !baseUrl) {
    return '';
  }

  const url = new URL(baseUrl, window.location.origin);
  const idCountry = form.querySelector('[name="id_country"]')?.value
    || form.querySelector('[name="delivery_id_country"]')?.value
    || '';
  const useSameAddress = getUseSameAddressValue();
  const invoiceContext = useSameAddress === '0'
    ? collectVisibleAddressContext(form, OPC_SELECTORS.opc.billingFields, INVOICE_ADDRESS_CONTEXT_FIELDS)
    : {};
  const invoiceIdCountry = invoiceContext.invoice_id_country || '';
  const deliveryAddressId = getSelectedOrInlineAddressId(
    OPC_SELECTORS.opc.deliveryList,
    OPC_SELECTORS.opc.deliveryFields,
    'id_address_delivery'
  );
  // When billing mirrors delivery ("use same address"), the billing radio/hidden field is not
  // re-rendered on a delivery change and would carry a stale invoice address. Mirror delivery
  // explicitly so country-restricted payment methods are evaluated against the right address.
  const invoiceAddressId = useSameAddress === '1'
    ? deliveryAddressId
    : getSelectedOrInlineAddressId(
      OPC_SELECTORS.opc.billingList,
      OPC_SELECTORS.opc.billingFields,
      'id_address_invoice'
    );

  if (idCountry) {
    url.searchParams.set('id_country', idCountry);
  }

  if (invoiceIdCountry) {
    url.searchParams.set('invoice_id_country', invoiceIdCountry);
  }

  if (deliveryAddressId) {
    url.searchParams.set('id_address_delivery', deliveryAddressId);
  }

  if (invoiceAddressId) {
    url.searchParams.set('id_address_invoice', invoiceAddressId);
  }

  return url.toString();
}

// Core's checkout-payment.js machinery (binary block visibility, terms gating) only reacts to
// real `change` events, which programmatic list injections never dispatch — Core never needs to:
// every checkout mutation reloads the page there. Re-arm it after every payment-list DOM
// replacement: with a radio present, a synthetic change re-runs toggleOrderButton (which itself
// handles the no-selection case by hiding every binary block); with NO radio left (awaiting and
// error templates), hide the module blocks directly through Core's canonical selector, with the
// same inline-style mechanism Core uses so a later toggleOrderButton show() still reveals them.
function resyncBinaryPaymentMachinery() {
  // No binary module on the page — nothing for Core's machinery to re-arm, and the synthetic
  // change below also triggers OPC's own selection listener: skip entirely so ordinary shops get
  // zero extra traffic.
  const paymentBinarySelector = (prestashop.selectors && prestashop.selectors.checkout && prestashop.selectors.checkout.paymentBinary)
    || '.payment-binary, .js-payment-binary';
  if (!document.querySelector(paymentBinarySelector)) {
    return;
  }

  const container = document.querySelector(CONTAINER_SELECTOR);
  const radio = container
    ? (container.querySelector(`${OPC_SELECTORS.inputs.paymentOption}:checked`)
      || container.querySelector(OPC_SELECTORS.inputs.paymentOption))
    : null;

  if (radio) {
    radio.dispatchEvent(new Event('change', {bubbles: true}));

    return;
  }

  document.querySelectorAll(paymentBinarySelector).forEach((block) => {
    block.style.display = 'none';
  });
}

function renderPaymentAwaitingAddress() {
  renderAwaitingAddress(getContainer(), OPC_SELECTORS.templates.paymentAwaitingAddress);
  lastFetchedPaymentListDom = null;
  setPaymentOptionsState(OPC_OPTION_LIST_STATE.AWAITING_ADDRESS);
  hideLoader();
  resyncBinaryPaymentMachinery();
}

function showPaymentLoading() {
  showLoader();
  setPaymentOptionsState(OPC_OPTION_LIST_STATE.LOADING);
}

const paymentReadiness = createSectionReadiness({
  getContainer,
  isReady: isPaymentSectionReady,
  getState: () => {
    const container = document.querySelector(CONTAINER_SELECTOR);

    return container instanceof HTMLElement ? container.dataset.opcPaymentMethodsState : '';
  },
  renderAwaiting: renderPaymentAwaitingAddress,
  showLoading: showPaymentLoading,
  fetch: () => fetchPaymentMethods(),
});

function fetchPaymentMethods() {
  const $container = getContainer();

  if (!$container.length) {
    return;
  }

  // Identity gate at the single fetch chokepoint: options are only ever shown to an identified buyer
  // (logged-in, or a guest who entered a valid email + required consent). Without this, a non-readiness
  // trigger — a cart mutation (updatedCart), a carriers refresh, or an address-update event — would
  // fetch+reveal payment for a not-yet-identified guest whose complete address is not yet persisted to
  // any customer. The reveal then happens on guest-init via opcAddressPersisted -> refreshReadiness.
  if (!isPaymentSectionReady() || !isBuyerIdentified()) {
    renderPaymentAwaitingAddress();

    return;
  }

  const paymentMethodsUrl = buildPaymentMethodsUrl(getConfiguredOpcUrl(URL_KEY));
  const fallbackMessage = getConfiguredOpcMessage('loadPaymentMethodsFailed', 'Unable to load payment methods.');

  if (!paymentMethodsUrl) {
    hideLoader();
    return;
  }

  const generation = ++fetchGeneration;
  showLoader();

  $.get(paymentMethodsUrl)
    .done((response) => {
      if (generation !== fetchGeneration) {
        return;
      }

      if (!response || response.success === false) {
        const error = normalizeErrorResponse(response, fallbackMessage);
        $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
        lastFetchedPaymentListDom = null;
        hideLoader();
        setPaymentOptionsState(OPC_OPTION_LIST_STATE.FAILED);
        resyncBinaryPaymentMachinery();
        prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error});
        prestashop.emit('handleError', {eventType: 'opcPaymentMethods', resp: error});

        return;
      }

      const responsePaymentHtml = response.payment_html || '';

      if (lastFetchedPaymentListDom !== responsePaymentHtml) {
        $container.html(responsePaymentHtml);
        lastFetchedPaymentListDom = responsePaymentHtml;
        refreshPaymentOptionsState();
        resyncBinaryPaymentMachinery();
        emitWithContext(OPC_EVENTS.opcPaymentMethodsUpdated, response);
      } else {
        refreshPaymentOptionsState();
        emitWithContext(OPC_EVENTS.opcPaymentMethodsRefreshed, response);
      }

      hideLoader();
    })
    .fail((jqXHR) => {
      if (generation !== fetchGeneration) {
        return;
      }

      const error = getAjaxErrorResponse(jqXHR, fallbackMessage);
      $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
      lastFetchedPaymentListDom = null;
      hideLoader();
      setPaymentOptionsState(OPC_OPTION_LIST_STATE.FAILED);
      resyncBinaryPaymentMachinery();
      prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error});
      prestashop.emit('handleError', {eventType: 'opcPaymentMethods', resp: error});
    });
}

$(fetchPaymentMethods);
$(document).on('click', '[data-opc-action="retry-payment"]', (event) => {
  event.preventDefault();
  fetchPaymentMethods();
});

prestashop.on(CORE_EVENTS.updatedCart, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarrierSelected, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarriersUpdated, () => {
  if (!hasSelectedCarrier()) {
    fetchPaymentMethods();
  }
});
// Route guest-init through the readiness sync (NOT a direct fetch): when the inline autosave is active
// the reveal waits for the persist+validation confirmation (opcAddressPersisted) instead of racing it,
// so identifying right after editing a field to an invalid value does not reveal payment before the
// validation error lands. A saved address (no autosave) still fetches directly via syncReadiness.
prestashop.on(OPC_EVENTS.opcGuestInitSuccess, paymentReadiness.syncReadiness);
// Persistence failed (guest-init or autosave): re-evaluate so the section drops its loader for the
// recoverable error hint instead of spinning forever.
prestashop.on(OPC_EVENTS.opcAddressPersistFailed, paymentReadiness.syncReadiness);
prestashop.on(OPC_EVENTS.opcPaymentMethodsRetry, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarriersLoading, () => {
  fetchGeneration += 1;
  showLoader();
});

prestashop.on(OPC_EVENTS.opcCarriersFailed, () => {
  const $container = getContainer();

  fetchGeneration += 1;
  if ($container.length) {
    $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
    lastFetchedPaymentListDom = null;
    setPaymentOptionsState(OPC_OPTION_LIST_STATE.FAILED);
    resyncBinaryPaymentMachinery();
  }
  hideLoader();

  prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error: 'carrier fetch failed'});
});

// Reveal the payment section as soon as the address becomes complete (delivery, plus the billing/tax
// address when PS_TAX_ADDRESS_TYPE=invoice with a separate billing) — keeping the missing-fields hint
// in sync — decoupled from the debounced autosave/persist.
let paymentReadinessTimer = null;
$('body').on(
  'input change',
  // Contact inputs (email + required consent) gate guest-init, so changing them must re-evaluate
  // readiness too — otherwise the payment awaiting hint stays stale after the email/consent changes.
  `${OPC_SELECTORS.opc.deliveryFields} input, ${OPC_SELECTORS.opc.deliveryFields} select, ${OPC_SELECTORS.opc.billingFields} input, ${OPC_SELECTORS.opc.billingFields} select, ${OPC_SELECTORS.opc.useSameAddress}, ${OPC_SELECTORS.opc.contactSection} input`,
  () => {
    window.clearTimeout(paymentReadinessTimer);
    paymentReadinessTimer = window.setTimeout(paymentReadiness.syncReadiness, 250);
  }
);

prestashop.on(OPC_EVENTS.opcAddressPersisted, paymentReadiness.refreshReadiness);
// When the carrier section withholds because no usable delivery address remains (e.g. the customer
// deleted their last saved address — which re-renders the address section WITHOUT firing an
// input/change on the old fields, so the debounced listener above misses it), re-evaluate payment
// readiness so it withholds too, instead of leaving a stale payment list shown for an address that no
// longer exists. Chaining off the carrier's opcCarriersAwaiting (NOT the raw delivery-address-updated
// event) preserves the established carrier -> payment order: the carrier re-evaluates first and emits,
// then the payment follows. syncReadiness only withholds when not ready and short-circuits when already
// available, so a normal valid update triggers no extra fetch.
// A country change that did not persist a valid new-country address must retract payment to awaiting
// too — even when a PREVIOUS-country address is still persisted (which would otherwise keep the section
// "ready", and the readiness AVAILABLE-guard would leave the previous country's options shown). The
// carrier tags that case (countryChange). Other awaiting triggers keep the readiness-based behaviour.
prestashop.on(OPC_EVENTS.opcCarriersAwaiting, (payload) => {
  if (payload && payload.countryChange) {
    // Invalidate any in-flight payment fetch (e.g. a trailing fetch from the previous country) so its
    // .done() cannot render stale options AFTER we retract to awaiting for the unpersisted new country.
    fetchGeneration += 1;
    renderPaymentAwaitingAddress();

    return;
  }

  paymentReadiness.syncReadiness();
});
}());
