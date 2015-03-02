<?php

class Auctane_Api_Model_Action_Export {

    /**
     * Perform an export according to the given request.
     *
     * @param Mage_Core_Controller_Request_Http $request
     * @param Mage_Core_Controller_Response_Http $response
     * @throws Exception
     */
    public function process(Mage_Core_Controller_Request_Http $request, Mage_Core_Controller_Response_Http $response) {
        // In case store is part of URL path use it to choose config.
        $store = $request->get('store');
        if ($store)
            $store = Mage::app()->getStore($store);

        $apiConfigCharset = Mage::getStoreConfig("api/config/charset", $store);

        $start_date = strtotime(urldecode($request->getParam('start_date')));
        $end_date = strtotime(urldecode($request->getParam('end_date')));
        if (!$start_date || !$end_date)
            throw new Exception('Start and end dates are required', 400);

        $page = (int) $request->getParam('page');

        /* @var $orders Mage_Sales_Model_Mysql4_Order_Collection */
        $orders = Mage::getResourceModel('sales/order_collection');
        // might use 'created_at' attribute instead
        $orders->addAttributeToFilter('updated_at', array(
            'from' => date('Y-m-d H:i:s', $start_date),
            'to' => date('Y-m-d H:i:s', $end_date)
        ));
        if ($store)
            $orders->addAttributeToFilter('store_id', $store->getId());
        if ($page > 0)
            $orders->setPage($page, $this->_getExportPageSize());
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', $apiConfigCharset);
        $this->_writeOrders($orders, $xml, $store ? $store->getId() : 0);
        $xml->endDocument();

        $response->clearHeaders()
                ->setHeader('Content-Type', 'text/xml; charset=' . $apiConfigCharset)
                ->setBody($xml->outputMemory(true));
    }

    protected function _getExportPageSize() {
        return (int) Mage::getStoreConfig('auctaneapi/config/exportPageSize');
    }

    protected function _writeOrders(Varien_Data_Collection $orders, XMLWriter $xml, $storeId = null) {
        $xml->startElement('Orders');
        $xml->writeAttribute('pages', $orders->getLastPageNumber());
        foreach ($orders as $order) {
            $this->_writeOrder($order, $xml, $storeId);
        }
        $xml->startElement('Query');
        $xml->writeCdata($orders->getSelectSql());
        $xml->endElement(); // Query

        $xml->startElement('Version');
        $xml->writeCdata('Magento ' . Mage::getVersion());
        $xml->endElement(); // Version

        $xml->startElement('Extensions');
        $xml->writeCdata(Mage::helper('auctaneapi')->getModuleList());
        $xml->endElement(); // Extensions

        $xml->endElement(); // Orders
    }

    protected function _writeOrder(Mage_Sales_Model_Order $order, XMLWriter $xml, $storeId = null) {
        $history = '';
        /* @var $status Mage_Sales_Model_Order_Status */
        foreach ($order->getStatusHistoryCollection() as $status) {
            if ($status->getComment()) {
                $history .= $status->getCreatedAt() . PHP_EOL;
                $history .= $status->getComment() . PHP_EOL . PHP_EOL;
            }
        }
        $history = trim($history);
        if ($history) {
            $order->setStatusHistoryText($history);
        }

        /* @var $gift Mage_GiftMessage_Model_Message */
        $gift = Mage::helper('giftmessage/message')->getGiftMessage($order->getGiftMessageId());
        $order->setGift($gift->isObjectNew() ? 'false' : 'true');
        if (!$gift->isObjectNew()) {
            $order->setGiftMessage(sprintf("From: %s\nTo: %s\nMessage: %s", $gift->getSender(), $gift->getRecipient(), $gift->getMessage()));
        }

        $helper = Mage::helper('auctaneapi');

        $xml->startElement('Order');

        if ($helper->getExportPriceType($order->getStoreId()) == Auctane_Api_Model_System_Source_Config_Prices::BASE_PRICE) {
            $helper->fieldsetToXml('base_sales_order', $order, $xml);
        } else {
            $helper->fieldsetToXml('sales_order', $order, $xml);
        }

        $xml->startElement('Customer');
        $xml->startElement('CustomerCode');
        $xml->writeCdata($order->getCustomerEmail());
        $xml->endElement(); // CustomerCode

        $xml->startElement('BillTo');
        $helper->fieldsetToXml('sales_order_billing_address', $order->getBillingAddress(), $xml);
        $xml->endElement(); // BillTo

        $xml->startElement('ShipTo');
        $helper->fieldsetToXml('sales_order_shipping_address', $order->getShippingAddress(), $xml);
        $xml->endElement(); // ShipTo

        $xml->endElement(); // Customer

        /** add purchase order nubmer */
        Mage::helper('auctaneapi')->writePoNumber($order, $xml);

        $xml->startElement('Items');
        /* @var $item Mage_Sales_Model_Order_Item */
        $bundleItems = array();
        $orderItems = array();
        $intCnt = 0;
        //Check for the bundle child product to import
        $intImportChildProducts = Mage::getStoreConfig('auctaneapi/general/import_child_products');
        foreach ($order->getItemsCollection($helper->getIncludedProductTypes()) as $item) {
            /* @var $product Mage_Catalog_Model_Product */
            $product = Mage::getModel('catalog/product')
                    ->setStoreId($storeId)
                    ->load($item->getProductId());
            $productId = $product->getId();

            $boolIsBundleProduct = false;
            if ($product->getTypeId() === 'bundle') {
                //Get all bundle items of this bundle product
                $bundleItems = array_flip(Mage::helper('auctaneapi')->getBundleItems($productId));
                $boolIsBundleProduct = true;
            }
            //Check for the parent bundle item type
            $parentItem = $this->_getOrderItemParent($item);
            if ($parentItem) {
                if ($intImportChildProducts == 2 && $parentItem->getProductType() === 'bundle') {
                    continue;
                }
            }

            if (isset($bundleItems[$productId])) {
                //Remove item from bundle product items
                unset($bundleItems[$productId]);
                $orderItems[$intCnt]['item'] = $item;
                $orderItems[$intCnt]['bundle'] = 1;
            } else {
                //These are items for next processing
                $orderItems[$intCnt]['item'] = $item;
                $orderItems[$intCnt]['bundle'] = 0;

                if ($boolIsBundleProduct == true) {
                    $orderItems[$intCnt]['bundle'] = 2;
                }
            }
            $intCnt++;
        }

        foreach ($orderItems as $key => $item) {
            $this->_writeOrderItem($item['item'], $xml, $storeId, $item['bundle']);
        }

        $intImportDiscount = Mage::getStoreConfig('auctaneapi/general/import_discounts');

        if ($intImportDiscount == 1) { // Import Discount is true
            $discounts = array();
            if ($order->getData('auctaneapi_discounts')) {
                $discounts = @unserialize($order->getData('auctaneapi_discounts'));
                if (is_array($discounts)) {
                    $aggregated = array();
                    foreach ($discounts as $key => $discount) {
                        $keyData = explode('-', $key);
                        if (isset($aggregated[$keyData[0]])) {
                            $aggregated[$keyData[0]] += $discount;
                        } else {
                            $aggregated[$keyData[0]] = $discount;
                        }
                    }
                    Mage::helper('auctaneapi')->writeDiscountsInfo($aggregated, $xml);
                }
            }
        }

        $xml->endElement(); // Items

        $xml->endElement(); // Order
    }

    protected function _writeOrderItem(Mage_Sales_Model_Order_Item $item, XMLWriter $xml, $storeId = null, $isBundle = 0) {
        // inherit some attributes from parent order item
        if ($item->getParentItemId() && !$item->getParentItem()) {
            $item->setParentItem(Mage::getModel('sales/order_item')->load($item->getParentItemId()));
        }
        // only inherit if parent has been hidden
        if ($item->getParentItem() && ($item->getPrice() == 0.000) && (Mage::helper('auctaneapi')->isExcludedProductType($item->getParentItem()->getProductType()))) {
            //set the store price of item from parent item
            $item->setPrice($item->getParentItem()->getPrice());
            //set the base price of item from parent item
            $item->setBasePrice($item->getParentItem()->getBasePrice());
        }

        /* @var $gift Mage_GiftMessage_Model_Message */
        $gift = Mage::helper('giftmessage/message')->getGiftMessage(
                !$item->getGiftMessageId() && $item->getParentItem() ? $item->getParentItem()->getGiftMessageId() : $item->getGiftMessageId());
        $item->setGift($gift->isObjectNew() ? 'false' : 'true');
        if (!$gift->isObjectNew()) {
            $item->setGiftMessage(sprintf("From: %s\nTo: %s\nMessage: %s", $gift->getSender(), $gift->getRecipient(), $gift->getMessage()));
        }

        /* @var $product Mage_Catalog_Model_Product */
        $product = Mage::getModel('catalog/product')
                ->setStoreId($storeId)
                ->load($item->getProductId());
        // inherit some attributes from parent product item
        if (($parentProduct = $this->_getOrderItemParentProduct($item, $storeId))) {
            if (!$product->getImage() || ($product->getImage() == 'no_selection'))
                $product->setImage($parentProduct->getImage());
            if (!$product->getSmallImage() || ($product->getSmallImage() == 'no_selection'))
                $product->setSmallImage($parentProduct->getSmallImage());
            if (!$product->getThumbnail() || ($product->getThumbnail() == 'no_selection'))
                $product->setThumbnail($parentProduct->getThumbnail());
        }


        $xml->startElement('Item');

        $helper = Mage::helper('auctaneapi');
        if (Mage::helper('auctaneapi')->getExportPriceType($item->getOrder()->getStoreId()) ==
                Auctane_Api_Model_System_Source_Config_Prices::BASE_PRICE) {
            $helper->fieldsetToXml('base_sales_order_item', $item, $xml, $isBundle);
        } else {
            $helper->fieldsetToXml('sales_order_item', $item, $xml, $isBundle);
        }

        /* using emulation so that product images come from the correct store */
        $appEmulation = Mage::getSingleton('core/app_emulation');
        $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($product->getStoreId());
        Mage::helper('auctaneapi')->fieldsetToXml('sales_order_item_product', $product, $xml);
        $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);

        $xml->startElement('Options');
        $this->_writeOrderProductAttribute($product, $xml, $storeId);
        // items may have several custom options chosen by customer
        foreach ((array) $item->getProductOptionByCode('options') as $option) {
            $this->_writeOrderItemOption($option, $xml, $storeId);
        }
        $buyRequest = $item->getProductOptionByCode('info_buyRequest');
        if ($buyRequest && @$buyRequest['super_attribute']) {
            // super_attribute is non-null and non-empty, there must be a Configurable involved
            $parentItem = $this->_getOrderItemParent($item);
            /* export configurable custom options as they are stored in parent */
            foreach ((array) $parentItem->getProductOptionByCode('options') as $option) {
                $this->_writeOrderItemOption($option, $xml, $storeId);
            }
            foreach ((array) $parentItem->getProductOptionByCode('attributes_info') as $option) {
                $this->_writeOrderItemOption($option, $xml, $storeId);
            }
        }
        $xml->endElement(); // Options

        $xml->endElement(); // Item
    }

    protected function _writeOrderProductAttribute(Mage_Catalog_Model_Product $product, XMLWriter $xml, $storeId = null) {
        // custom attributes are specified in Admin > Configuration > Sales > Auctane Shipstation API
        // static because attributes can be cached, they do not change during a request
        static $attrs = null;
        if (is_null($attrs)) {
            $attrs = Mage::getResourceModel('eav/entity_attribute_collection');
            $attrIds = explode(',', Mage::getStoreConfig('auctaneapi/general/customattributes', $storeId));
            $attrs->addFieldToFilter('attribute_id', $attrIds);
        }

        /* @var $attr Mage_Eav_Model_Entity_Attribute */
        foreach ($attrs as $attr) {
            if ($product->hasData($attr->getName())) {
                // if an attribute has options/labels
                if (in_array($attr->getFrontendInput(), array('select', 'multiselect'))) {
                    $value = $product->getAttributeText($attr->getName());
                    if (is_array($value))
                        $value = implode(',', $value);
                }
                // else is a static value
                else {
                    $value = $product->getDataUsingMethod($attr->getName());
                }
                // fake an item option
                $option = array(
                    'value' => $value,
                    'label' => $attr->getFrontendLabel()
                );
                $this->_writeOrderItemOption($option, $xml, $storeId);
            }
        }
    }

    protected function _writeOrderItemOption($option, XMLWriter $xml) {
        $xml->startElement('Option');
        Mage::helper('auctaneapi')->fieldsetToXml('sales_order_item_option', $option, $xml);
        $xml->endElement(); // Option
    }

    /**
     * Safe way to lookup parent order items.
     *
     * @param Mage_Sales_Model_Order_Item $item
     * @return Mage_Sales_Model_Order_Item
     */
    protected function _getOrderItemParent(Mage_Sales_Model_Order_Item $item) {
        if ($item->getParentItem()) {
            return $item->getParentItem();
        }

        $parentItem = Mage::getModel('sales/order_item')
                ->load($item->getParentItemId());
        $item->setParentItem($parentItem);
        return $parentItem;
    }

    /**
     * @param Mage_Sales_Model_Order_Item $item
     * @param mixed $storeId
     * @return Mage_Catalog_Model_Product
     */
    protected function _getOrderItemParentProduct(Mage_Sales_Model_Order_Item $item, $storeId = null) {
        if ($item->getParentItemId()) {
            // cannot use getParentItem() because we stripped parents from the order
            $parentItem = $this->_getOrderItemParent($item);
            // initialise with store so that images are correct
            return Mage::getModel('catalog/product')
                            ->setStoreId($storeId)
                            ->load($parentItem->getProductId());
        }
        return null;
    }

}
