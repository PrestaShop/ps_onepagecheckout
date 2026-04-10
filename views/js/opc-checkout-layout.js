/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
(function psOpcCheckoutLayoutRuntime() {

const $ = window.$ || window.jQuery;

const CONFIGURATION_CONTAINER_SELECTOR = '.psopc-configuration';
const SAVE_BTN_SELECTOR = '#psopc-save-btn';
const CONFIRM_BTN_SELECTOR = '#psopc-confirm-btn';
const MODAL_SELECTOR = '#psopc-confirmation-modal';
const FORM_SELECTOR = '.psopc-configuration form';
const MAINTENANCE_HIDDEN_SELECTOR = '#psopc-maintenance-hidden';
const MAINTENANCE_TOGGLE_SELECTOR = '#psopc-maintenance-toggle';
const OPC_ACTIVATION_SELECTOR = '[data-psopc-modal="opc-activation"]';
const FOUR_PAGE_ACTIVATION_SELECTOR = '[data-psopc-modal="four-page-activation"]';

/**
 * @param {boolean} isOpc
 */
function updateModalContent(isOpc) {
  const opcActivationSelectors = document.querySelectorAll(OPC_ACTIVATION_SELECTOR);
  const fourPageActivationSelectors = document.querySelectorAll(FOUR_PAGE_ACTIVATION_SELECTOR);

  opcActivationSelectors.forEach(function (el) {
    el.style.display = isOpc ? '' : 'none';
  });

  fourPageActivationSelectors.forEach(function (el) {
    el.style.display = isOpc ? 'none' : '';
  });
}

/**
 * @returns {string|null}
 */
function getConfigurationKey() {
  const container = document.querySelector(CONFIGURATION_CONTAINER_SELECTOR);
  if (!container) {
    return null;
  }
  return container.dataset.opcConfigurationKey || null;
}

/**
 * @param {string} configurationKey
 * @returns {NodeListOf<HTMLInputElement>}
 */
function getRadioInputs(configurationKey) {
  return document.querySelectorAll(`input[name="${configurationKey}"]`);
}

/**
 * @param {string} configurationKey
 * @returns {string|null}
 */
function getCheckedValue(configurationKey) {
  const checked = document.querySelector(`input[name="${configurationKey}"]:checked`);
  return checked ? checked.value : null;
}

function initCheckoutLayoutConfiguration() {
  const configurationKey = getConfigurationKey();
  if (!configurationKey) {
    return;
  }

  const saveBtn = document.querySelector(SAVE_BTN_SELECTOR);
  const confirmBtn = document.querySelector(CONFIRM_BTN_SELECTOR);
  const modal = $(MODAL_SELECTOR);
  const form = document.querySelector(FORM_SELECTOR);
  const radioInputs = getRadioInputs(configurationKey);
  const maintenanceHidden = document.querySelector(MAINTENANCE_HIDDEN_SELECTOR);
  const maintenanceToggle = document.querySelector(MAINTENANCE_TOGGLE_SELECTOR);

  if (!saveBtn || !confirmBtn || !form || !maintenanceHidden) {
    return;
  }

  const initialValue = getCheckedValue(configurationKey);

  saveBtn.disabled = true;

  radioInputs.forEach(function (radio) {
    radio.addEventListener('change', function () {
      const currentValue = getCheckedValue(configurationKey);
      saveBtn.disabled = (currentValue === initialValue);
    });
  });

  saveBtn.addEventListener('click', function () {
    const selectedValue = getCheckedValue(configurationKey);
    const isOpc = selectedValue === '1';

    updateModalContent(isOpc);

    if (maintenanceToggle) {
      maintenanceToggle.checked = false;
    }
    maintenanceHidden.value = '0';
    modal.modal('show');
  });

  if (maintenanceToggle) {
    maintenanceToggle.addEventListener('change', function () {
      maintenanceHidden.value = this.checked ? '1' : '0';
    });
  }

  confirmBtn.addEventListener('click', function () {
    modal.modal('hide');
    form.submit();
  });
}

document.addEventListener('DOMContentLoaded', initCheckoutLayoutConfiguration);

})();
