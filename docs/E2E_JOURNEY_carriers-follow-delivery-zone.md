# E2E user journey - delivery methods follow a state or postcode change

Behaviour: after the delivery methods are shown, changing the inline delivery state, postcode or
city refreshes them once the address is saved, as a country change already does. Automatable as
a Playwright scenario against a 9.2 shop with the module enabled and one-page checkout on.

## Preconditions

- Guest checkout enabled; a cart with one orderable product, on `/order`.
- Country "United States" active with states. Two states in different zones, for example
  "New York" in "North America" and "Hawaii" moved to "Oceania" (International > Locations > States).
- Carrier A ("My carrier") delivers to "North America" only; carrier B ("Click and collect") to
  "Oceania" only (Shipping > Carriers > Shipping locations).

## Steps

1. Enter a guest e-mail and tick the required consent.
2. Inline delivery address: country "United States", first name, last name, address, city,
   state "New York", postcode `10001`.
3. Wait for the delivery methods. Assert: carrier A only.
4. Change the state to "Hawaii" and leave the field.
5. Assert: one `carriers` request is sent after the `savedraft` response, its URL carries
   `id_state=<Hawaii id>`; the list shows carrier B only; the summary shipping line follows it.
6. Change the last name and leave the field. Assert: a `savedraft` request and no new
   `carriers` request.

## Notes for automation

- Fill fields with real input events (Playwright `fill`); the checkout ignores untrusted events.
- The inline fields are `#opc-delivery-address-fields [name="id_state"]` and siblings. The hidden
  address modal renders the same field names (`#modal-delivery-field-*`) earlier in the form, so
  scope selectors to the inline container.
