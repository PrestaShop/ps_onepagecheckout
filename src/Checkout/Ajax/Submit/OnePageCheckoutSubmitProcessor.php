<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit;

use PrestaShop\Module\PsOnePageCheckout\Checkout\PaymentSelectionKeyBuilder;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutSubmitProcessor
{
    private \Context $context;
    private TranslatorInterface $translator;
    private OnePageCheckoutForm $opcForm;
    private \PaymentOptionsFinder $paymentOptionsFinder;
    private \ConditionsToApproveFinder $conditionsToApproveFinder;
    private PaymentSelectionKeyBuilder $paymentSelectionKeyBuilder;
    /**
     * @var array<string,mixed>
     */
    private array $validationErrors = [];

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        OnePageCheckoutForm $opcForm,
        \PaymentOptionsFinder $paymentOptionsFinder,
        \ConditionsToApproveFinder $conditionsToApproveFinder,
        ?PaymentSelectionKeyBuilder $paymentSelectionKeyBuilder = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->opcForm = $opcForm;
        $this->paymentOptionsFinder = $paymentOptionsFinder;
        $this->conditionsToApproveFinder = $conditionsToApproveFinder;
        $this->paymentSelectionKeyBuilder = $paymentSelectionKeyBuilder ?? new PaymentSelectionKeyBuilder();
    }

    /**
     * @param \CheckoutSession $checkoutSession
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function process(\CheckoutSession $checkoutSession, array $requestParameters): array
    {
        $this->validationErrors = [];
        $normalizedRequestParameters = $this->normalizeSubmittedRequestParameters($requestParameters);

        $this->hydrateOpcFromSubmittedAddresses($normalizedRequestParameters);

        $validationResult = $this->validateAllSections($checkoutSession, $normalizedRequestParameters);
        if (!$this->isAllSectionsValid($validationResult)) {
            return $this->buildFailureResult($normalizedRequestParameters);
        }

        if (!$this->saveAllSections($checkoutSession, $normalizedRequestParameters)) {
            return $this->buildFailureResult($normalizedRequestParameters);
        }

        return [
            'success' => true,
            'validation_errors' => [],
            'form_errors' => [],
            'submitted_values' => [],
        ];
    }

    /**
     * Browser form submits omit unchecked checkboxes, but OPC always needs an explicit
     * same/different-address flag during the final submit flow.
     *
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    private function normalizeSubmittedRequestParameters(array $requestParameters): array
    {
        if (!array_key_exists('use_same_address', $requestParameters)) {
            $requestParameters['use_same_address'] = $this->context->cart->isVirtualCart() ? '1' : '0';
        }

        return $requestParameters;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function hydrateOpcFromSubmittedAddresses(array $requestParameters): void
    {
        $deliveryAddressId = (int) ($requestParameters['id_address_delivery'] ?? 0);
        $invoiceAddressId = (int) ($requestParameters['id_address_invoice'] ?? 0);
        $customerId = (int) ($this->context->customer->id ?? 0);

        $this->fillOpcFormFromResolvedAddresses($deliveryAddressId, $invoiceAddressId, $customerId);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,bool>
     */
    private function validateAllSections(\CheckoutSession $checkoutSession, array $requestParameters): array
    {
        return [
            'identity' => $this->validateIdentitySection($requestParameters),
            'address' => $this->validateAddressSection($requestParameters),
            'shipping' => $this->validateShippingSection($checkoutSession, $requestParameters),
            'payment' => $this->validatePaymentSection($checkoutSession, $requestParameters),
            'conditions' => $this->validateConditionsSection($requestParameters),
        ];
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function validateIdentitySection(array $requestParameters): bool
    {
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

        if ($email !== '' && \Validate::isEmail($email)) {
            return true;
        }

        $this->validationErrors['identity'] = [
            'email' => ModuleTranslation::translate($this->translator, 'Invalid email format.'),
        ];

        return false;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function validateAddressSection(array $requestParameters): bool
    {
        $this->opcForm->fillWith($requestParameters);

        if ($this->opcForm->validate()) {
            return true;
        }

        $this->validationErrors['address'] = $this->opcForm->getErrors();

        return false;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function validateShippingSection(\CheckoutSession $checkoutSession, array $requestParameters): bool
    {
        if ($this->context->cart->isVirtualCart()) {
            return true;
        }

        $deliveryOptions = $checkoutSession->getDeliveryOptions();
        $deliveryOptionKey = isset($requestParameters['delivery_option']) && is_string($requestParameters['delivery_option'])
            ? $requestParameters['delivery_option']
            : (string) $checkoutSession->getSelectedDeliveryOption();

        if ($deliveryOptionKey === '' || empty($deliveryOptions[$deliveryOptionKey])) {
            $this->validationErrors['shipping'] = [
                'delivery_option' => ModuleTranslation::translate($this->translator, 'Please select a shipping method.'),
            ];

            return false;
        }

        $currentDeliveryOption = $deliveryOptions[$deliveryOptionKey];
        $isComplete = true;

        if (!empty($currentDeliveryOption['is_module']) && !empty($currentDeliveryOption['external_module_name'])) {
            \Hook::exec(
                'actionValidateStepComplete',
                [
                    'step_name' => 'delivery',
                    'request_params' => $requestParameters,
                    'completed' => &$isComplete,
                ],
                \Module::getModuleIdByName($currentDeliveryOption['external_module_name'])
            );
        }

        return $isComplete;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function validatePaymentSection(\CheckoutSession $checkoutSession, array $requestParameters): bool
    {
        $isFree = 0 == (float) $checkoutSession->getCart()->getOrderTotal(true, \Cart::BOTH);
        $paymentOptions = $this->paymentSelectionKeyBuilder->enrichPaymentOptions(
            (array) $this->paymentOptionsFinder->present($isFree)
        );
        $selectedPaymentModule = trim((string) ($requestParameters['paymentMethod'] ?? $this->getSelectedPaymentModule()));

        if ($isFree || empty($paymentOptions) || $selectedPaymentModule !== '') {
            return true;
        }

        $this->validationErrors['payment'] = [
            'paymentMethod' => ModuleTranslation::translate($this->translator, 'Please select a payment method.'),
        ];

        return false;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function validateConditionsSection(array $requestParameters): bool
    {
        $conditionsToApprove = (array) $this->conditionsToApproveFinder->getConditionsToApproveForTemplate();
        $submittedConditions = $requestParameters['conditions_to_approve'] ?? [];

        if (!is_array($submittedConditions)) {
            $submittedConditions = [];
        }

        foreach (array_keys($conditionsToApprove) as $conditionIdentifier) {
            if (!empty($submittedConditions[$conditionIdentifier])) {
                continue;
            }

            $this->validationErrors['conditions'] = [
                'conditions_to_approve' => ModuleTranslation::translate($this->translator, 'Please accept the terms of service.'),
            ];

            return false;
        }

        return true;
    }

    /**
     * @param array<string,bool> $validationResult
     */
    private function isAllSectionsValid(array $validationResult): bool
    {
        return $validationResult['identity']
            && $validationResult['address']
            && $validationResult['shipping']
            && $validationResult['payment']
            && $validationResult['conditions'];
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function saveAllSections(\CheckoutSession $checkoutSession, array $requestParameters): bool
    {
        $customer = $this->context->customer;
        $isGuestFlow = !$customer->isLogged() || $customer->isGuest();
        if ($isGuestFlow) {
            $hookResult = array_reduce(
                \Hook::exec('actionSubmitAccountBefore', [], null, true),
                static function ($carry, $item) {
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
            $this->validationErrors['address'] = $this->opcForm->getErrors();

            return false;
        }

        $checkoutSession->setIdAddressDelivery($addressIds['id_address_delivery']);
        $checkoutSession->setIdAddressInvoice($addressIds['id_address_invoice']);

        if (
            !$this->context->cart->isVirtualCart()
            && isset($requestParameters['delivery_option'])
            && is_string($requestParameters['delivery_option'])
            && $requestParameters['delivery_option'] !== ''
        ) {
            $checkoutSession->setDeliveryOption([
                (int) $addressIds['id_address_delivery'] => $requestParameters['delivery_option'],
            ]);
        }

        if (isset($requestParameters['delivery_message'])) {
            $checkoutSession->setMessage($requestParameters['delivery_message']);
        }

        if ((bool) \Configuration::get('PS_RECYCLABLE_PACK')) {
            $checkoutSession->setRecyclable($requestParameters['recyclable'] ?? false);
        }

        if ((bool) \Configuration::get('PS_GIFT_WRAPPING')) {
            $useGift = $requestParameters['gift'] ?? false;
            $checkoutSession->setGift(
                $useGift,
                $useGift ? ($requestParameters['gift_message'] ?? '') : ''
            );
        }

        $customer = $checkoutSession->getCustomer();
        if ($customer->isGuest() || empty($customer->firstname) || empty($customer->lastname)) {
            $address = new \Address($addressIds['id_address_delivery'], (int) $this->context->language->id);
            if ($address->id && (!empty($address->firstname) || !empty($address->lastname))) {
                $customer->firstname = $address->firstname;
                $customer->lastname = $address->lastname;
                $customer->save();
                $this->context->updateCustomer($customer);
            }
        }

        \Hook::exec('actionCarrierProcess', ['cart' => $checkoutSession->getCart()]);

        return true;
    }

    /**
     * @param array<string,mixed> $submittedValues
     *
     * @return array<string,mixed>
     */
    private function buildFailureResult(array $submittedValues): array
    {
        $persistedSubmittedValues = $this->filterPersistedSubmittedValues($submittedValues);

        return [
            'success' => false,
            'validation_errors' => $this->validationErrors,
            'form_errors' => $this->opcForm->getErrors(),
            'submitted_values' => $persistedSubmittedValues,
            'persisted_state' => $this->opcForm->buildPersistedSubmissionState(
                $persistedSubmittedValues,
                $this->validationErrors
            ),
        ];
    }

    /**
     * @param array<string,mixed> $submittedValues
     *
     * @return array<string,mixed>
     */
    private function filterPersistedSubmittedValues(array $submittedValues): array
    {
        unset(
            $submittedValues['ajax'],
            $submittedValues['action'],
            $submittedValues['static_token'],
            $submittedValues['token'],
            $submittedValues['paymentMethod']
        );

        $submittedValues = $this->filterPersistableAddressSelections($submittedValues);

        return array_filter(
            $submittedValues,
            static fn ($value): bool => is_scalar($value) || is_array($value) || $value === null
        );
    }

    /**
     * Keep only address ids that still resolve to a real address owned by the current checkout customer.
     * This avoids restoring a stale temp preview address as a selected saved address after a failed submit.
     *
     * @param array<string,mixed> $submittedValues
     *
     * @return array<string,mixed>
     */
    private function filterPersistableAddressSelections(array $submittedValues): array
    {
        foreach (['id_address_delivery', 'id_address_invoice'] as $fieldName) {
            $addressId = (int) ($submittedValues[$fieldName] ?? 0);

            if ($addressId <= 0 || !$this->isOwnedPersistableAddressId($addressId)) {
                unset($submittedValues[$fieldName]);
            }
        }

        return $submittedValues;
    }

    private function isOwnedPersistableAddressId(int $addressId): bool
    {
        $customerId = (int) ($this->context->customer->id ?? 0);
        if ($addressId <= 0 || $customerId <= 0 || !\Customer::customerHasAddress($customerId, $addressId)) {
            return false;
        }

        $address = new \Address($addressId, (int) ($this->context->language->id ?? 0));

        return \Validate::isLoadedObject($address) && (int) $address->deleted === 0;
    }

    private function getSelectedPaymentModule(): string
    {
        if (!isset($this->context->cookie)) {
            return '';
        }

        return (string) ($this->context->cookie->__get('opc_selected_payment_module') ?: '');
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
}
