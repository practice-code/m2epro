<?php

namespace Ess\M2ePro\Model\Magento;

use Ess\M2ePro\Model\Magento\Product\Image;
use Ess\M2ePro\Model\Magento\Product\Inventory\Factory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;

class Product extends \Ess\M2ePro\Model\AbstractModel
{
    public const TYPE_SIMPLE_ORIGIN = 'simple';
    public const TYPE_CONFIGURABLE_ORIGIN = 'configurable';
    public const TYPE_BUNDLE_ORIGIN = 'bundle';
    public const TYPE_GROUPED_ORIGIN = 'grouped';
    public const TYPE_DOWNLOADABLE_ORIGIN = 'downloadable';
    public const TYPE_VIRTUAL_ORIGIN = 'virtual';

    public const BUNDLE_PRICE_TYPE_DYNAMIC = 0;
    public const BUNDLE_PRICE_TYPE_FIXED = 1;

    public const THUMBNAIL_IMAGE_CACHE_TIME = 604800;

    public const TAX_CLASS_ID_NONE = 0;

    public const FORCING_QTY_TYPE_MANAGE_STOCK_NO = 1;
    public const FORCING_QTY_TYPE_BACKORDERS = 2;

    /**
     *  $statistics = array(
     *      'id' => array(
     *         'store_id' => array(
     *              'product_id' => array(
     *                  'qty' => array(
     *                      '1' => $qty,
     *                      '2' => $qty,
     *                  ),
     *              ),
     *              ...
     *          ),
     *          ...
     *      ),
     *      ...
     *  )
     */

    /** @var array */
    public static $statistics = [];

    protected $inventoryFactory;
    protected $driverPool;
    protected $resourceModel;
    protected $productFactory;
    protected $websiteFactory;
    protected $productType;
    protected $configurableFactory;
    protected $productStatus;
    protected $catalogInventoryConfiguration;
    protected $storeFactory;
    protected $filesystem;
    protected $objectManager;
    protected $activeRecordFactory;
    protected $magentoProductCollectionFactory;

    protected $statisticId;

    protected $_productId = 0;

    protected $_storeId = \Magento\Store\Model\Store::DEFAULT_STORE_ID;

    /** @var \Magento\Catalog\Model\Product */
    protected $_productModel = null;

    /** @var \Magento\Catalog\Model\ResourceModel\Product */
    protected $resourceProduct;

    /** @var \Ess\M2ePro\Helper\Data */
    protected $helperData;

    /** @var \Ess\M2ePro\Model\Magento\Product\Variation */
    protected $_variationInstance = null;

    // applied only for standard variations type
    protected $variationVirtualAttributes = [];

    protected $isIgnoreVariationVirtualAttributes = false;

    // applied only for standard variations type
    protected $variationFilterAttributes = [];

    protected $isIgnoreVariationFilterAttributes = false;

    public $notFoundAttributes = [];

    protected $isGroupedProductMode = \Ess\M2ePro\Model\Listing\Product::GROUPED_PRODUCT_MODE_OPTIONS;

    /** @var \Ess\M2ePro\Helper\Module\Configuration */
    private $moduleConfiguration;

    /** @var \Ess\M2ePro\Model\Magento\ProductFactory */
    private $m2eProductFactory;

    /** @var \Magento\Catalog\Api\ProductRepositoryInterface */
    private $productRepository;
    /** @var \Ess\M2ePro\Model\Magento\Product\ImageFactory */
    private $imageFactory;

    public function __construct(
        \Ess\M2ePro\Model\Magento\Product\ImageFactory $imageFactory,
        Factory $inventoryFactory,
        \Magento\Framework\Filesystem\DriverPool $driverPool,
        \Magento\Framework\App\ResourceConnection $resourceModel,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Store\Model\WebsiteFactory $websiteFactory,
        \Magento\Catalog\Model\Product\Type $productType,
        \Ess\M2ePro\Model\Magento\Product\Type\ConfigurableFactory $configurableFactory,
        \Ess\M2ePro\Model\Magento\Product\Status $productStatus,
        \Magento\CatalogInventory\Model\Configuration $catalogInventoryConfiguration,
        \Magento\Store\Model\StoreFactory $storeFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Ess\M2ePro\Model\ActiveRecord\Factory $activeRecordFactory,
        \Ess\M2ePro\Model\ResourceModel\Magento\Product\CollectionFactory $magentoProductCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Product $resourceProduct,
        \Ess\M2ePro\Helper\Data $helperData,
        \Ess\M2ePro\Helper\Module\Configuration $moduleConfiguration,
        \Ess\M2ePro\Model\Factory $modelFactory,
        \Ess\M2ePro\Helper\Factory $helperFactory,
        \Ess\M2ePro\Model\Magento\ProductFactory $m2eProductFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
    ) {
        $this->inventoryFactory = $inventoryFactory;
        $this->driverPool = $driverPool;
        $this->resourceModel = $resourceModel;
        $this->productFactory = $productFactory;
        $this->websiteFactory = $websiteFactory;
        $this->productType = $productType;
        $this->configurableFactory = $configurableFactory;
        $this->productStatus = $productStatus;
        $this->catalogInventoryConfiguration = $catalogInventoryConfiguration;
        $this->storeFactory = $storeFactory;
        $this->filesystem = $filesystem;
        $this->objectManager = $objectManager;
        $this->activeRecordFactory = $activeRecordFactory;
        $this->magentoProductCollectionFactory = $magentoProductCollectionFactory;
        $this->resourceProduct = $resourceProduct;
        $this->helperData = $helperData;
        $this->moduleConfiguration = $moduleConfiguration;
        $this->m2eProductFactory = $m2eProductFactory;
        $this->productRepository = $productRepository;
        $this->imageFactory = $imageFactory;

        parent::__construct($helperFactory, $modelFactory);
    }

    //########################################

    /**
     * @return bool
     */
    public function exists()
    {
        if ($this->_productId === null) {
            return false;
        }

        $table = $this->getHelper('Module_Database_Structure')->getTableNameWithPrefix('catalog_product_entity');
        $dbSelect = $this->resourceModel->getConnection()
                                        ->select()
                                        ->from($table, new \Zend_Db_Expr('COUNT(*)'))
                                        ->where('`entity_id` = ?', (int)$this->_productId);

        $count = $this->resourceModel->getConnection()->fetchOne($dbSelect);

        return $count == 1;
    }

    /**
     * @param int|null $productId
     * @param int|null $storeId
     *
     * @return \Ess\M2ePro\Model\Magento\Product | \Ess\M2ePro\Model\Magento\Product\Cache
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function loadProduct($productId = null, $storeId = null)
    {
        $productId = ($productId === null) ? $this->_productId : $productId;
        $storeId = ($storeId === null) ? $this->_storeId : $storeId;

        if ($productId <= 0) {
            throw new \Ess\M2ePro\Model\Exception('The Product ID is not set.');
        }

        $this->_productModel = $this->productFactory->create()->setStoreId($storeId);
        $this->_productModel->load($productId, 'entity_id');

        if ($this->_productModel->getId() === null) {
            throw new \Ess\M2ePro\Model\Exception\ProductNotExist(
                sprintf('Magento Product with id %s does not exist.', $productId)
            );
        }

        $this->setProductId($productId);
        $this->setStoreId($storeId);

        return $this;
    }

    //########################################

    /**
     * @param int $productId
     *
     * @return \Ess\M2ePro\Model\Magento\Product
     */
    public function setProductId($productId)
    {
        $this->_productId = $productId;

        return $this;
    }

    /**
     * @return int
     */
    public function getProductId()
    {
        return $this->_productId;
    }

    // ---------------------------------------

    /**
     * @param int $storeId
     *
     * @return \Ess\M2ePro\Model\Magento\Product
     */
    public function setStoreId($storeId)
    {
        $this->_storeId = $storeId;

        return $this;
    }

    /**
     * @return int
     */
    public function getStoreId()
    {
        return $this->_storeId;
    }

    //########################################

    /**
     * @return array
     */
    public function getStoreIds()
    {
        $storeIds = [];
        foreach ($this->getWebsiteIds() as $websiteId) {
            try {
                $websiteStores = $this->websiteFactory->create()->load($websiteId)->getStoreIds();
                $storeIds = array_merge($storeIds, $websiteStores);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $storeIds;
    }

    /**
     * @return array
     */
    public function getWebsiteIds()
    {
        $select = $this->resourceModel->getConnection()
                                      ->select()
                                      ->from(
                                          $this->getHelper('Module_Database_Structure')->getTableNameWithPrefix(
                                              'catalog_product_website'
                                          ),
                                          'website_id'
                                      )
                                      ->where('product_id = ?', (int)$this->getProductId());

        $websiteIds = $this->resourceModel->getConnection()->fetchCol($select);

        return $websiteIds ? $websiteIds : [];
    }

    //########################################

    /**
     * @return \Magento\Catalog\Model\Product
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function getProduct()
    {
        if ($this->_productModel) {
            return $this->_productModel;
        }

        if ($this->_productId > 0) {
            $this->loadProduct();

            return $this->_productModel;
        }

        throw new \Ess\M2ePro\Model\Exception('Load instance first');
    }

    /**
     * @param \Magento\Catalog\Model\Product $productModel
     *
     * @return \Ess\M2ePro\Model\Magento\Product
     */
    public function setProduct(\Magento\Catalog\Model\Product $productModel)
    {
        $this->_productModel = $productModel;

        $this->setProductId($this->_productModel->getId());
        $this->setStoreId($this->_productModel->getStoreId());

        return $this;
    }

    // ---------------------------------------

    /**
     * @param int $isGroupedProductMode
     *
     * @return \Ess\M2ePro\Model\Magento\Product
     */
    public function setGroupedProductMode($isGroupedProductMode)
    {
        $this->isGroupedProductMode = $isGroupedProductMode;

        return $this;
    }

    /**
     * @return \Magento\Catalog\Model\Product\Type\AbstractType
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function getTypeInstance()
    {
        if ($this->_productModel === null && $this->_productId < 0) {
            throw new \Ess\M2ePro\Model\Exception('Load instance first');
        }

        /** @var \Magento\Catalog\Model\Product\Type\AbstractType $typeInstance */
        if ($this->isConfigurableType() && !$this->getProduct()->getData('overridden_type_instance_injected')) {
            $config = $this->productType->getTypes();

            $typeInstance = $this->configurableFactory->create();
            $typeInstance->setConfig($config['configurable']);

            $this->getProduct()->setTypeInstance($typeInstance);
            $this->getProduct()->setData('overridden_type_instance_injected', true);
        } else {
            $typeInstance = $this->getProduct()->getTypeInstance();
        }

        $typeInstance->setStoreFilter($this->getStoreId(), $this->getProduct());

        return $typeInstance;
    }

    /**
     * @return \Magento\CatalogInventory\Api\Data\StockItemInterface
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function getStockItem()
    {
        if ($this->_productModel === null && $this->_productId < 0) {
            throw new \Ess\M2ePro\Model\Exception('Load instance first');
        }

        return $this->inventoryFactory->getObject($this->getProduct())->getStockItem();
    }

    //########################################

    /**
     * @return array
     */
    public function getVariationVirtualAttributes()
    {
        return $this->variationVirtualAttributes;
    }

    /**
     * @param array $attributes
     *
     * @return $this
     */
    public function setVariationVirtualAttributes(array $attributes)
    {
        $this->variationVirtualAttributes = $attributes;

        return $this;
    }

    /**
     * @return bool
     */
    public function isIgnoreVariationVirtualAttributes()
    {
        return $this->isIgnoreVariationVirtualAttributes;
    }

    /**
     * @param bool $isIgnore
     *
     * @return $this
     */
    public function setIgnoreVariationVirtualAttributes($isIgnore = true)
    {
        $this->isIgnoreVariationVirtualAttributes = $isIgnore;

        return $this;
    }

    // ---------------------------------------

    /**
     * @return array
     */
    public function getVariationFilterAttributes()
    {
        return $this->variationFilterAttributes;
    }

    /**
     * @param array $attributes
     *
     * @return $this
     */
    public function setVariationFilterAttributes(array $attributes)
    {
        $this->variationFilterAttributes = $attributes;

        return $this;
    }

    /**
     * @return bool
     */
    public function isIgnoreVariationFilterAttributes()
    {
        return $this->isIgnoreVariationFilterAttributes;
    }

    /**
     * @param bool $isIgnore
     *
     * @return $this
     */
    public function setIgnoreVariationFilterAttributes($isIgnore = true)
    {
        $this->isIgnoreVariationFilterAttributes = $isIgnore;

        return $this;
    }

    //########################################

    private function getTypeIdByProductId($productId)
    {
        $tempKey = 'product_id_' . (int)$productId . '_type';

        $typeId = $this->helperFactory->getObject('Data\GlobalData')->getValue($tempKey);

        if ($typeId !== null) {
            return $typeId;
        }

        $resource = $this->resourceModel;

        $typeId = $resource->getConnection()
                           ->select()
                           ->from(
                               $this->getHelper('Module_Database_Structure')->getTableNameWithPrefix(
                                   'catalog_product_entity'
                               ),
                               ['type_id']
                           )
                           ->where('`entity_id` = ?', (int)$productId)
                           ->query()
                           ->fetchColumn();

        $this->helperFactory->getObject('Data\GlobalData')->setValue($tempKey, $typeId);

        return $typeId;
    }

    public function getNameByProductId($productId, $storeId = \Magento\Store\Model\Store::DEFAULT_STORE_ID)
    {
        $nameCacheKey = 'product_id_' . (int)$productId . '_' . (int)$storeId . '_name';

        $name = $this->helperFactory->getObject('Data\GlobalData')->getValue($nameCacheKey);

        if ($name !== null) {
            return $name;
        }

        $resource = $this->resourceModel;

        $cacheHelper = $this->helperFactory->getObject('Data_Cache_Permanent');

        if (($attributeId = $cacheHelper->getValue(__METHOD__)) === null) {
            $attributeId = $resource->getConnection()
                                    ->select()
                                    ->from(
                                        $this->getHelper('Module_Database_Structure')->getTableNameWithPrefix(
                                            'eav_attribute'
                                        ),
                                        ['attribute_id']
                                    )
                                    ->where('attribute_code = ?', 'name')
                                    ->where(
                                        'entity_type_id = ?',
                                        $this->productFactory
                                            ->create()->getResource()->getTypeId()
                                    )
                                    ->query()
                                    ->fetchColumn();

            $cacheHelper->setValue(__METHOD__, $attributeId);
        }

        $storeIds = [(int)$storeId, \Magento\Store\Model\Store::DEFAULT_STORE_ID];
        $storeIds = array_unique($storeIds);

        /** @var \Ess\M2ePro\Model\ResourceModel\Magento\Product\Collection $collection */
        $collection = $this->magentoProductCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', (int)$productId);
        $collection->joinTable(
            [
                'cpev' => $this->getHelper('Module_Database_Structure')
                               ->getTableNameWithPrefix('catalog_product_entity_varchar'),
            ],
            'entity_id = entity_id',
            ['value' => 'value']
        );
        $queryStmt = $collection->getSelect()
                                ->reset(\Magento\Framework\DB\Select::COLUMNS)
                                ->columns(['value' => 'cpev.value'])
                                ->where('cpev.store_id IN (?)', $storeIds)
                                ->where('cpev.attribute_id = ?', (int)$attributeId)
                                ->order('cpev.store_id DESC')
                                ->query();

        $nameValue = '';
        while ($tempValue = $queryStmt->fetchColumn()) {
            if (!empty($tempValue)) {
                $nameValue = $tempValue;
                break;
            }
        }

        $this->helperFactory->getObject('Data\GlobalData')->setValue($nameCacheKey, (string)$nameValue);

        return (string)$nameValue;
    }

    private function getSkuByProductId($productId)
    {
        $tempKey = 'product_id_' . (int)$productId . '_name';

        $sku = $this->helperFactory->getObject('Data\GlobalData')->getValue($tempKey);

        if ($sku !== null) {
            return $sku;
        }

        $resource = $this->resourceModel;

        $sku = $resource->getConnection()
                        ->select()
                        ->from(
                            $this->getHelper('Module_Database_Structure')->getTableNameWithPrefix(
                                'catalog_product_entity'
                            ),
                            ['sku']
                        )
                        ->where('`entity_id` = ?', (int)$productId)
                        ->query()
                        ->fetchColumn();

        $this->helperFactory->getObject('Data\GlobalData')->setValue($tempKey, $sku);

        return $sku;
    }

    //########################################

    public function getTypeId()
    {
        $typeId = null;
        if (!$this->_productModel && $this->_productId > 0) {
            $typeId = $this->getTypeIdByProductId($this->_productId);
        } else {
            $typeId = $this->getProduct()->getTypeId();
        }

        return $typeId;
    }

    // ---------------------------------------

    /**
     * @return bool
     */
    public function isSimpleType()
    {
        return $this->getHelper('Magento\Product')->isSimpleType($this->getTypeId());
    }

    /**
     * @return bool
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function isSimpleTypeWithCustomOptions()
    {
        if (!$this->isSimpleType()) {
            return false;
        }

        foreach ($this->getProduct()->getOptions() as $option) {
            if (
                (int)$option->getData('is_require') &&
                in_array($option->getData('type'), ['drop_down', 'radio', 'multiple', 'checkbox'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isSimpleTypeWithoutCustomOptions()
    {
        if (!$this->isSimpleType()) {
            return false;
        }

        return !$this->isSimpleTypeWithCustomOptions();
    }

    // ---------------------------------------

    /**
     * @return bool
     */
    public function isDownloadableType()
    {
        return $this->getHelper('Magento\Product')->isDownloadableType($this->getTypeId());
    }

    /**
     * @return bool
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function isDownloadableTypeWithSeparatedLinks()
    {
        if (!$this->isDownloadableType()) {
            return false;
        }

        return (bool)$this->getProduct()->getData('links_purchased_separately');
    }

    /**
     * @return bool
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function isDownloadableTypeWithoutSeparatedLinks()
    {
        if (!$this->isDownloadableType()) {
            return false;
        }

        return !$this->isDownloadableTypeWithSeparatedLinks();
    }

    // ---------------------------------------

    /**
     * @return bool
     */
    public function isConfigurableType()
    {
        return $this->getHelper('Magento\Product')->isConfigurableType($this->getTypeId());
    }

    /**
     * @return bool
     */
    public function isBundleType()
    {
        return $this->getHelper('Magento\Product')->isBundleType($this->getTypeId());
    }

    /**
     * @return bool
     */
    public function isGroupedType()
    {
        return $this->getHelper('Magento\Product')->isGroupedType($this->getTypeId());
    }

    // ---------------------------------------

    /**
     * @return bool
     */
    public function isSimpleTypeOrigin()
    {
        return $this->getTypeId() == self::TYPE_SIMPLE_ORIGIN;
    }

    /**
     * @return bool
     */
    public function isConfigurableTypeOrigin()
    {
        return $this->getTypeId() == self::TYPE_CONFIGURABLE_ORIGIN;
    }

    /**
     * @return bool
     */
    public function isBundleTypeOrigin()
    {
        return $this->getTypeId() == self::TYPE_BUNDLE_ORIGIN;
    }

    /**
     * @return bool
     */
    public function isGroupedTypeOrigin()
    {
        return $this->getTypeId() == self::TYPE_GROUPED_ORIGIN;
    }

    /**
     * @return bool
     */
    public function isDownloadableTypeOrigin()
    {
        return $this->getTypeId() == self::TYPE_DOWNLOADABLE_ORIGIN;
    }

    /**
     * @return bool
     */
    public function isVirtualTypeOrigin()
    {
        return $this->getTypeId() == self::TYPE_VIRTUAL_ORIGIN;
    }

    //########################################

    /**
     * @return int
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function getBundlePriceType()
    {
        return (int)$this->getProduct()->getPriceType();
    }

    // ---------------------------------------

    /**
     * @return bool
     */
    public function isBundlePriceTypeDynamic()
    {
        return $this->getBundlePriceType() == self::BUNDLE_PRICE_TYPE_DYNAMIC;
    }

    /**
     * @return bool
     */
    public function isBundlePriceTypeFixed()
    {
        return $this->getBundlePriceType() == self::BUNDLE_PRICE_TYPE_FIXED;
    }

    //########################################

    /**
     * @return bool
     */
    public function isProductWithVariations()
    {
        return !$this->isProductWithoutVariations();
    }

    /**
     * @return bool
     */
    public function isProductWithoutVariations()
    {
        return $this->isSimpleTypeWithoutCustomOptions() || $this->isDownloadableTypeWithoutSeparatedLinks();
    }

    /**
     * @return bool
     */
    public function isStrictVariationProduct()
    {
        return $this->isConfigurableType() || $this->isBundleType() || $this->isGroupedType();
    }

    //########################################

    public function getSku()
    {
        if (!$this->_productModel && $this->_productId > 0) {
            $temp = $this->getSkuByProductId($this->_productId);
            if ($temp !== null && $temp != '') {
                return $temp;
            }
        }

        return $this->getProduct()->getSku();
    }

    public function getName()
    {
        if (!$this->_productModel && $this->_productId > 0) {
            return $this->getNameByProductId($this->_productId, $this->_storeId);
        }

        return $this->getProduct()->getName();
    }

    // ---------------------------------------

    /**
     * @return bool
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function isStatusEnabled()
    {
        if (\Ess\M2ePro\Model\Listing\Product::GROUPED_PRODUCT_MODE_SET == $this->isGroupedProductMode) {
            foreach ($this->getTypeInstance()->getAssociatedProducts($this->getProduct()) as $childProduct) {
                if ($childProduct->getStatus() == Status::STATUS_ENABLED) {
                    continue;
                }

                return false;
            }
        }

        if (!$this->_productModel && $this->_productId > 0) {
            $status = $this->productStatus->getProductStatus($this->_productId, $this->_storeId);

            if (is_array($status) && isset($status[$this->_productId])) {
                $status = (int)$status[$this->_productId];
                if ($status == Status::STATUS_DISABLED || $status == Status::STATUS_ENABLED) {
                    return $status == Status::STATUS_ENABLED;
                }
            }
        }

        return (int)$this->getProduct()->getStatus() == Status::STATUS_ENABLED;
    }

    /**
     * @return bool
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function isStockAvailability()
    {
        if (\Ess\M2ePro\Model\Listing\Product::GROUPED_PRODUCT_MODE_SET == $this->isGroupedProductMode) {
            foreach ($this->getTypeInstance()->getAssociatedProducts($this->getProduct()) as $childProduct) {
                if ($this->inventoryFactory->getObject($childProduct)->isStockAvailability()) {
                    continue;
                }

                return false;
            }
        }

        return $this->inventoryFactory->getObject($this->getProduct())->isStockAvailability();
    }

    //########################################

    public function getPrice()
    {
        // for bundle with dynamic price and grouped always returns 0
        // for configurable product always returns 0
        return (float)$this->getProduct()->getPrice();
    }

    public function setPrice($value)
    {
        // there is no any sense to set price for bundle
        // with dynamic price or grouped
        return $this->getProduct()->setPrice($value);
    }

    // ---------------------------------------

    public function getSpecialPrice()
    {
        if (!$this->isSpecialPriceActual()) {
            return null;
        }

        // for grouped always returns 0
        $specialPriceValue = (float)$this->getProduct()->getSpecialPrice();

        if ($this->isBundleType()) {
            if ($this->isBundlePriceTypeDynamic()) {
                // there is no reason to calculate it
                // because product price is not defined at all
                $specialPriceValue = 0;
            } else {
                $specialPriceValue = round((($this->getPrice() * $specialPriceValue) / 100), 2);
            }
        }

        return (float)$specialPriceValue;
    }

    public function setSpecialPrice($value)
    {
        // there is no any sense to set price for grouped
        // it sets percent instead of price value for bundle
        return $this->getProduct()->setSpecialPrice($value);
    }

    // ---------------------------------------

    /**
     * @return bool
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function isSpecialPriceActual()
    {
        $fromDate = (int)\Ess\M2ePro\Helper\Date::createDateGmt($this->getSpecialPriceFromDate())
                                                ->format('U');
        $toDate = (int)\Ess\M2ePro\Helper\Date::createDateGmt($this->getSpecialPriceToDate())
                                              ->format('U');
        $currentTimeStamp = \Ess\M2ePro\Helper\Date::createCurrentGmt()->getTimestamp();

        return $currentTimeStamp >= $fromDate && $currentTimeStamp < $toDate &&
            (float)$this->getProduct()->getSpecialPrice() > 0;
    }

    // ---------------------------------------

    public function getSpecialPriceFromDate()
    {
        $fromDate = $this->getProduct()->getSpecialFromDate();

        if ($fromDate === null || $fromDate === false || $fromDate == '') {
            $fromDate = \Ess\M2ePro\Helper\Date::createCurrentGmt()
                                               ->format('Y-01-01 00:00:00');
        } else {
            $fromDate = \Ess\M2ePro\Helper\Date::createDateGmt($fromDate)
                                               ->format('Y-m-d 00:00:00');
        }

        return $fromDate;
    }

    public function getSpecialPriceToDate()
    {
        $toDate = $this->getProduct()->getSpecialToDate();

        if ($toDate === null || $toDate === false || $toDate == '') {
            $toDate = \Ess\M2ePro\Helper\Date::createCurrentGmt();
            $toDate->modify('+1 year');
            $toDate = $toDate->format('Y-01-01 00:00:00');
        } else {
            $toDate = \Ess\M2ePro\Helper\Date::createDateGmt($toDate)
                                             ->format('Y-m-d 00:00:00');

            $toDate = \Ess\M2ePro\Helper\Date::createDateGmt($toDate);
            $toDate->modify('+1 day');
            $toDate = $toDate->format('Y-m-d 00:00:00');
        }

        return $toDate;
    }

    // ---------------------------------------

    /**
     * @param null $websiteId
     * @param null $customerGroupId
     *
     * @return array
     */
    public function getTierPrice($websiteId = null, $customerGroupId = null)
    {
        $attribute = $this->getProduct()->getResource()->getAttribute('tier_price');
        $attribute->getBackend()->afterLoad($this->getProduct());

        $prices = $this->getProduct()->getData('tier_price');
        if (empty($prices)) {
            return [];
        }

        $resultPrices = [];

        foreach ($prices as $priceValue) {
            if ($websiteId !== null && !empty($priceValue['website_id']) && $websiteId != $priceValue['website_id']) {
                continue;
            }

            if (
                $customerGroupId !== null &&
                $priceValue['cust_group'] != \Magento\Customer\Model\Group::CUST_GROUP_ALL &&
                $customerGroupId != $priceValue['cust_group']
            ) {
                continue;
            }

            $resultPrices[(int)$priceValue['price_qty']] = $priceValue['website_price'];
        }

        return $resultPrices;
    }

    //########################################

    /**
     * @param bool $lifeMode
     *
     * @return int
     */
    public function getQty($lifeMode = false)
    {
        if ($lifeMode && (!$this->isStatusEnabled() || !$this->isStockAvailability())) {
            return 0;
        }

        if ($this->isStrictVariationProduct()) {
            if ($this->isBundleType()) {
                return $this->getBundleQty($lifeMode);
            }
            if ($this->isGroupedType()) {
                return $this->getGroupedQty($lifeMode);
            }
            if ($this->isConfigurableType()) {
                return $this->getConfigurableQty($lifeMode);
            }
        }

        return $this->calculateQty(
            $this->inventoryFactory->getObject($this->getProduct())->getQty(),
            $this->getStockItem()->getManageStock(),
            $this->getStockItem()->getUseConfigManageStock(),
            $this->getStockItem()->getBackorders(),
            $this->getStockItem()->getUseConfigBackorders()
        );
    }

    public function getBundleDefaultQty(int $productId): int
    {
        $product = $this->getProduct();
        $productInstance = $this->getTypeInstance();
        $optionCollection = $productInstance->getOptionsCollection($product);
        $selectionsCollection = $productInstance->getSelectionsCollection($optionCollection->getAllIds(), $product);
        $items = $selectionsCollection->getItems();

        foreach ($items as $item) {
            if ((int)$item->getId() === $productId) {
                $qty = (int)$item->getSelectionQty();
                if ($qty > 0) {
                    return $qty;
                }

                return 1;
            }
        }

        return 1;
    }

    // ---------------------------------------

    protected function calculateQty(
        $qty,
        $manageStock,
        $useConfigManageStock,
        $backorders,
        $useConfigBackorders
    ) {
        if (!$this->moduleConfiguration->isEnableProductForceQtyMode()) {
            return $qty;
        }

        $forceQtyValue = $this->moduleConfiguration->getProductForceQtyValue();

        $manageStockGlobal = $this->catalogInventoryConfiguration->getManageStock();
        if (($useConfigManageStock && !$manageStockGlobal) || (!$useConfigManageStock && !$manageStock)) {
            self::$statistics[$this->getStatisticId()]
            [$this->getProductId()]
            [$this->getStoreId()]
            ['qty']
            [self::FORCING_QTY_TYPE_MANAGE_STOCK_NO] = $forceQtyValue;

            return $forceQtyValue;
        }

        $backOrdersGlobal = $this->catalogInventoryConfiguration->getBackorders();
        if (
            ($useConfigBackorders && $backOrdersGlobal != \Magento\CatalogInventory\Model\Stock::BACKORDERS_NO) ||
            (!$useConfigBackorders && $backorders != \Magento\CatalogInventory\Model\Stock::BACKORDERS_NO)
        ) {
            if ($forceQtyValue > $qty) {
                self::$statistics[$this->getStatisticId()]
                [$this->getProductId()]
                [$this->getStoreId()]
                ['qty']
                [self::FORCING_QTY_TYPE_BACKORDERS] = $forceQtyValue;

                return $forceQtyValue;
            }
        }

        return $qty;
    }

    // ---------------------------------------

    /**
     * @param bool $lifeMode
     *
     * @return int
     */
    protected function getConfigurableQty($lifeMode = false)
    {
        $totalQty = 0;

        foreach ($this->getTypeInstance()->getUsedProducts($this->getProduct()) as $childProduct) {
            $inventory = $this->inventoryFactory->getObject($childProduct);
            $stockItem = $inventory->getStockItem();

            $qty = $this->calculateQty(
                $inventory->getQty(),
                $stockItem->getManageStock(),
                $stockItem->getUseConfigManageStock(),
                $stockItem->getBackorders(),
                $stockItem->getUseConfigBackorders()
            );

            if ($lifeMode && (!$inventory->isInStock() || $childProduct->getStatus() != Status::STATUS_ENABLED)) {
                continue;
            }

            $totalQty += $qty;
        }

        return $totalQty;
    }

    /**
     * @param bool $lifeMode
     *
     * @return int
     * @throws \Ess\M2ePro\Model\Exception
     */
    protected function getGroupedQty($lifeMode = false)
    {
        $value = 0;

        foreach ($this->getTypeInstance()->getAssociatedProducts($this->getProduct()) as $childProduct) {
            $inventory = $this->inventoryFactory->getObject($childProduct);
            $stockItem = $inventory->getStockItem();

            if (
                $lifeMode
                && (
                    $inventory->getQty() <= 0
                    || !$inventory->isInStock()
                    || $childProduct->getStatus() != Status::STATUS_ENABLED
                )
            ) {
                if (\Ess\M2ePro\Model\Listing\Product::GROUPED_PRODUCT_MODE_SET == $this->isGroupedProductMode) {
                    return 0; // not sellable product if any child "Out Of Stock" or Disable
                }

                continue;
            }

            $qty = $this->calculateQty(
                $inventory->getQty(),
                $stockItem->getManageStock(),
                $stockItem->getUseConfigManageStock(),
                $stockItem->getBackorders(),
                $stockItem->getUseConfigBackorders()
            );

            if (\Ess\M2ePro\Model\Listing\Product::GROUPED_PRODUCT_MODE_OPTIONS == $this->isGroupedProductMode) {
                $value += $qty;
                continue;
            }

            $defaultQty = $childProduct->getQty();
            if ($defaultQty <= 0 || $qty <= 0) {
                continue;
            }

            $qty = floor($qty / $defaultQty);
            if ($qty < 1) {
                return 0; // not sellable product if any child "Out Of Stock" or Disable
            }

            if ($value < $qty && $value != 0) { // where "0" is default $value
                continue;
            }

            $value = $qty;
        }

        return $value;
    }

    /**
     * @param bool $lifeMode
     *
     * @return int
     */
    protected function getBundleQty($lifeMode = false)
    {
        $product = $this->getProduct();

        // Prepare bundle options format usable for search
        $productInstance = $this->getTypeInstance();

        $optionCollection = $productInstance->getOptionsCollection($product);
        $optionsData = $optionCollection->getData();

        /** @var \Magento\Bundle\Model\Option $singleOption */
        foreach ($optionsData as $singleOption) {
            // Save QTY, before calculate = 0
            $bundleOptionsArray[$singleOption['option_id']] = 0;
        }

        $selectionsCollection = $productInstance->getSelectionsCollection($optionCollection->getAllIds(), $product);
        $items = $selectionsCollection->getItems();

        $bundleOptionsQtyArray = [];
        /** @var \Magento\Catalog\Model\Product $item */
        foreach ($items as $item) {
            if (!isset($bundleOptionsArray[$item->getOptionId()])) {
                continue;
            }

            $inventory = $this->inventoryFactory->getObject($item);
            $stockItem = $inventory->getStockItem(false);

            $qty = $this->calculateQty(
                $inventory->getQty(),
                $stockItem->getManageStock(),
                $stockItem->getUseConfigManageStock(),
                $stockItem->getBackorders(),
                $stockItem->getUseConfigBackorders()
            );

            $bundleDefaultQty = $this->getBundleDefaultQty($item->getId());
            $qty /= $bundleDefaultQty;

            if ($lifeMode && (!$inventory->isInStock() || $item->getStatus() != Status::STATUS_ENABLED)) {
                continue;
            }

            // Only positive
            // grouping qty by product id
            $bundleOptionsQtyArray[$item->getProductId()][$item->getOptionId()] = $qty;
        }

        foreach ($bundleOptionsQtyArray as $optionQty) {
            foreach ($optionQty as $optionId => $val) {
                $bundleOptionsArray[$optionId] += floor($val / count($optionQty));
            }
        }

        // Get min of qty product for all options
        $minQty = -1;
        foreach ($bundleOptionsArray as $singleBundle) {
            if ($singleBundle < $minQty || $minQty == -1) {
                $minQty = $singleBundle;
            }
        }

        return $minQty;
    }

    // ---------------------------------------

    public function setStatisticId($id)
    {
        $this->statisticId = $id;

        return $this;
    }

    public function getStatisticId()
    {
        return $this->statisticId;
    }

    //########################################

    public function getAttributeFrontendInput($attributeCode)
    {
        $productObject = $this->getProduct();

        /** @var \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute */
        $attribute = $productObject->getResource()->getAttribute($attributeCode);

        if (!$attribute) {
            $this->addNotFoundAttributes($attributeCode);

            return '';
        }

        if (!$productObject->hasData($attributeCode)) {
            $this->addNotFoundAttributes($attributeCode);

            return '';
        }

        return $attribute->getFrontendInput();
    }

    public function getAttributeValue($attributeCode, $convertBoolean = true)
    {
        $productObject = $this->getProduct();

        /** @var \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute */
        $attribute = $productObject->getResource()->getAttribute($attributeCode);

        if (!$attribute) {
            $this->addNotFoundAttributes($attributeCode);

            return '';
        }

        if ($attributeCode !== 'gallery' && !$productObject->hasData($attributeCode)) {
            $this->addNotFoundAttributes($attributeCode);

            return '';
        }

        $value = $productObject->getData($attributeCode);

        if ($attributeCode === 'media_gallery' || $attributeCode === 'gallery') {
            $links = [];
            foreach ($this->getGalleryImages(100) as $image) {
                if (!$image->getUrl()) {
                    continue;
                }
                $links[] = $image->getUrl();
            }

            return implode(',', $links);
        }

        if ($value === null || is_bool($value) || is_array($value) || $value === '') {
            return '';
        }

        // SELECT and MULTISELECT
        if ($attribute->getFrontendInput() === 'select' || $attribute->getFrontendInput() === 'multiselect') {
            if (
                $attribute->getSource() instanceof \Magento\Eav\Model\Entity\Attribute\Source\SourceInterface &&
                $attribute->getSource()->getAllOptions()
            ) {
                $attribute->setStoreId($this->getStoreId());

                /* This value is htmlEscaped::getOptionText()
                 * vendor/magento/module-eav/Model/Entity/Attribute/Source/Table.php
                 */
                $value = $attribute->getSource()->getOptionText($value);
                $value = $this->helperData->deEscapeHtml($value, ENT_QUOTES);

                $value = is_array($value) ? implode(',', $value) : (string)$value;
            }
            // DATE
        } elseif ($attribute->getFrontendInput() == 'date') {
            $temp = explode(' ', $value);
            isset($temp[0]) && $value = (string)$temp[0];
            // YES NO
        } elseif ($attribute->getFrontendInput() == 'boolean') {
            if ($convertBoolean) {
                (bool)$value ? $value = $this->helperFactory->getObject('Module\Translation')->__('Yes') :
                    $value = $this->helperFactory->getObject('Module\Translation')->__('No');
            } else {
                (bool)$value ? $value = 'true' : $value = 'false';
            }
            // PRICE
        } elseif ($attribute->getFrontendInput() == 'price') {
            $value = (string)number_format($value, 2, '.', '');
            // MEDIA IMAGE
        } elseif ($attribute->getFrontendInput() == 'media_image') {
            if ($value == 'no_selection') {
                $value = '';
            } else {
                if (!preg_match('((mailto\:|(news|(ht|f)tp(s?))\://){1}\S+)', $value)) {
                    $value = $this->storeFactory->create()
                                                ->load($this->getStoreId())
                                                ->getBaseUrl(
                                                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA,
                                                    $this->moduleConfiguration->getSecureImageUrlInItemDescriptionMode()
                                                )
                        . 'catalog/product/' . ltrim($value, '/');
                }
            }
        }

        if ($value instanceof \Magento\Framework\Phrase) {
            $value = $value->render();
        }

        return is_string($value) ? $value : '';
    }

    public function setAttributeValue($attributeCode, $value)
    {
        // supports only string values
        if (is_string($value)) {
            $productObject = $this->getProduct();

            $productObject->setData($attributeCode, $value)
                          ->getResource()
                          ->saveAttribute($productObject, $attributeCode);
        }

        return $this;
    }

    //########################################

    public function getThumbnailImage()
    {
        $resource = $this->resourceModel;

        $cacheHelper = $this->helperFactory->getObject('Data_Cache_Permanent');

        if (($attributeId = $cacheHelper->getValue(__METHOD__)) === null) {
            $attributeId = $resource->getConnection()
                                    ->select()
                                    ->from(
                                        $this->getHelper('Module_Database_Structure')->getTableNameWithPrefix(
                                            'eav_attribute'
                                        ),
                                        ['attribute_id']
                                    )
                                    ->where('attribute_code = ?', 'thumbnail')
                                    ->where(
                                        'entity_type_id = ?',
                                        $this->productFactory
                                            ->create()->getResource()->getTypeId()
                                    )
                                    ->query()
                                    ->fetchColumn();

            $cacheHelper->setValue(__METHOD__, $attributeId);
        }

        $storeIds = [(int)$this->getStoreId(), \Magento\Store\Model\Store::DEFAULT_STORE_ID];
        $storeIds = array_unique($storeIds);

        /** @var \Ess\M2ePro\Model\ResourceModel\Magento\Product\Collection $collection */
        $collection = $this->magentoProductCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', (int)$this->getProductId());
        $collection->joinTable(
            [
                'cpev' => $this->getHelper('Module_Database_Structure')
                               ->getTableNameWithPrefix('catalog_product_entity_varchar'),
            ],
            'entity_id = entity_id',
            ['value' => 'value']
        );
        $queryStmt = $collection->getSelect()
                                ->reset(\Magento\Framework\DB\Select::COLUMNS)
                                ->columns(['value' => 'cpev.value'])
                                ->where('cpev.store_id IN (?)', $storeIds)
                                ->where('cpev.attribute_id = ?', (int)$attributeId)
                                ->order('cpev.store_id DESC')
                                ->query();

        $thumbnailTempPath = null;
        while ($tempPath = $queryStmt->fetchColumn()) {
            if ($tempPath != '' && $tempPath != 'no_selection' && $tempPath != '/') {
                $thumbnailTempPath = $tempPath;
                break;
            }
        }

        if ($thumbnailTempPath === null) {
            return null;
        }

        $thumbnailTempPath = $this->filesystem
                ->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)
                ->getAbsolutePath() . 'catalog/product/' . ltrim($thumbnailTempPath, '/');

        $image = $this->imageFactory->create();
        $image->setPath($thumbnailTempPath);
        $image->setArea(\Magento\Framework\App\Area::AREA_ADMINHTML);
        $image->setStoreId($this->getStoreId());

        if (!$image->isSelfHosted()) {
            return null;
        }

        $width = 100;
        $height = 100;

        $fileDriver = $this->driverPool->getDriver(\Magento\Framework\Filesystem\DriverPool::FILE);
        $prefixResizedImage = "resized-{$width}px-{$height}px-";
        $imagePathResized = dirname($image->getPath())
            . DIRECTORY_SEPARATOR
            . $prefixResizedImage
            . basename($image->getPath());

        if ($fileDriver->isFile($imagePathResized)) {
            $currentTime = $this->helperFactory->getObject('Data')->getCurrentGmtDate(true);

            if (filemtime($imagePathResized) + self::THUMBNAIL_IMAGE_CACHE_TIME > $currentTime) {
                $image->setPath($imagePathResized)
                      ->setUrl($image->getUrlByPath())
                      ->resetHash();

                return $image;
            }

            $fileDriver->deleteFile($imagePathResized);
        }

        try {
            $imageObj = $this->objectManager->create(
                \Magento\Framework\Image::class,
                [
                    'fileName' => $image->getPath(),
                ]
            );
            $imageObj->constrainOnly(true);
            $imageObj->keepAspectRatio(true);
            $imageObj->keepFrame(false);
            $imageObj->resize($width, $height);
            $imageObj->save($imagePathResized);
        } catch (\Exception $exception) {
            return null;
        }

        if (!$fileDriver->isFile($imagePathResized)) {
            return null;
        }

        $image->setPath($imagePathResized)
              ->setUrl($image->getUrlByPath())
              ->resetHash();

        return $image;
    }

    /**
     * @param string $attribute
     *
     * @return Image|null
     */
    public function getImage($attribute = 'image')
    {
        if (empty($attribute)) {
            return null;
        }

        $imageUrl = $this->getAttributeValue($attribute);
        $imageUrl = $this->prepareImageUrl($imageUrl);

        if (empty($imageUrl)) {
            return null;
        }

        $image = $this->imageFactory->create();
        $image->setUrl($imageUrl);
        $image->setStoreId($this->getStoreId());

        return $image;
    }

    /**
     * @param int $limitImages
     *
     * @return Image[]
     */
    public function getGalleryImages($limitImages = 0)
    {
        $limitImages = (int)$limitImages;

        if ($limitImages <= 0) {
            return [];
        }

        $galleryImages = $this->getProduct()->getMediaGalleryEntries();

        if (empty($galleryImages)) {
            return [];
        }

        $i = 0;
        $images = [];

        foreach ($galleryImages as $galleryImage) {
            if ($i >= $limitImages) {
                break;
            }

            if ($this->isNeedSkipGalleryImage($galleryImage)) {
                continue;
            }

            $imageUrl = $this->storeFactory
                ->create()
                ->load($this->getStoreId())
                ->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA,
                    $this->moduleConfiguration->getSecureImageUrlInItemDescriptionMode()
                );
            $imageUrl .= 'catalog/product/' . ltrim($galleryImage->getFile(), '/');
            $imageUrl = $this->prepareImageUrl($imageUrl);

            if (empty($imageUrl)) {
                continue;
            }

            $image = $this->imageFactory->create();
            $image->setUrl($imageUrl);
            $image->setStoreId($this->getStoreId());

            $images[] = $image;
            $i++;
        }

        return $images;
    }

    /**
     * @param int $position
     *
     * @return Image|null
     */
    public function getGalleryImageByPosition($position = 1)
    {
        $position = (int)$position;

        if ($position <= 0) {
            return null;
        }

        // need for correct sampling of the array
        $position--;

        $galleryImages = $this->getProduct()->getData('media_gallery');

        if (!isset($galleryImages['images']) || !is_array($galleryImages['images'])) {
            return null;
        }

        $galleryImages = array_values($galleryImages['images']);

        if (!isset($galleryImages[$position])) {
            return null;
        }

        $galleryImage = $galleryImages[$position];

        if (isset($galleryImage['disabled']) && (bool)$galleryImage['disabled']) {
            return null;
        }

        if (!isset($galleryImage['file'])) {
            return null;
        }

        $imagePath = 'catalog/product/' . ltrim($galleryImage['file'], '/');
        $imageUrl = $this->storeFactory->create()
                                       ->load($this->getStoreId())
                                       ->getBaseUrl(
                                           \Magento\Framework\UrlInterface::URL_TYPE_MEDIA,
                                           $this->moduleConfiguration->getSecureImageUrlInItemDescriptionMode()
                                       ) . $imagePath;

        $imageUrl = $this->prepareImageUrl($imageUrl);

        $image = $this->imageFactory->create();
        $image->setUrl($imageUrl);
        $image->setStoreId($this->getStoreId());

        return $image;
    }

    private function prepareImageUrl($url)
    {
        if (!is_string($url) || $url == '') {
            return '';
        }

        return str_replace(' ', '%20', $url);
    }

    //########################################

    public function getGroupedWeight()
    {
        $groupedProductWeight = 0;

        if ($this->isGroupedType()) {
            foreach ($this->getTypeInstance()->getAssociatedProducts($this->getProduct()) as $childProduct) {
                /** @var \Magento\Catalog\Model\Product $product */
                $product = $this->productFactory->create();
                $product->setStoreId($childProduct->getStoreId());
                $this->resourceProduct->load($product, $childProduct->getId(), ['weight']);
                $groupedProductWeight += $childProduct->getQty() * $product->getWeight();
            }
        }

        return $groupedProductWeight;
    }

    public function getVariationMaxWeight(string $attributeName): string
    {
        $productsIds = $this->getTypeInstance()->getChildrenIds($this->getProduct()->getId());
        $productsIds = reset($productsIds);

        if (empty($productsIds)) {
            return '0';
        }

        $weights = array_map(function ($productId) use ($attributeName) {
            $product = $this->productRepository->getById($productId);
            $tmpProduct = $this->m2eProductFactory->create();
            $tmpProduct->setProduct($product);

            return $tmpProduct->getAttributeValue($attributeName);
        }, $productsIds);

        return max($weights);
    }

    //########################################

    public function getVariationInstance()
    {
        if ($this->_variationInstance === null) {
            $this->_variationInstance = $this->modelFactory
                ->getObject('Magento_Product_Variation')
                ->setMagentoProduct($this);
        }

        return $this->_variationInstance;
    }

    //########################################

    private function addNotFoundAttributes($attributeCode)
    {
        $this->notFoundAttributes[] = $attributeCode;
        $this->notFoundAttributes = array_unique($this->notFoundAttributes);
    }

    // ---------------------------------------

    /**
     * @return array
     */
    public function getNotFoundAttributes()
    {
        return $this->notFoundAttributes;
    }

    public function clearNotFoundAttributes()
    {
        $this->notFoundAttributes = [];
    }

    // ----------------------------------------

    private function isNeedSkipGalleryImage(
        \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface $galleryImage
    ): bool {
        if ($galleryImage->isDisabled()) {
            return true;
        }

        if (empty($galleryImage->getFile())) {
            return true;
        }

        if ($galleryImage->getMediaType() === ExternalVideoEntryConverter::MEDIA_TYPE_CODE) {
            return true;
        }

        if (in_array('image', $galleryImage->getTypes())) {
            return false;
        }

        if (in_array('small_image', $galleryImage->getTypes())) {
            return true;
        }

        if (in_array('thumbnail', $galleryImage->getTypes())) {
            return true;
        }

        return false;
    }
}
