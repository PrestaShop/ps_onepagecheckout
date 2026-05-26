import {OPC_EVENTS} from './events';

export function emitAddressUpdate(type, payload = {}) {
  const prestashop = window.prestashop || {};

  if (type === 'delivery') {
    prestashop.emit(OPC_EVENTS.opcDeliveryAddressUpdated, payload);
    return;
  }

  if (type === 'billing') {
    prestashop.emit(OPC_EVENTS.opcBillingAddressUpdated, payload);
  }
}
