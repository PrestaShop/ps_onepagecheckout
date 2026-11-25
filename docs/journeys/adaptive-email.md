# E2E user journey — adaptive invalid-email message

Feature: the guest e-mail field reports what is wrong with the typed value instead of a
single generic message. Automatable as a Playwright scenario against a 9.2 shop with the
module enabled and one-page checkout on.

## Preconditions

- Guest visitor (not logged in), guest checkout enabled.
- A cart with at least one orderable product, on the one-page checkout (`/order`).
- The contact section is visible; the e-mail input is `input[name="email"]` (`#field-email`).

## Steps

The message is validated on blur (the field's `focusout`), not while typing, and renders in
the field's error slot `.js-opc-field-error` inside the contact section. For each row: focus
the e-mail input, type the value, blur the field, assert the visible error text.

| Typed value | Expected `.js-opc-field-error` text |
| --- | --- |
| `no-at-here` | The email address is missing an "@" (e.g. name@example.com). |
| `user@` | The email address is missing the part after the "@" (e.g. name@example.com). |
| `@example.com` | The email address is missing the part before the "@" (e.g. name@example.com). |
| `a@@b.com` | Please enter a valid email address. (generic fallback) |
| `good@example.com` | no error — the field error is cleared, guest-init proceeds |

## Notes for automation

- The three specific messages plus the generic fallback come from the module's translated
  `messages` config (`emailMissingAt`, `emailMissingDomain`, `emailMissingLocalPart`,
  `invalidEmail`); assert against the resolved shop-language text, not a hardcoded English string.
- An empty field on blur clears the error (no message), it does not assert "invalid".
- The pay button stays disabled while the e-mail is invalid.
