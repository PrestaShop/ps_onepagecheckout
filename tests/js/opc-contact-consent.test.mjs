import assert from 'node:assert/strict';
import test from 'node:test';

import {
  hasUncheckedRequiredConsentForContainer,
  isRequiredConsentMissingForContainer,
} from '../../views/js/runtime/address/opc-contact-consent-state.mjs';

const ADDRESS_MODAL_SELECTOR = '#opc-address-modal, #modal-delivery, #modal-invoice';
const CONTACT_EMAIL_SELECTOR = 'input[name="email"]';

function checkbox({
  name = 'privacy[terms]',
  checked = false,
  disabled = false,
  insideAddressModal = false,
} = {}) {
  return {
    name,
    checked,
    disabled,
    closest(selector) {
      assert.equal(selector, ADDRESS_MODAL_SELECTOR);

      return insideAddressModal ? {} : null;
    },
  };
}

function contactContainer(checkboxes = [], {emailFieldPresent = true} = {}) {
  return {
    querySelectorAll(selector) {
      assert.equal(selector, 'input[type="checkbox"][required]');

      return checkboxes;
    },
    querySelector(selector) {
      assert.equal(selector, CONTACT_EMAIL_SELECTOR);

      return emailFieldPresent ? {} : null;
    },
  };
}

test('a contact section that has not rendered yet remains gated', () => {
  assert.equal(isRequiredConsentMissingForContainer(null, ADDRESS_MODAL_SELECTOR, CONTACT_EMAIL_SELECTOR), true);
  assert.equal(hasUncheckedRequiredConsentForContainer(null, ADDRESS_MODAL_SELECTOR), false);
});

test('a contact section without the email field is not rendered enough to identify a guest', () => {
  const container = contactContainer([], {emailFieldPresent: false});

  assert.equal(isRequiredConsentMissingForContainer(container, ADDRESS_MODAL_SELECTOR, CONTACT_EMAIL_SELECTOR), true);
  assert.equal(hasUncheckedRequiredConsentForContainer(container, ADDRESS_MODAL_SELECTOR), false);
});

test('a rendered contact section with no required consent does not block checkout', () => {
  const container = contactContainer();

  assert.equal(isRequiredConsentMissingForContainer(container, ADDRESS_MODAL_SELECTOR, CONTACT_EMAIL_SELECTOR), false);
  assert.equal(hasUncheckedRequiredConsentForContainer(container, ADDRESS_MODAL_SELECTOR), false);
});

test('an unchecked required consent blocks checkout and can be named by the hint', () => {
  const container = contactContainer([checkbox()]);

  assert.equal(isRequiredConsentMissingForContainer(container, ADDRESS_MODAL_SELECTOR, CONTACT_EMAIL_SELECTOR), true);
  assert.equal(hasUncheckedRequiredConsentForContainer(container, ADDRESS_MODAL_SELECTOR), true);
});

test('checked required consent satisfies the gate', () => {
  const container = contactContainer([checkbox({checked: true})]);

  assert.equal(isRequiredConsentMissingForContainer(container, ADDRESS_MODAL_SELECTOR, CONTACT_EMAIL_SELECTOR), false);
  assert.equal(hasUncheckedRequiredConsentForContainer(container, ADDRESS_MODAL_SELECTOR), false);
});

test('disabled, unnamed, and address-modal checkboxes do not count as required consent', () => {
  const container = contactContainer([
    checkbox({disabled: true}),
    checkbox({name: ''}),
    checkbox({insideAddressModal: true}),
  ]);

  assert.equal(isRequiredConsentMissingForContainer(container, ADDRESS_MODAL_SELECTOR, CONTACT_EMAIL_SELECTOR), false);
  assert.equal(hasUncheckedRequiredConsentForContainer(container, ADDRESS_MODAL_SELECTOR), false);
});
