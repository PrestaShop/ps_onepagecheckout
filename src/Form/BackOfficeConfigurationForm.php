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
            if (!$this->isSingleShopContext()) {
                $this->redirectToConfigurationForm();

                return '';
            }

            $isEnabled = (int) \Tools::getValue($this->configurationKey, 0) === 1;
            $this->persistConfigurationValue((int) $isEnabled);
            $this->storeSuccessFlash();
            $this->redirectToConfigurationForm();

            return '';
        }

        if ($this->consumeSuccessFlash()) {
            $output .= $this->module->displayConfirmation(
                $this->trans('Settings updated.', 'Admin.Notifications.Success')
            );
        }

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
    }

    private function trans(string $message, string $domain = 'Modules.PsOnePageCheckout.Admin'): string
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

        $context->cookie->{self::SUCCESS_FLASH_COOKIE_KEY} = '1';
        $context->cookie->write();
    }

    protected function consumeSuccessFlash(): bool
    {
        $context = \Context::getContext();
        if (!isset($context->cookie) || !isset($context->cookie->{self::SUCCESS_FLASH_COOKIE_KEY})) {
            return false;
        }

        unset($context->cookie->{self::SUCCESS_FLASH_COOKIE_KEY});
        $context->cookie->write();

        return true;
    }
}
