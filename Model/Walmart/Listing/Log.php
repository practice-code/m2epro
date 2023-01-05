<?php

/**
 * @author     M2E Pro Developers Team
 * @copyright  M2E LTD
 * @license    Commercial use is forbidden
 */

namespace Ess\M2ePro\Model\Walmart\Listing;

use Ess\M2ePro\Helper\Component\Walmart;

class Log extends \Ess\M2ePro\Model\Listing\Log
{
    protected function _construct(): void
    {
        parent::_construct();
        $this->setComponentMode(Walmart::NICK);
    }

    // ----------------------------------------

    /**
     * @param $listingId
     * @param $productId
     * @param $listingProductId
     * @param int $initiator
     * @param null $actionId
     * @param null $action
     * @param null $description
     * @param null $type
     * @param array $additionalData
     * @throws \Ess\M2ePro\Model\Exception
     * @throws \Ess\M2ePro\Model\Exception\Logic
     */
    public function addProductMessage(
        $listingId,
        $productId,
        $listingProductId,
        $initiator = \Ess\M2ePro\Helper\Data::INITIATOR_UNKNOWN,
        $actionId = null,
        $action = null,
        $description = null,
        $type = null,
        array $additionalData = []
    ) {
        $dataForAdd = $this->makeDataForAdd(
            $listingId,
            $initiator,
            $productId,
            $listingProductId,
            $actionId,
            $action,
            $description,
            $type,
            $additionalData
        );

        if (!empty($listingProductId)) {

            /** @var \Ess\M2ePro\Model\Listing\Product $listingProduct */
            $listingProduct = $this->parentFactory
                ->getObjectLoaded(Walmart::NICK, 'Listing\Product', $listingProductId);

            /** @var \Ess\M2ePro\Model\Walmart\Listing\Product\Variation\Manager $variationManager */
            $variationManager = $listingProduct->getChildObject()->getVariationManager();

            if ($variationManager->isPhysicalUnit() &&
                $variationManager->getTypeModel()->isVariationProductMatched()
            ) {
                $productOptions = $variationManager->getTypeModel()->getProductOptions();

                if (!empty($productOptions)) {
                    $dataForAdd['additional_data'] = (array)$this->getHelper('Data')->jsonDecode(
                        $dataForAdd['additional_data']
                    );
                    $dataForAdd['additional_data']['variation_options'] = $productOptions;
                    $dataForAdd['additional_data'] = $this->getHelper('Data')->jsonEncode(
                        $dataForAdd['additional_data']
                    );
                }
            }

            if ($variationManager->isRelationChildType()) {
                $dataForAdd['parent_listing_product_id'] = $variationManager->getVariationParentId();
            }
        }

        $this->createMessage($dataForAdd);
    }
}
