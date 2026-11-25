# Public surfaces

A change is breaking when a merchant, a theme or another module must react to it. These are the places where that
happens.

| Surface | Path | Symbols |
|---|---|---|
| Hooks the module registers, their names and parameters | `ps_onepagecheckout.php` | `actionCheckoutBuildProcess`, `actionFrontControllerSetMedia`, `actionFrontControllerSetVariables`, `actionCustomerLogoutAfter`, `displayOverrideTemplate` |
| Templates and Smarty blocks a theme overrides | `views/templates` | `checkout_process`, `cart_summary`, `express_checkout`, `address_selector_bottom` |
| JS events other modules listen to | `views/js/events.js` | `opcFinalSubmitStarted`, `updatedOpcAddressForm`, `opcPaymentMethodsUpdated`, `opcCarriersUpdated` |
| DOM ids, classes and selectors other modules read | `views/js/selectors.js` | `#opc-form`, `#opc-pay-button`, `.js-opc-contact-section`, `input[name="payment-option"]` |
| Front controller routes and their AJAX payloads | `controllers/front` | `AbstractOpcJsonFrontController` |
| Configuration keys | `ps_onepagecheckout.php` | `PS_ONE_PAGE_CHECKOUT_ENABLED` |
| Lowest PrestaShop version supported | `ps_onepagecheckout.php` | `ps_versions_compliancy` |
| Upgrade scripts a merchant runs | `upgrade` | |
