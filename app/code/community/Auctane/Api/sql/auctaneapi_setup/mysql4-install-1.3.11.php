<?php

/* @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;
/* @var $installer Mage_Sales_Model_Mysql4_Setup */

$installer->getConnection()->addColumn($this->getTable('sales/quote'), 'auctaneapi_discounts', 'text default NULL');
$installer->getConnection()->addColumn($this->getTable('sales/order'), 'auctaneapi_discounts', 'text default NULL');

$installer->endSetup();