<?php

namespace Stream\ProductApi\Model;


use Magento\Framework\Exception\NotFoundException;
use Stream\ProductApi\Api\ProductUpdateManagementInterface as ProductApiInterface;
use Magento\Framework\Exception\InputException;

class ProductUpdateManagement implements ProductApiInterface
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;
    /**
     * @var \Stream\ProductApi\Helper\Data
     */
    private $helper;
    /**
     * @var \Magento\Catalog\Api\ProductTierPriceManagementInterface
     */
    private $tierPriceManager;
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Action
     */
    private $productAction;
    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    private $timezone;
    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute
     */
    private $eavAttribute;
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    private $productCollectionFactory;
    /**
     * @var \BoostMyShop\Supplier\Model\Supplier\ProductFactory
     */
    private $supplierProductFactory;
    /**
     * @var \BoostMyShop\Supplier\Model\SupplierFactory
     */
    private $supplierFactory;

    protected $tradetrekAttributeId = null;

    protected $customerGroupIds = [];

    protected $supplierIds = [];

    protected $defaultLimit = 100;

    const TRADETREK_ATTRIBUTE_CODE = 'tradetrek_sku';

    /**
     * ProductUpdateManagement constructor.
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
     * @param \BoostMyShop\Supplier\Model\Supplier\ProductFactory $supplierProductFactory
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \BoostMyShop\Supplier\Model\SupplierFactory $supplierFactory
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute $eavAttribute
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone
     * @param \Magento\Catalog\Model\ResourceModel\Product\Action $productAction
     * @param \Magento\Catalog\Api\ProductTierPriceManagementInterface $tierPriceManager
     * @param \Stream\ProductApi\Helper\Data $helper
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \BoostMyShop\Supplier\Model\Supplier\ProductFactory $supplierProductFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \BoostMyShop\Supplier\Model\SupplierFactory $supplierFactory,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute $eavAttribute,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        \Magento\Catalog\Model\ResourceModel\Product\Action $productAction,
        \Magento\Catalog\Api\ProductTierPriceManagementInterface $tierPriceManager,
        \Stream\ProductApi\Helper\Data $helper,
        \Psr\Log\LoggerInterface $logger,
        $defaultLimit = 100
    ) {
        $this->connection = $resourceConnection->getConnection();
        $this->logger = $logger;
        $this->helper = $helper;
        $this->tierPriceManager = $tierPriceManager;
        $this->productAction = $productAction;
        $this->timezone = $timezone;
        $this->eavAttribute = $eavAttribute;
        $this->productRepository = $productRepository;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->supplierProductFactory = $supplierProductFactory;
        $this->supplierFactory = $supplierFactory;
        $this->defaultLimit = $defaultLimit;
    }

    /**
     * Get TradetrekSKU product attribute code
     * @return int|null
     * @throws NotFoundException
     */
    protected function getTradetrekAttributeId()
    {
        if (!$this->tradetrekAttributeId) {
            $this->tradetrekAttributeId = $this->eavAttribute->getIdByCode('catalog_product',
                self::TRADETREK_ATTRIBUTE_CODE);
        }
        if (!$this->tradetrekAttributeId) {
            throw new NotFoundException(__('Tradetrek attribute is missing in the system.'));
        }
        return $this->tradetrekAttributeId;
    }

    /**
     * Updates the specified product from the request payload.
     *
     * @api
     * @param mixed $products
     * @return array|boolean
     * @throws \Exception
     */
    public function updateProduct($products)
    {
        if (empty($products)) {
            throw new InputException(__('Please provide valid data.'));
        }
        if (count($products) > $this->defaultLimit) {
            throw new InputException(__('You have requested to update more than allowed max limit. Max allowed limit is: %1',
                $this->defaultLimit));
        }
        $result = [];
        foreach ($products as $product) {
            $storeId = 0;
            $tradetrakSKU = $product['tradetrek_sku'];
            $price = $product['price'];
            $cost = $product['cost'];
            $customerGroups = $product['customer_group'];
            $supplierCode = $product['supplier_no'];
            $supplierPrice = $product['supplier_price'];
            $productID = $this->getProductIdByTradetrekSKU($tradetrakSKU);

            if (!$productID) {
                $result[$tradetrakSKU][] = __('Tradetrak SKU %1 not found in the system.', $tradetrakSKU);
                continue;
            }

            /*Update product price*/
            if ($price) {
                try {
                    $this->updateProductPrice($productID, $price, $storeId);
                    $this->helper->toLog(__($tradetrakSKU . ' :: Product price updated "%1"', $price));
                } catch (\Exception $e) {
                    $result[$tradetrakSKU][] = __($tradetrakSKU . ' :: Product price not updated.');
                    $this->helper->toLog($tradetrakSKU . ' :: ' . $e->getMessage());
                }
            }

            /*Update product cost*/
            if ($cost) {
                try {
                    $this->updateProductCost($productID, $cost, $storeId);
                    $this->helper->toLog(__($tradetrakSKU . ' :: Product cost updated "%1"', $cost));
                } catch (\Exception $e) {
                    $result[$tradetrakSKU][] = __($tradetrakSKU . ' :: Product cost not updated.');
                    $this->helper->toLog($tradetrakSKU . ' :: ' . $e->getMessage());
                }
            }

            /*Update tier price*/
            if ($customerGroups && count($customerGroups) > 0) {
                foreach ($customerGroups as $customerGroup) {
                    if (!isset($customerGroup['name'])) {
                        /*skip if customer group not defined*/
                        continue;
                    }
                    try {
                        $customerGroupId = $this->getCustomerGroupIdByName($customerGroup['name']);
                        if ($customerGroupId) {
                            $this->updateTierPrices($productID, $customerGroupId,
                                $customerGroup['price']);
                            $this->helper->toLog(__($tradetrakSKU . ' :: Tier price "%1" price "%2"', $customerGroup['name'], $customerGroup['price']));
                        } else {
                            throw new NotFoundException(__($tradetrakSKU . ' :: Customer group "%1" doesn\'t exists',
                                $customerGroup['name']));
                        }
                    } catch (\Exception $e) {
                        $result[$tradetrakSKU][] = __($tradetrakSKU . ' :: Customer group price not updated for "%1".',
                            $customerGroup['name']);
                        $this->helper->toLog($tradetrakSKU . ' :: ' . $e->getMessage());
                    }
                }
            }

            /*Update supplier cost*/
            if ($supplierCode && $supplierPrice >= 0) {
                try {
                    $this->updateProductSupplierCost($productID, $supplierCode, $supplierPrice);
                    $this->helper->toLog(__($tradetrakSKU . ' :: Supplier cost "%1" price "%2"', $supplierCode, $supplierPrice));
                } catch (\Exception $e) {
                    $result[$tradetrakSKU][] = __($tradetrakSKU . ' :: Supplier cost not updated for supplier code "%1".', $supplierCode);
                    $this->helper->toLog($tradetrakSKU . ' :: ' . $e->getMessage());
                }
            }

        }

        if (count($result) > 0) {
            return $result;
        }
        return true;
    }

    /**
     * @param $productId
     * @param $supplierCode
     * @param $supplierCost
     * @return $this
     * @throws NotFoundException
     */
    public function updateProductSupplierCost($productId, $supplierCode, $supplierCost)
    {
        $supplierId = $this->getSupplierIdByCode($supplierCode);
        if (!$supplierId) {
            throw new NotFoundException(__('Supplier with code %1 doesn\'t exists', $supplierCode));
        }
        $productSupplier = $this->supplierProductFactory->create()
            ->loadByProductSupplier($productId, $supplierId);

        if ($productSupplier && $productSupplier->getId()) {
            $productSupplier->setData('sp_updated_at', $this->timezone->date());
            $productSupplier->setData('sp_price', (double)$supplierCost);
            $productSupplier->save();
        } else {
            throw new NotFoundException(__('Supplier with code %1 is not associated with %2', $supplierCode,
                $productId));
        }
        return $this;
    }

    /**
     * @param $productId
     * @param $price
     * @param int $storeId
     * @return $this
     * @throws \Exception
     */
    public function updateProductPrice($productId, $price, $storeId = 0)
    {
        $this->productAction->updateAttributes([$productId], ['price' => $price], $storeId);
        return $this;
    }

    /**
     * @param $productId
     * @param $cost
     * @param int $storeId
     * @return $this
     * @throws \Exception
     */
    public function updateProductCost($productId, $cost, $storeId = 0)
    {
        $this->productAction->updateAttributes([$productId], ['cost' => $cost], $storeId);
        return $this;
    }

    /**
     * @param $tradetrekSKU
     * @return int|null
     * @throws NotFoundException
     */
    public function getProductIdByTradetrekSKU($tradetrekSKU)
    {
        $bind = [':attribute_id' => $this->getTradetrekAttributeId(), ':value' => $tradetrekSKU, ':store_id' => 0];
        $select = $this->connection->select()
            ->from(['cpev' => $this->connection->getTableName('catalog_product_entity_varchar')],
                ['cpev.entity_id'])
            ->where('cpev.attribute_id = :attribute_id')
            ->where('cpev.value = :value')
            ->where('cpev.store_id = :store_id');
        return $this->connection->fetchOne($select, $bind);
    }

    /**
     * Get Supplier Id by Code
     *
     * @param $supplierCode
     * @return mixed
     */
    public function getSupplierIdByCode($supplierCode)
    {
        if (!$supplierCode) {
            return false;
        }
        if (!isset($this->supplierIds[$supplierCode])) {
            $bind = [':sup_code' => $supplierCode];
            $supSelect = $this->connection->select()
                ->from(['sup' => $this->connection->getTableName('bms_supplier')], ['sup.sup_id'])
                ->where('sup.sup_code = :sup_code');
            $this->supplierIds[$supplierCode] = $this->connection->fetchOne($supSelect, $bind);
        }
        return $this->supplierIds[$supplierCode];
    }

    /**
     * @param $entityId
     * @param $customerGroupId
     * @param $price
     */
    public function updateTierPrices($entityId, $customerGroupId, $price)
    {
        $connection = $this->connection;
        $tierPriceTable = $connection->getTableName('catalog_product_entity_tier_price');
        $insertQuery = "INSERT INTO " . $tierPriceTable . " (entity_id, all_groups, customer_group_id, qty, value, website_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)";
        $connection->query(
            $insertQuery, [$entityId, 0, $customerGroupId, 1, (double)$price, 0]
        );
    }

    /**
     * @param $customerGroupName
     * @return bool|mixed
     */
    public function getCustomerGroupIdByName($customerGroupName)
    {
        if (!$customerGroupName) {
            return false;
        }
        if (!isset($this->customerGroupIds[$customerGroupName])) {
            $bind = [':customer_group_code' => $customerGroupName];
            $select = $this->connection->select()
                ->from(['cg' => $this->connection->getTableName('customer_group')], ['cg.customer_group_id'])
                ->where('cg.customer_group_code = :customer_group_code');

            $this->customerGroupIds[$customerGroupName] = $this->connection->fetchOne($select, $bind);
        }

        return $this->customerGroupIds[$customerGroupName];
    }

}