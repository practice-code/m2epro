<?php

/*
 * @author     M2E Pro Developers Team
 * @copyright  M2E LTD
 * @license    Commercial use is forbidden
 */

namespace Ess\M2ePro\Controller\Adminhtml\Ebay\Listing;

class RunStopAndRemoveProducts extends \Ess\M2ePro\Controller\Adminhtml\Ebay\Listing\ActionAbstract
{
    public function execute()
    {
        if (!$listingsProductsIds = $this->getRequest()->getParam('selected_products')) {
            return $this->setRawContent('You should select Products');
        }

        /** @var \Ess\M2ePro\Model\ResourceModel\Listing\Product\Collection $productsCollection */
        $productsCollection = $this->ebayFactory->getObject('Listing_Product')->getCollection();
        $productsCollection->addFieldToFilter('id', explode(',', $listingsProductsIds));

        /** @var \Ess\M2ePro\Model\Listing\Product[] $listingsProducts */
        $listingsProducts = $productsCollection->getItems();
        $logsActionId = $this->activeRecordFactory->getObject('Listing\Log')->getResource()->getNextActionId();

        $this->checkLocking($listingsProducts, $logsActionId, \Ess\M2ePro\Model\Listing\Product::ACTION_STOP);
        if (empty($listingsProducts)) {
            $this->setJsonContent(['result' => 'error', 'action_id' => $logsActionId]);
            return $this->getResult();
        }

        foreach ($listingsProducts as $index => $listingProduct) {
            if (!$listingProduct->isStoppable()) {
                /** @var \Ess\M2ePro\Model\Listing\Product\RemoveHandler $removeHandler */
                $removeHandler = $this->modelFactory->getObject('Listing_Product_RemoveHandler');
                $removeHandler->setListingProduct($listingProduct);
                $removeHandler->process();

                unset($listingsProducts[$index]);
            }
        }

        if (empty($listingsProducts)) {
            $this->setJsonContent(['result' => 'success', 'action_id' => $logsActionId]);
            return $this->getResult();
        }

        if ($this->getHelper('Data')->jsonDecode($this->getRequest()->getParam('is_realtime'))) {
            return $this->runConnector(
                $listingsProducts,
                \Ess\M2ePro\Model\Listing\Product::ACTION_STOP,
                ['remove' => true],
                $logsActionId
            );
        }

        $this->createUpdateScheduledActions(
            $listingsProducts,
            \Ess\M2ePro\Model\Listing\Product::ACTION_STOP,
            ['remove' => true]
        );

        $this->setJsonContent(['result' => 'success', 'action_id' => $logsActionId]);
        return $this->getResult();
    }
}
