<?php
/**
 * @deprecated since 1.0.0, use AdminPsOnePageCheckoutController instead.
 */

class AdminPsOnepagecheckoutController extends ModuleAdminController
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
}
