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
import {OPC_EVENTS} from './events';

(function psOpcCartSummaryStateRuntime() {
const prestashop = window.prestashop || null;

if (!prestashop || typeof prestashop.on !== 'function') {
  return;
}

// Bootstrap accordion items inside the cart summary collapse on every DOM
// replacement. Capture which ones were open before the update and reopen them
// after — no-op when the theme doesn't use Bootstrap accordions.
let openCollapseIds = [];

prestashop.on(OPC_EVENTS.opcCartSummaryBeforeUpdate, ({selector}) => {
  if (typeof selector !== 'string' || selector === '') {
    return;
  }

  openCollapseIds = Array.from(document.querySelectorAll(`${selector} .accordion-collapse.show`))
    .map((el) => el.id)
    .filter(Boolean);
});

prestashop.on(OPC_EVENTS.opcCartSummaryUpdated, () => {
  openCollapseIds.forEach((id) => {
    const el = document.getElementById(id);

    if (!el) {
      return;
    }

    el.classList.add('show');

    const btn = document.querySelector(`[data-bs-target="#${id}"]`);

    if (btn) {
      btn.classList.remove('collapsed');
      btn.setAttribute('aria-expanded', 'true');
    }
  });
  openCollapseIds = [];
});
})();
