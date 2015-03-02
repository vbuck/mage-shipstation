<?php

/**
 * ShipStation
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@auctane.com so we can send you a copy immediately.
 *
 * @category   Shipping
 * @package    Auctane_Api
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Auctane_Api_Helper_Data extends Mage_Core_Helper_Data {

    private $_strBundleSku = '';

    /**
     * Write a source object to an XML stream, mapping fields via a fieldset.
     * Fieldsets are nodes in config.xml
     * 
     * @param string $fieldset
     * @param array|Varien_Object $source
     * @param XMLWriter $xml
     */
    public function fieldsetToXml($fieldset, $source, XMLWriter $xml, $isBundle = 0) {
        $fields = (array) Mage::getConfig()->getFieldset($fieldset);
        //Check for the importing the child product settings
        $intImportChildProducts = Mage::getStoreConfig('auctaneapi/general/import_child_products');
        foreach ($fields as $field => $dest) {
            if (!$dest->auctaneapi)
                continue;

            $name = $dest->auctaneapi == '*' ? $field : $dest->auctaneapi;
            $value = $source instanceof Varien_Object ? $source->getDataUsingMethod($field) : @$source[$field];
            //Set the child item price of bundle products to 0
            if ($isBundle == 1 && $name == 'UnitPrice' && $intImportChildProducts == 1) {
                $value = 0;
            }
            $xml->startElement((string) $name);
            if (is_numeric($value)) {
                $xml->text((string) $value);
            } elseif ($value) {
                $xml->writeCdata((string) $value);
            }
            $xml->endElement();
        }
    }

    /**
     * Write discounts info to order
     * @param array $discount
     * @param XMLWriter $xml
     */
    public function writeDiscountsInfo(array $discounts, XMLWriter $xml) {
        foreach ($discounts as $rule => $total) {
            $salesRule = Mage::getModel('salesrule/rule')->load($rule);

            if (!$salesRule->getId()) {
                continue;
            }

            $xml->startElement('Item');

            $xml->startElement('SKU');
            $xml->writeCdata($salesRule->getCouponCode() ? $salesRule->getCouponCode() : 'AUTOMATIC_DISCOUNT');
            $xml->endElement();
            $xml->startElement('Name');
            $xml->writeCdata($salesRule->getName());
            $xml->endElement();
            $xml->startElement('Adjustment');
            $xml->writeCdata('true');
            $xml->endElement();
            $xml->startElement('Quantity');
            $xml->text(1);
            $xml->endElement();
            $xml->startElement('UnitPrice');
            $xml->text(-$total);
            $xml->endElement();

            $xml->endElement();
        }
    }

    /**
     * Write purchase order info to order
     * @param Mage_Sales_Model_Order $order
     * @param XMLWriter $xml
     */
    public function writePoNumber($order, $xml) {
        $payment = $order->getPayment();
        $xml->startElement('PO');
        if ($payment) {
            $xml->writeCdata($payment->getPoNumber());
        }
        $xml->endElement();
    }

    /**
     * @return array of string names
     * @see "auctane_exclude" nodes in config.xml
     */
    public function getIncludedProductTypes() {
        static $types;
        if (!isset($types)) {
            $types = Mage::getModel('catalog/product_type')->getTypes();
            $types = array_filter($types, create_function('$type', 'return !@$type["auctane_exclude"];'));
        }
        return array_keys($types);
    }

    /**
     * Indicate if a {$type} is specifically excluded by config
     * 
     * @param string $type
     * @return bool
     */
    public function isExcludedProductType($type) {
        static $types;
        if (!isset($types)) {
            $types = Mage::getModel('catalog/product_type')->getTypes();
            $types = array_filter($types, create_function('$type', 'return (bool) @$type["auctane_exclude"];'));
        }
        return isset($types[$type]);
    }

    /**
     * A simple list of module names for debugging.
     * 
     * @return string
     */
    public function getModuleList() {
        $modules = array_keys((array) Mage::getConfig()->getNode('modules')->children());
        return implode(',', $modules);
    }

    /**
     * Get export price type
     * 
     * @param int $store
     * @return int
     */
    public function getExportPriceType($store) {
        return Mage::getStoreConfig('auctaneapi/general/export_price', $store);
    }

    /**
     * Get bundle items for specific bundle product
     * 
     * @param int $bundleProductId
     * @return array()
     */
    public function getBundleItems($bundleProductId) {
        try {

            $bundledProduct = new Mage_Catalog_Model_Product();
            $bundledProduct->load($bundleProductId);
            $selectionCollection = $bundledProduct->getTypeInstance(true)->getSelectionsCollection(
                    $bundledProduct->getTypeInstance(true)->getOptionsIds($bundledProduct), $bundledProduct
            );
            $bundleItems = array();
            foreach ($selectionCollection as $option) {
                // Assign bundle product items to global array.
                $bundleItems[] = $option->product_id;
            }

            return $bundleItems;
        } catch (Exception $e) {
            Mage::log($e->getMessage());
            return array();
        }
    }

}
