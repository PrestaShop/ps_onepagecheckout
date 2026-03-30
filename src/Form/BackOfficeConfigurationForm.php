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

declare(strict_types=1);

namespace PrestaShop\Module\PsOnePageCheckout\Form;

use Twig\Environment;

class BackOfficeConfigurationForm
{
    private const FORM_SUBMIT_ACTION = 'submitPsOnePageCheckoutConfiguration';
    private const SUCCESS_FLASH_COOKIE_KEY = 'psopc_bo_configuration_saved';
    private const MAINTENANCE_FLASH_COOKIE_KEY = 'psopc_bo_maintenance_enabled';
    private const MAINTENANCE_INPUT_NAME = 'psopc_enable_maintenance';

    private \Module $module;
    private string $configurationKey;

    public function __construct(\Module $module, string $configurationKey)
    {
        $this->module = $module;
        $this->configurationKey = $configurationKey;
    }

    public function renderBackOfficeConfiguration(): string
    {
        $output = '';

        if (\Tools::isSubmit(self::FORM_SUBMIT_ACTION)) {
            $isEnabled = (int) \Tools::getValue($this->configurationKey, 0) === 1;
            $isMaintenanceEnabled = (int) \Tools::getValue(self::MAINTENANCE_INPUT_NAME, 0) === 1;

            if ($isMaintenanceEnabled && !$this->enableMaintenanceMode()) {
                return $this->module->displayError(
                    $this->trans('Unable to enable maintenance mode. Checkout layout was not changed.', 'Modules.Psonepagecheckout.Admin')
                ) . $this->renderConfigurationForm();
            }

            $this->persistConfigurationValue((int) $isEnabled);

            if ($isMaintenanceEnabled) {
                $this->storeMaintenanceFlash();
            } else {
                $this->storeSuccessFlash();
            }

            $this->redirectToConfigurationForm();

            return '';
        }

        $output .= $this->consumeFlashMessages();

        return $output . $this->renderConfigurationForm();
    }

    private function renderConfigurationForm(): string
    {
        $this->registerBackOfficeAssets();
        $templateVariables = $this->buildTemplateVariables();
        $twig = $this->resolveTwigEnvironment();

        if (!$twig instanceof Environment) {
            throw new \RuntimeException('Twig environment is required to render ps_onepagecheckout back office configuration.');
        }

        return $twig->render(
            '@Modules/ps_onepagecheckout/views/templates/admin/checkout_layout_configuration.html.twig',
            $templateVariables
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplateVariables(): array
    {
        $isEnabled = $this->getCurrentConfigurationValue() === 1;
        $onePageLabel = $this->trans('One-page checkout', 'Admin.Design.Feature');
        $fourPageLabel = $this->trans('Four-page checkout', 'Admin.Design.Feature');

        return [
            'configuration_key' => $this->configurationKey,
            'configuration_form_action' => $this->getConfigurationFormAction(),
            'form_submit_action' => self::FORM_SUBMIT_ACTION,
            'checkout_layout_css_url' => $this->module->getPathUri() . 'views/css/checkout_layout_choice.css',
            'is_single_shop_context' => $this->isSingleShopContext(),
            'single_shop_context_warning' => $this->trans(
                'Note that this page is available in a single shop context only. Switch context to work on it.',
                'Admin.Notifications.Info'
            ),
            'checkout_layout_title' => $this->trans('Checkout layout', 'Admin.Design.Feature'),
            'checkout_layout_description' => $this->trans(
                'Allow faster checkout for higher conversion and spontaneous purchases.',
                'Admin.Design.Feature'
            ),
            'save_button_label' => $this->trans('Save', 'Admin.Actions'),
            'confirm_modal_title' => $this->trans('Check theme compatibility', 'Modules.Psonepagecheckout.Admin'),
            'confirm_modal_description' => $this->trans('One-page checkout layout requires a Hummingbird-compatible theme.', 'Modules.Psonepagecheckout.Admin'),
            'confirm_modal_checklist_title' => $this->trans('Before you proceed, please make sure that:', 'Modules.Psonepagecheckout.Admin'),
            'confirm_modal_checklist' => [
                $this->trans('You are NOT using the "Classic" theme: This layout is incompatible with the legacy Classic theme and will break your storefront.', 'Modules.Psonepagecheckout.Admin'),
                $this->trans('Your theme is Hummingbird-based: Ensure your custom or third-party theme supports this new checkout architecture.', 'Modules.Psonepagecheckout.Admin'),
                $this->trans('Your checkout modules are compatible. Some older modules may not be compatible with a one-page layout.', 'Modules.Psonepagecheckout.Admin'),
            ],
            'cancel_button_label' => $this->trans('Cancel', 'Admin.Actions'),
            'confirm_button_label' => $this->trans('Change checkout appearance', 'Modules.Psonepagecheckout.Admin'),
            'maintenance_mode_input_name' => self::MAINTENANCE_INPUT_NAME,
            'maintenance_mode_label' => $this->trans('Maintenance mode', 'Modules.Psonepagecheckout.Admin'),
            'maintenance_mode_warning' => $this->trans(
                'We strongly recommend enabling <strong>Maintenance Mode</strong> or testing this in a <strong>Staging Environment</strong> first to ensure a smooth transition for your customers.',
                'Modules.Psonepagecheckout.Admin'
            ),
            'choices' => [
                [
                    'id' => $this->configurationKey . '_one_page',
                    'value' => 1,
                    'checked' => $isEnabled,
                    'label' => $onePageLabel,
                    'badge' => $this->trans('Recommended', 'Admin.Design.Feature'),
                    'description' => $this->trans(
                        'Propose the best checkout experience to your clients with the one-page layout. All the payment steps will be displayed in one page and let you benefit from:',
                        'Admin.Design.Feature'
                    ),
                    'features' => [
                        $this->trans('Reduced time spent, less clicks & no extra-step', 'Admin.Design.Feature'),
                        $this->trans(
                            'Increased conversion with a seamless and frictionless experience',
                            'Admin.Design.Feature'
                        ),
                        $this->trans('Mobile-friendly checkout', 'Admin.Design.Feature'),
                    ],
                    'illustration' => $this->module->getPathUri() . 'views/img/checkout/one-page-checkout.svg',
                ],
                [
                    'id' => $this->configurationKey . '_four_page',
                    'value' => 0,
                    'checked' => !$isEnabled,
                    'label' => $fourPageLabel,
                    'badge' => '',
                    'description' => $this->trans(
                        'Propose a step-by-step checkout experience. All the usual payment steps will be displayed on different screens. Choose this method if:',
                        'Admin.Design.Feature'
                    ),
                    'features' => [
                        $this->trans(
                            'You need flexibility for complex or specific information',
                            'Admin.Design.Feature'
                        ),
                        $this->trans('You\'re under regulatory compliance', 'Admin.Design.Feature'),
                        $this->trans('You want to integrate upsell & cross-sell strategies', 'Admin.Design.Feature'),
                    ],
                    'illustration' => $this->module->getPathUri() . 'views/img/checkout/four-page-checkout.svg',
                ],
            ],
        ];
    }

    private function getConfigurationFormAction(): string
    {
        $link = \Context::getContext()->link;
        if (null === $link) {
            return '';
        }

        if ($this->isAdminPsOnePageCheckoutContext()) {
            return $link->getAdminLink('AdminPsOnePageCheckout');
        }

        return $link->getAdminLink('AdminModules', true, [], ['configure' => $this->module->name]);
    }

    private function registerBackOfficeAssets(): void
    {
        $context = \Context::getContext();
        if (!isset($context->controller)) {
            return;
        }

        $context->controller->addCSS(
            $this->module->getPathUri() . 'views/css/checkout_layout_choice.css'
        );

        $context->controller->addJS(
            $this->module->getPathUri() . 'views/public/opc-checkout-layout.bundle.js'
        );
    }

    private function trans(string $message, string $domain = 'Modules.Psonepagecheckout.Admin'): string
    {
        $translator = \Context::getContext()->getTranslator();

        return $translator->trans($message, [], $domain);
    }

    private function isAdminPsOnePageCheckoutContext(): bool
    {
        $controllerName = (string) \Tools::getValue('controller');
        if ($controllerName === 'AdminPsOnePageCheckout') {
            return true;
        }

        $context = \Context::getContext();

        return isset($context->controller)
            && in_array(
                get_class($context->controller),
                ['AdminPsOnePageCheckoutController'],
                true
            );
    }

    private function resolveTwigEnvironment(): ?Environment
    {
        return $this->module->getTwig();
    }

    private function isSingleShopContext(): bool
    {
        return \Shop::getContext() === \Shop::CONTEXT_SHOP;
    }

    protected function persistConfigurationValue(int $value): void
    {
        $this->updateConfigurationValue($value);
    }

    protected function getCurrentConfigurationValue(): int
    {
        return $this->readConfigurationValue();
    }

    protected function updateConfigurationValue(int $value): void
    {
        \Configuration::updateValue($this->configurationKey, $value, false);
    }

    protected function readConfigurationValue(): int
    {
        return (int) \Configuration::get($this->configurationKey);
    }

    protected function redirectToConfigurationForm(): void
    {
        \Tools::redirectAdmin($this->getConfigurationFormAction());
    }

    protected function storeSuccessFlash(): void
    {
        $context = \Context::getContext();
        if (!isset($context->cookie)) {
            return;
        }

        $context->cookie->__set(self::SUCCESS_FLASH_COOKIE_KEY, '1');
        $context->cookie->write();
    }

    protected function consumeSuccessFlash(): bool
    {
        $context = \Context::getContext();
        if (!isset($context->cookie) || !$context->cookie->__isset(self::SUCCESS_FLASH_COOKIE_KEY)) {
            return false;
        }

        $context->cookie->__unset(self::SUCCESS_FLASH_COOKIE_KEY);
        $context->cookie->write();

        return true;
    }

    protected function storeMaintenanceFlash(): void
    {
        $context = \Context::getContext();
        if (!isset($context->cookie)) {
            return;
        }

        $context->cookie->__set(self::MAINTENANCE_FLASH_COOKIE_KEY, '1');
        $context->cookie->write();
    }

    protected function consumeMaintenanceFlash(): bool
    {
        $context = \Context::getContext();
        if (!isset($context->cookie) || !$context->cookie->__isset(self::MAINTENANCE_FLASH_COOKIE_KEY)) {
            return false;
        }

        $context->cookie->__unset(self::MAINTENANCE_FLASH_COOKIE_KEY);
        $context->cookie->write();

        return true;
    }

    private function consumeFlashMessages(): string
    {
        $output = '';

        if ($this->consumeMaintenanceFlash()) {
            $output .= $this->module->displayWarning(
                $this->trans('Checkout layout has been changed. Shop is now in maintenance mode.', 'Modules.Psonepagecheckout.Admin')
            );
        } elseif ($this->consumeSuccessFlash()) {
            $output .= $this->module->displayConfirmation(
                $this->trans('Checkout layout has been changed.', 'Modules.Psonepagecheckout.Admin')
            );
        }

        return $output;
    }

    protected function enableMaintenanceMode(): bool
    {
        return \Configuration::updateValue('PS_SHOP_ENABLE', 0, false);
    }
}
