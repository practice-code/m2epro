<?php

/**
 * @author     M2E Pro Developers Team
 * @copyright  M2E LTD
 * @license    Commercial use is forbidden
 */

namespace Ess\M2ePro\Controller\Adminhtml\Walmart\Settings;

class Index extends \Ess\M2ePro\Controller\Adminhtml\Walmart\Settings
{
    protected function getLayoutType()
    {
        return self::LAYOUT_TWO_COLUMNS;
    }

    public function execute()
    {
        $activeTab = $this->getRequest()->getParam('active_tab', null);

        if ($activeTab === null) {
            $activeTab = \Ess\M2ePro\Block\Adminhtml\Walmart\Settings\Tabs::TAB_ID_MAIN;
        }

        /** @var \Ess\M2ePro\Block\Adminhtml\Walmart\Settings\Tabs $tabsBlock */
        $tabsBlock = $this->getLayout()
                          ->createBlock(
                              \Ess\M2ePro\Block\Adminhtml\Walmart\Settings\Tabs::class,
                              '',
                              [
                                  'data' => [
                                      'active_tab' => $activeTab,
                                  ],
                              ]
                          );

        if ($this->isAjax()) {
            $this->setAjaxContent(
                $tabsBlock->getTabContent($tabsBlock->getActiveTabById($activeTab))
            );

            return $this->getResult();
        }

        $this->addLeft($tabsBlock);
        $this->addContent($this->getLayout()->createBlock(\Ess\M2ePro\Block\Adminhtml\Walmart\Settings::class));

        $this->setPageHelpLink('x/jf1IB');

        $this->getResult()->getConfig()->getTitle()->prepend($this->__('Settings'));

        return $this->getResult();
    }
}
