<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutPaymentMethodsHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\PaymentSelectionKeyBuilder;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcPaymentMethodsHandlerIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables([
            'configuration',
            'module_country',
            'customer',
            'customer_group',
            'address',
        ]);
        \Configuration::loadConfiguration();
    }

    public function testItReturnsPaymentOptionsAndSelectedModuleForPaidCart(): void
    {
        $paymentOption = [
            'module_name' => 'ps_wirepayment',
            'call_to_action_text' => 'Wire payment',
            'action' => '/module/ps_wirepayment/validation',
        ];
        $selectionKey = (new PaymentSelectionKeyBuilder())->buildSelectionKey($paymentOption);

        $context = self::getContext();
        $context->cart = new class extends \Cart {
            public function __construct()
            {
                $this->id = 1;
            }

            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
            {
                return 42.0;
            }
        };
        $context->cookie->__set('opc_selected_payment_module', 'ps_wirepayment');
        $context->cookie->__set('opc_selected_payment_selection_key', $selectionKey);

        $finder = new class($paymentOption) extends \PaymentOptionsFinder {
            private array $paymentOption;

            public function __construct(array $paymentOption)
            {
                $this->paymentOption = $paymentOption;
            }

            public function present($free = false)
            {
                return $free ? [] : ['ps_wirepayment' => [0 => $this->paymentOption]];
            }
        };

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);
        $response = $handler->handle();

        self::assertTrue($response['success']);
        self::assertFalse($response['is_free']);
        self::assertSame('ps_wirepayment', $response['selected_payment_module']);
        self::assertSame($selectionKey, $response['selected_payment_selection_key']);
        self::assertSame('ps_wirepayment', $response['payment_options']['ps_wirepayment'][0]['module_name']);
        self::assertNotSame('', $response['payment_options']['ps_wirepayment'][0]['selection_key']);
    }

    public function testItFiltersPaymentOptionsByResolvedDeliveryCountry(): void
    {
        $context = self::getContext();
        $context->cart = new class extends \Cart {
            public function __construct()
            {
                $this->id = 1;
            }

            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
            {
                return 42.0;
            }
        };
        $context->country = new \Country((int) \Country::getByIso('FR'));

        $finder = new class extends \PaymentOptionsFinder {
            public function __construct()
            {
            }

            public function present($free = false)
            {
                return [
                    'ps_wirepayment' => [
                        0 => [
                            'module_name' => 'ps_wirepayment',
                            'call_to_action_text' => 'Wire payment',
                            'action' => '/module/ps_wirepayment/validation',
                        ],
                    ],
                    'ps_checkpayment' => [
                        0 => [
                            'module_name' => 'ps_checkpayment',
                            'call_to_action_text' => 'Check payment',
                            'action' => '/module/ps_checkpayment/validation',
                        ],
                    ],
                ];
            }
        };

        $this->configureModuleCountryRestriction('ps_wirepayment', ['FR']);
        $this->configureModuleCountryRestriction('ps_checkpayment', ['US']);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);

        $franceResponse = $handler->handle([
            'id_country' => (string) \Country::getByIso('FR'),
        ]);
        self::assertArrayHasKey('ps_wirepayment', $franceResponse['payment_options']);
        self::assertArrayNotHasKey('ps_checkpayment', $franceResponse['payment_options']);
        self::assertSame((int) \Country::getByIso('FR'), (int) $context->country->id);

        $usResponse = $handler->handle([
            'id_country' => (string) \Country::getByIso('US'),
        ]);
        self::assertArrayNotHasKey('ps_wirepayment', $usResponse['payment_options']);
        self::assertArrayHasKey('ps_checkpayment', $usResponse['payment_options']);
        self::assertSame((int) \Country::getByIso('FR'), (int) $context->country->id);
    }

    public function testItFiltersPaymentOptionsByResolvedInvoiceCountryWhenTaxesUseInvoiceAddress(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        $context = self::getContext();
        $context->cart = new class extends \Cart {
            public function __construct()
            {
                $this->id = 1;
                $this->id_address_delivery = 0;
                $this->id_address_invoice = 0;
            }

            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
            {
                return 42.0;
            }
        };
        $context->country = new \Country((int) \Country::getByIso('FR'));

        $finder = new class extends \PaymentOptionsFinder {
            public function __construct()
            {
            }

            public function present($free = false)
            {
                return [
                    'ps_wirepayment' => [
                        0 => [
                            'module_name' => 'ps_wirepayment',
                            'call_to_action_text' => 'Wire payment',
                            'action' => '/module/ps_wirepayment/validation',
                        ],
                    ],
                    'ps_checkpayment' => [
                        0 => [
                            'module_name' => 'ps_checkpayment',
                            'call_to_action_text' => 'Check payment',
                            'action' => '/module/ps_checkpayment/validation',
                        ],
                    ],
                ];
            }
        };

        $this->configureModuleCountryRestriction('ps_wirepayment', ['FR']);
        $this->configureModuleCountryRestriction('ps_checkpayment', ['US']);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);
        $response = $handler->handle([
            'id_country' => (string) \Country::getByIso('FR'),
            'invoice_id_country' => (string) \Country::getByIso('US'),
        ]);

        self::assertArrayNotHasKey('ps_wirepayment', $response['payment_options']);
        self::assertArrayHasKey('ps_checkpayment', $response['payment_options']);
        self::assertSame((int) \Country::getByIso('FR'), (int) $context->country->id);
    }

    public function testItFiltersPaymentOptionsByPersistedDeliveryAddressCountry(): void
    {
        $customer = $this->createCustomer('opc-payment-country');
        $usAddress = $this->createAddressForCustomer((int) $customer->id, 'US');

        $context = self::getContext();
        $context->cart = new class((int) $usAddress->id) extends \Cart {
            public function __construct(int $deliveryAddressId)
            {
                $this->id = 1;
                $this->id_address_delivery = $deliveryAddressId;
            }

            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
            {
                return 42.0;
            }
        };
        $context->country = new \Country((int) \Country::getByIso('FR'));

        $finder = new class extends \PaymentOptionsFinder {
            public function __construct()
            {
            }

            public function present($free = false)
            {
                return [
                    'ps_wirepayment' => [[
                        'module_name' => 'ps_wirepayment',
                        'call_to_action_text' => 'Wire payment',
                        'action' => '/module/ps_wirepayment/validation',
                    ]],
                    'ps_checkpayment' => [[
                        'module_name' => 'ps_checkpayment',
                        'call_to_action_text' => 'Check payment',
                        'action' => '/module/ps_checkpayment/validation',
                    ]],
                ];
            }
        };

        $this->configureModuleCountryRestriction('ps_wirepayment', ['FR']);
        $this->configureModuleCountryRestriction('ps_checkpayment', ['US']);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);
        $response = $handler->handle();

        self::assertArrayNotHasKey('ps_wirepayment', $response['payment_options']);
        self::assertArrayHasKey('ps_checkpayment', $response['payment_options']);
        self::assertSame((int) \Country::getByIso('FR'), (int) $context->country->id);
    }

    public function testItPrefersExplicitDeliveryAddressSelectionWhenTaxesUseDeliveryAddress(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');

        $customer = $this->createCustomer('opc-payment-explicit-delivery');
        $usAddress = $this->createAddressForCustomer((int) $customer->id, 'US');

        $context = self::getContext();
        $context->cart = new class extends \Cart {
            public function __construct()
            {
                $this->id = 1;
                $this->id_address_delivery = 0;
                $this->id_address_invoice = 0;
            }

            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
            {
                return 42.0;
            }
        };
        $context->country = new \Country((int) \Country::getByIso('FR'));

        $finder = new class extends \PaymentOptionsFinder {
            public function __construct()
            {
            }

            public function present($free = false)
            {
                return [
                    'ps_wirepayment' => [[
                        'module_name' => 'ps_wirepayment',
                        'call_to_action_text' => 'Wire payment',
                        'action' => '/module/ps_wirepayment/validation',
                    ]],
                    'ps_checkpayment' => [[
                        'module_name' => 'ps_checkpayment',
                        'call_to_action_text' => 'Check payment',
                        'action' => '/module/ps_checkpayment/validation',
                    ]],
                ];
            }
        };

        $this->configureModuleCountryRestriction('ps_wirepayment', ['FR']);
        $this->configureModuleCountryRestriction('ps_checkpayment', ['US']);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);
        $response = $handler->handle([
            'id_address_delivery' => (string) $usAddress->id,
            'invoice_id_country' => (string) \Country::getByIso('FR'),
        ]);

        self::assertArrayNotHasKey('ps_wirepayment', $response['payment_options']);
        self::assertArrayHasKey('ps_checkpayment', $response['payment_options']);
        self::assertSame((int) \Country::getByIso('FR'), (int) $context->country->id);
    }

    public function testItClearsPersistedPaymentSelectionWhenCountryFilteringMakesItInvalid(): void
    {
        $context = self::getContext();
        $context->cart = new class extends \Cart {
            public function __construct()
            {
                $this->id = 1;
            }

            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
            {
                return 42.0;
            }
        };
        $context->country = new \Country((int) \Country::getByIso('FR'));
        $context->cookie->__set('opc_selected_payment_option', 'payment-option-1');
        $context->cookie->__set('opc_selected_payment_module', 'ps_wirepayment');
        $context->cookie->__set('opc_selected_payment_selection_key', 'ps_wirepayment::selection');

        $finder = new class extends \PaymentOptionsFinder {
            public function __construct()
            {
            }

            public function present($free = false)
            {
                return [
                    'ps_wirepayment' => [
                        0 => [
                            'module_name' => 'ps_wirepayment',
                            'call_to_action_text' => 'Wire payment',
                            'action' => '/module/ps_wirepayment/validation',
                        ],
                    ],
                    'ps_checkpayment' => [
                        0 => [
                            'module_name' => 'ps_checkpayment',
                            'call_to_action_text' => 'Check payment',
                            'action' => '/module/ps_checkpayment/validation',
                        ],
                    ],
                ];
            }
        };

        $this->configureModuleCountryRestriction('ps_wirepayment', ['FR']);
        $this->configureModuleCountryRestriction('ps_checkpayment', ['US']);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);
        $response = $handler->handle([
            'id_country' => (string) \Country::getByIso('US'),
        ]);

        self::assertSame('', $response['selected_payment_module']);
        self::assertSame('', $response['selected_payment_selection_key']);
        self::assertSame('', (string) $context->cookie->__get('opc_selected_payment_option'));
        self::assertSame('', (string) $context->cookie->__get('opc_selected_payment_module'));
        self::assertSame('', (string) $context->cookie->__get('opc_selected_payment_selection_key'));
        self::assertArrayNotHasKey('ps_wirepayment', $response['payment_options']);
        self::assertArrayHasKey('ps_checkpayment', $response['payment_options']);
    }

    public function testItReturnsStructuredErrorWhenCartIsMissing(): void
    {
        $context = self::getContext();
        $context->cart = null;

        $handler = new OnePageCheckoutPaymentMethodsHandler($context);
        $response = $handler->handle();

        self::assertFalse($response['success']);
        self::assertArrayHasKey('errors', $response);
    }

    /**
     * @param string[] $countryIsoCodes
     */
    private function configureModuleCountryRestriction(string $moduleName, array $countryIsoCodes): void
    {
        $moduleId = (int) \Module::getModuleIdByName($moduleName);
        if ($moduleId <= 0) {
            self::assertTrue(\Db::getInstance()->insert('module', [
                'name' => pSQL($moduleName),
                'active' => 1,
            ]));
            $moduleId = (int) \Db::getInstance()->Insert_ID();
        }

        self::assertGreaterThan(0, $moduleId, sprintf('Unable to resolve module "%s".', $moduleName));

        $shopId = (int) self::getContext()->shop->id;
        \Db::getInstance()->delete('module_country', sprintf('id_module = %d AND id_shop = %d', $moduleId, $shopId));

        foreach ($countryIsoCodes as $isoCode) {
            $countryId = (int) \Country::getByIso($isoCode);
            self::assertGreaterThan(0, $countryId, sprintf('Unable to resolve country "%s".', $isoCode));

            self::assertTrue(\Db::getInstance()->insert('module_country', [
                'id_module' => $moduleId,
                'id_shop' => $shopId,
                'id_country' => $countryId,
            ]));
        }
    }

    private function createCustomer(string $emailPrefix): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Payment';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('%s_%s@example.com', $emailPrefix, uniqid('', true));
        $customer->is_guest = false;
        $customer->passwd = \Tools::hash('payment-test-password');

        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddressForCustomer(int $customerId, string $countryIso): \Address
    {
        $address = new \Address();
        $address->id_customer = $customerId;
        $address->id_country = (int) \Country::getByIso($countryIso);
        $address->firstname = 'Payment';
        $address->lastname = 'Customer';
        $address->address1 = '1 rue Payment';
        $address->alias = 'payment_' . uniqid('', true);
        $address->postcode = $countryIso === 'US' ? '10001' : '75001';
        $address->city = $countryIso === 'US' ? 'New York' : 'Paris';

        self::assertTrue($address->save());

        return $address;
    }
}
