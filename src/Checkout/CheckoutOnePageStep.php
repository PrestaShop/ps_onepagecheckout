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

use Address;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Symfony\Contracts\Translation\TranslatorInterface;
use Validate;

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
    ) {
        parent::__construct($context, $translator);
        $this->opcForm = $opcForm;
        $this->paymentOptionsFinder = $paymentOptionsFinder;
        $this->conditionsToApproveFinder = $conditionsToApproveFinder;
        $this->paymentSelectionKeyBuilder = $paymentSelectionKeyBuilder ?? new PaymentSelectionKeyBuilder();
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

        // Pre-fill form from session if not submitting
        if (!isset($requestParameters['submitOnePageCheckout'])) {
            $this->hydrateOpcFromSession();
        }

        if (
            !$this->context->cart->isVirtualCart()
            && isset($requestParameters['delivery_option'])
            && is_array($requestParameters['delivery_option'])
            && !isset($requestParameters['submitOnePageCheckout'])
        ) {
            $this->getCheckoutSession()->setDeliveryOption($requestParameters['delivery_option']);
        }

        // Handle submission
        if (isset($requestParameters['submitOnePageCheckout'])) {
            $this->handleOnePageCheckoutSubmit($requestParameters);
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

        $languageId = $this->context->language->id;

        $idAddressDelivery = $session->getIdAddressDelivery();
        $idAddressInvoice = $session->getIdAddressInvoice();

        $deliveryAddress = $idAddressDelivery ? new \Address($idAddressDelivery, $languageId) : null;

        $invoiceAddress = null;
        if ($idAddressInvoice && $idAddressInvoice != $idAddressDelivery) {
            $invoiceAddress = new \Address($idAddressInvoice, $languageId);
        }

        if ($deliveryAddress || $invoiceAddress) {
            $this->opcForm->fillFromAddresses($deliveryAddress, $invoiceAddress);
        }
    }

    private function handleOnePageCheckoutSubmit(array $requestParameters): void
    {
        $this->hydrateOpcFromSubmittedAddresses($requestParameters);

        $validationResult = $this->validateAllSections($requestParameters);
        if (!$this->isAllSectionsValid($validationResult)) {
            $this->getCheckoutProcess()->setHasErrors(true);
            $this->setCurrent(true);

            return;
        }
        if (!$this->saveAllSections($requestParameters)) {
            $this->getCheckoutProcess()->setHasErrors(true);

            return;
        }
        $this->setComplete(true);
    }

    /**
     * Validate all sections (5 validations)
     *
     * @param array $requestParameters
     *
     * @return array ['identity' => bool, 'address' => bool, 'shipping' => bool, 'payment' => bool, 'conditions' => bool]
     */
    private function validateAllSections(array $requestParameters)
    {
        $this->validationErrors = [];
        $result = [
            'identity' => true,
            'address' => true,
            'shipping' => true,
            'payment' => true,
            'conditions' => true,
        ];

        // 1. Identity validation: registered customers reuse the email already stored in session.
        $customer = $this->context->customer;
        $email = (string) ($requestParameters['email'] ?? '');
        if (
            $email === ''
            && $customer instanceof \Customer
            && $customer->isLogged()
            && !$customer->isGuest()
        ) {
            $email = (string) $customer->email;
        }

        if ($email === '' || !\Validate::isEmail($email)) {
            $result['identity'] = false;
            $this->validationErrors['identity'] = [
                'email' => $this->getTranslator()->trans(
                    'Invalid email format.',
                    [],
                    'Shop.Notifications.Error'
                ),
            ];
        }

        // 2. Address validation: validate form
        $this->opcForm->fillWith($requestParameters);
        if (!$this->opcForm->validate()) {
            $result['address'] = false;
            $this->validationErrors['address'] = $this->opcForm->getErrors();
        }

        return $result;
    }

    /**
     * Check if all sections are valid
     *
     * @param array $validationResult
     *
     * @return bool
     */
    private function isAllSectionsValid(array $validationResult)
    {
        return $validationResult['identity']
            && $validationResult['address']
            && $validationResult['shipping']
            && $validationResult['payment']
            && $validationResult['conditions'];
    }

    /**
     * Save all sections (5 saves)
     *
     * @param array $requestParameters
     *
     * @return bool
     */
    private function saveAllSections(array $requestParameters)
    {
        // 1. Identity + Address: save via form (creates/updates customer guest and addresses)
        $customer = $this->context->customer;
        $isGuestFlow = !$customer->isLogged() || $customer->isGuest();
        if ($isGuestFlow) {
            $hookResult = array_reduce(
                \Hook::exec('actionSubmitAccountBefore', [], null, true),
                function ($carry, $item) {
                    return $carry && $item;
                },
                true
            );
            if (!$hookResult) {
                return false;
            }
        }

        $addressIds = $this->opcForm->fillWith($requestParameters)->submit();
        if (!$addressIds) {
            return false;
        }

        // Set addresses in session
        $this->getCheckoutSession()->setIdAddressDelivery($addressIds['id_address_delivery']);
        $this->getCheckoutSession()->setIdAddressInvoice($addressIds['id_address_invoice']);

        if (isset($requestParameters['delivery_message'])) {
            $this->getCheckoutSession()->setMessage($requestParameters['delivery_message']);
        }

        if ($this->isRecyclablePackAllowed()) {
            $this->getCheckoutSession()->setRecyclable($requestParameters['recyclable'] ?? false);
        }

        if ($this->isGiftAllowed()) {
            $useGift = $requestParameters['gift'] ?? false;
            $this->getCheckoutSession()->setGift(
                $useGift,
                $useGift ? ($requestParameters['gift_message'] ?? '') : ''
            );
        }

        // Sync customer name from delivery address if needed
        $customer = $this->getCheckoutSession()->getCustomer();
        if ($customer && ($customer->isGuest() || empty($customer->firstname) || empty($customer->lastname))) {
            $address = new \Address($addressIds['id_address_delivery'], $this->context->language->id);
            if ($address->id && (!empty($address->firstname) || !empty($address->lastname))) {
                $customer->firstname = $address->firstname;
                $customer->lastname = $address->lastname;
                $customer->save();
                $this->context->updateCustomer($customer);
            }
        }

        return true;
    }

    private function hydrateOpcFromSubmittedAddresses(array $requestParameters): void
    {
        $deliveryAddressId = (int) ($requestParameters['id_address'] ?? 0);
        $invoiceAddressId = (int) ($requestParameters['id_address_invoice'] ?? 0);
        $customerId = (int) ($this->context->customer->id ?? 0);

        if (($deliveryAddressId <= 0 && $invoiceAddressId <= 0) || $customerId <= 0) {
            return;
        }

        $languageId = (int) $this->context->language->id;
        $deliveryAddress = $this->resolveSubmittedAddress($deliveryAddressId, $customerId, $languageId);
        $invoiceAddress = null;

        if ($invoiceAddressId > 0 && $invoiceAddressId !== $deliveryAddressId) {
            $invoiceAddress = $this->resolveSubmittedAddress($invoiceAddressId, $customerId, $languageId);
        }

        if ($deliveryAddress || $invoiceAddress) {
            $this->opcForm->fillFromAddresses($deliveryAddress, $invoiceAddress);
        }
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
            'opc_form' => $this->opcForm->getProxy(),
            'hookDisplayBeforeCarrier' => \Hook::exec('displayBeforeCarrier', ['cart' => $this->getCheckoutSession()->getCart()]),
            'hookDisplayAfterCarrier' => \Hook::exec('displayAfterCarrier', ['cart' => $this->getCheckoutSession()->getCart()]),
            'delivery_options' => $deliveryOptions,
            'delivery_option' => $deliveryOptionKey,
            'selected_delivery_option' => $selectedDeliveryOption,
            'payment_options' => $paymentOptions,
            'selected_payment_module' => $this->getSelectedPaymentModule(),
            'selected_payment_selection_key' => $this->getSelectedPaymentSelectionKey(),
            'conditions_to_approve' => $conditionsToApprove,
            'validation_errors' => $this->validationErrors,
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

    private function resolveSubmittedAddress(int $addressId, int $customerId, int $languageId): ?\Address
    {
        if ($addressId <= 0 || !\Customer::customerHasAddress($customerId, $addressId)) {
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
