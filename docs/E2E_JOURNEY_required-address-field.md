# E2E user journey - a required address field emptied after the options are shown

Behaviour: once the address is saved, the delivery and payment options stay shown while the buyer
edits it. A required inline address field the buyer empties and leaves gets an inline
"Required field" error; the next edit in that field clears it. Automatable as a Playwright
scenario against a 9.2 shop with the module enabled and one-page checkout on.

## Preconditions

- Guest checkout enabled; a cart with one orderable product, on `/order`.

## Steps

1. Click into the inline delivery "First name" and press Tab twice without typing.
   Assert: no `.js-opc-field-error` in `#opc-delivery-address-fields`.
2. Enter a guest e-mail, tick the required consent, fill the inline delivery address.
   Wait for the delivery methods.
3. Select the whole "Address" value, delete it, press Tab.
   Assert: the field has `is-invalid` and `aria-invalid="true"`, its group shows
   `.js-opc-field-error` with the core "Required field" text (`Shop.Forms.Errors`), and the
   delivery methods are still listed.
4. Type one character in "Address". Assert: the error and `is-invalid` are gone.
5. Uncheck "Use this address for invoice too", type in the billing "Address", delete it, press Tab.
   Assert: the same error on the billing field.

## Notes for automation

- Use real keyboard input (`fill`, `press`); the checkout ignores untrusted events.
- The hidden address modal renders the same field names (`#modal-delivery-field-*`) earlier in
  the form: scope selectors to `#opc-delivery-address-fields` / `#opc-billing-address-fields`.
