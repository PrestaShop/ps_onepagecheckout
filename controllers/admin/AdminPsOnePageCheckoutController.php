<?php

/**
 * Back office entry point for ps_onepagecheckout settings.
 */

use PrestaShop\PrestaShop\Core\Context\LegacyControllerContext;
use Twig\Environment;

class AdminPsOnePageCheckoutController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $configurationContent = $this->getBackOfficeConfigurationContent();
        if ($configurationContent !== '') {
            $this->content .= $configurationContent;
            $this->context->smarty->assign('content', $this->content);
        }
    }

    protected function getBackOfficeConfigurationContent(): string
    {
        if (!$this->module instanceof Ps_Onepagecheckout) {
            return '';
        }

        return $this->module->getBackOfficeConfigurationContent();
    }

    public function getTwig(): ?Environment
    {
        try {
            $legacyControllerContext = $this->getContainer()->get(LegacyControllerContext::class);
        } catch (Throwable) {
            return null;
        }

        if (!$legacyControllerContext instanceof LegacyControllerContext) {
            return null;
        }

        return $legacyControllerContext->getTwig();
    }
}
