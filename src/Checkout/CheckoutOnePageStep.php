<?php

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

namespace PrestaShop\Module\PsOnePageCheckout\Checkout;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Symfony\Contracts\Translation\TranslatorInterface;

class CheckoutOnePageStep extends \AbstractCheckoutStep
{
    protected $template = 'checkout/_partials/steps/one-page-checkout.tpl';

    /**
     * @var OnePageCheckoutForm
     */
    private $opcForm;

    /**
     * @var \PaymentOptionsFinder
     */
    public $paymentOptionsFinder;

    private PaymentSelectionKeyBuilder $paymentSelectionKeyBuilder;

    /**
     * @var \ConditionsToApproveFinder
     */
    public $conditionsToApproveFinder;

    // Delivery options (like CheckoutDeliveryStep)
    private $recyclablePackAllowed = false;
    private $giftAllowed = false;
    private $giftCost = 0;
    private $includeTaxes = false;
    private $displayTaxesLabel = false;

    private $validationErrors = [];
    private bool $clearPersistedValidationErrorsOnNextSave = false;
    private OnePageCheckoutSubmitValidationStateStorage $submitValidationStateStorage;

    /**
     * @param \Context $context
     * @param TranslatorInterface $translator
     * @param OnePageCheckoutForm $opcForm
     * @param \PaymentOptionsFinder $paymentOptionsFinder
     * @param \ConditionsToApproveFinder $conditionsToApproveFinder
     * @param PaymentSelectionKeyBuilder|null $paymentSelectionKeyBuilder
     */
    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        OnePageCheckoutForm $opcForm,
        \PaymentOptionsFinder $paymentOptionsFinder,
        \ConditionsToApproveFinder $conditionsToApproveFinder,
        ?PaymentSelectionKeyBuilder $paymentSelectionKeyBuilder = null,
        ?OnePageCheckoutSubmitValidationStateStorage $submitValidationStateStorage = null,
    ) {
        parent::__construct($context, $translator);
        $this->opcForm = $opcForm;
        $this->paymentOptionsFinder = $paymentOptionsFinder;
        $this->conditionsToApproveFinder = $conditionsToApproveFinder;
        $this->paymentSelectionKeyBuilder = $paymentSelectionKeyBuilder ?? new PaymentSelectionKeyBuilder();
        $this->submitValidationStateStorage = $submitValidationStateStorage ?? new OnePageCheckoutSubmitValidationStateStorage($context);
    }

    // Delivery options setters (like CheckoutDeliveryStep)
    public function setRecyclablePackAllowed($recyclablePackAllowed)
    {
        $this->recyclablePackAllowed = $recyclablePackAllowed;

        return $this;
    }

    public function isRecyclablePackAllowed()
    {
        return $this->recyclablePackAllowed;
    }

    public function setGiftAllowed($giftAllowed)
    {
        $this->giftAllowed = $giftAllowed;

        return $this;
    }

    public function isGiftAllowed()
    {
        return $this->giftAllowed;
    }

    public function setGiftCost($giftCost)
    {
        $this->giftCost = $giftCost;

        return $this;
    }

    public function getGiftCost()
    {
        return $this->giftCost;
    }

    public function setIncludeTaxes($includeTaxes)
    {
        $this->includeTaxes = $includeTaxes;

        return $this;
    }

    public function getIncludeTaxes()
    {
        return $this->includeTaxes;
    }

    public function setDisplayTaxesLabel($displayTaxesLabel)
    {
        $this->displayTaxesLabel = $displayTaxesLabel;

        return $this;
    }

    public function getDisplayTaxesLabel()
    {
        return $this->displayTaxesLabel;
    }

    public function handleRequest(array $requestParameters = [])
    {
        // Step is always reachable (single step)
        $this->setReachable(true);

        $this->hydrateOpcFromSession();
        $this->restoreLastFailedSubmitState();

        if (
            !$this->context->cart->isVirtualCart()
            && isset($requestParameters['delivery_option'])
            && is_array($requestParameters['delivery_option'])
        ) {
            $this->getCheckoutSession()->setDeliveryOption($requestParameters['delivery_option']);
        }

        $this->setTitle(
            $this->getTranslator()->trans(
                'Checkout',
                [],
                'Shop.Theme.Checkout'
            )
        );
    }

    private function hydrateOpcFromSession(): void
    {
        $session = $this->getCheckoutSession();

        $customer = $session->getCustomer();
        if ($customer && $customer->id) {
            $this->opcForm->fillFromCustomer($customer);
        }

        $this->fillOpcFormFromResolvedAddresses(
            (int) $session->getIdAddressDelivery(),
            (int) $session->getIdAddressInvoice()
        );
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function getValidationErrors()
    {
        return $this->validationErrors;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDataToPersist()
    {
        // This step still lives inside the Core CheckoutProcess injected by the module hook,
        // so it must keep the standard AbstractCheckoutStep persistence contract.
        return [
            'validation_errors' => $this->clearPersistedValidationErrorsOnNextSave ? [] : $this->validationErrors,
        ];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return $this
     */
    public function restorePersistedData(array $data)
    {
        $this->validationErrors = isset($data['validation_errors']) && is_array($data['validation_errors'])
            ? $data['validation_errors']
            : [];
        $this->clearPersistedValidationErrorsOnNextSave = !empty($this->validationErrors);

        return $this;
    }

    public function render(array $extraParams = [])
    {
        $isFree = 0 == (float) $this->getCheckoutSession()->getCart()->getOrderTotal(true, \Cart::BOTH);
        $paymentOptions = $this->paymentSelectionKeyBuilder->enrichPaymentOptions(
            $this->paymentOptionsFinder->present($isFree)
        );
        $conditionsToApprove = $this->conditionsToApproveFinder->getConditionsToApproveForTemplate();
        $deliveryOptions = $this->getCheckoutSession()->getDeliveryOptions();
        $deliveryOptionKey = $this->getCheckoutSession()->getSelectedDeliveryOption();

        if (isset($deliveryOptions[$deliveryOptionKey])) {
            $selectedDeliveryOption = $deliveryOptions[$deliveryOptionKey];
        } else {
            $selectedDeliveryOption = 0;
        }

        if (true === is_array($selectedDeliveryOption) && isset($selectedDeliveryOption['product_list'])) {
            unset($selectedDeliveryOption['product_list']);
        }

        $assignedVars = [
            'hookDisplayBeforeCarrier' => \Hook::exec('displayBeforeCarrier', ['cart' => $this->getCheckoutSession()->getCart()]),
            'hookDisplayAfterCarrier' => \Hook::exec('displayAfterCarrier', ['cart' => $this->getCheckoutSession()->getCart()]),
            'delivery_options' => $deliveryOptions,
            'delivery_option' => $deliveryOptionKey,
            'selected_delivery_option' => $selectedDeliveryOption,
            'payment_options' => $paymentOptions,
            'is_free' => $isFree,
            'selected_payment_module' => $this->getSelectedPaymentModule(),
            'selected_payment_selection_key' => $this->getSelectedPaymentSelectionKey(),
            'conditions_to_approve' => $conditionsToApprove,
            'validation_errors' => $this->validationErrors,
            'validation_error_messages' => $this->getValidationErrorMessages(),
            'recyclable' => $this->getCheckoutSession()->isRecyclable(),
            'recyclablePackAllowed' => $this->isRecyclablePackAllowed(),
            'delivery_message' => $this->getCheckoutSession()->getMessage(),
            'gift' => [
                'allowed' => $this->isGiftAllowed(),
                'isGift' => $this->getCheckoutSession()->getGift()['isGift'],
                'message' => $this->getCheckoutSession()->getGift()['message'],
            ],
            'is_virtual_cart' => $this->context->cart->isVirtualCart(),
            'configuration' => $this->getTemplateConfiguration(),
        ] + $this->opcForm->getTemplateVariables();

        return $this->renderTemplate($this->getTemplate(), $extraParams, $assignedVars);
    }

    private function getSelectedPaymentModule(): string
    {
        if (!isset($this->context->cookie)) {
            return '';
        }

        return (string) ($this->context->cookie->__get('opc_selected_payment_module') ?: '');
    }

    private function getSelectedPaymentSelectionKey(): string
    {
        if (!isset($this->context->cookie)) {
            return '';
        }

        return (string) ($this->context->cookie->__get('opc_selected_payment_selection_key') ?: '');
    }

    private function restoreLastFailedSubmitState(): void
    {
        $submitState = $this->submitValidationStateStorage->consume();
        if ($submitState === []) {
            return;
        }

        $submittedValues = isset($submitState['submitted_values']) && is_array($submitState['submitted_values'])
            ? $submitState['submitted_values']
            : [];
        $formErrors = isset($submitState['form_errors']) && is_array($submitState['form_errors'])
            ? $submitState['form_errors']
            : [];

        if ($submittedValues !== [] || $formErrors !== []) {
            $this->opcForm->restoreSubmissionState($submittedValues, $formErrors);
        }

        $this->validationErrors = isset($submitState['validation_errors']) && is_array($submitState['validation_errors'])
            ? $submitState['validation_errors']
            : [];

        if ($this->validationErrors !== [] || $formErrors !== []) {
            $this->clearPersistedValidationErrorsOnNextSave = true;
        }
    }

    /**
     * @return array<int,string>
     */
    private function getValidationErrorMessages(): array
    {
        $messages = [];

        array_walk_recursive($this->validationErrors, static function ($value) use (&$messages): void {
            if (!is_string($value) || $value === '') {
                return;
            }

            $messages[] = $value;
        });

        return array_values(array_unique($messages));
    }

    private function fillOpcFormFromResolvedAddresses(int $deliveryAddressId, int $invoiceAddressId, ?int $customerId = null): void
    {
        if ($deliveryAddressId <= 0 && $invoiceAddressId <= 0) {
            return;
        }

        $languageId = (int) $this->context->language->id;
        $deliveryAddress = $this->resolveAddressForHydration($deliveryAddressId, $languageId, $customerId);
        $invoiceAddress = null;

        if ($invoiceAddressId > 0 && $invoiceAddressId !== $deliveryAddressId) {
            $invoiceAddress = $this->resolveAddressForHydration($invoiceAddressId, $languageId, $customerId);
        }

        if ($deliveryAddress || $invoiceAddress) {
            $this->opcForm->fillFromAddresses($deliveryAddress, $invoiceAddress);
        }
    }

    private function resolveAddressForHydration(int $addressId, int $languageId, ?int $customerId = null): ?\Address
    {
        if (
            $addressId <= 0
            || ($customerId !== null && ($customerId <= 0 || !\Customer::customerHasAddress($customerId, $addressId)))
        ) {
            return null;
        }

        $address = new \Address($addressId, $languageId);

        return \Validate::isLoadedObject($address) ? $address : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function getTemplateConfiguration(): array
    {
        $configuration = [];
        $controller = $this->context->controller ?? null;

        if (is_object($controller) && method_exists($controller, 'getTemplateVarConfiguration')) {
            $configuration = (array) $controller->getTemplateVarConfiguration();
        }

        $configuration['is_guest_checkout_enabled'] = (bool) \Configuration::get('PS_GUEST_CHECKOUT_ENABLED');

        return $configuration;
    }
}
