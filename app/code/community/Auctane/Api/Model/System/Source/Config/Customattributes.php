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
 * @package	Auctane_Api
 * @license	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Auctane_Api_Model_System_Source_Config_Customattributes
{

	public function toOptionArray()
	{
		$type = Mage::getModel('eav/entity_type');
		$type->loadByCode('catalog_product');

		$attrs = Mage::getResourceModel('eav/entity_attribute_collection');
		$attrs->setEntityTypeFilter($type);
		$attrs->addFieldToFilter('is_user_defined', true);

		$array = array();
		/* @var $attr Mage_Eav_Model_Entity_Attribute */
		foreach ($attrs as $id => $attr) {
			$array[$id] = array(
				'value'	=> $attr->getId(),
				'label'	=> $attr->getFrontendLabel()
			);
		}
		return $array;
	}

}
