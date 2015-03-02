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

class Auctane_Api_Model_Server_Adapter
	extends Varien_Object
	implements Mage_Api_Model_Server_Adapter_Interface
{
	/**
	 * Set handler class name for webservice
	 * Regular handlers are ignored because this adapter only performs one service.
	 *
	 * @param string $handler
	 * @return Auctane_Api_Model_Server_Adapter
	 */
	public function setHandler($handler)
	{
		$this->setData('handler', $handler);
		return $this;
	}

	/**
	 * Retreive handler class name for webservice
	 *
	 * @return string
	 */
	public function getHandler()
	{
		return $this->getData('handler');
	}

	 /**
	 * Set webservice api controller
	 *
	 * @param Auctane_Api_AuctaneController $controller
	 * @return Auctane_Api_Model_Server_Adapter
	 */
	public function setController(Mage_Api_Controller_Action $controller)
	{
		 $this->setData('controller', $controller);
		 return $this;
	}

	/**
	 * Retrive webservice api controller
	 *
	 * @return Auctane_Api_AuctaneController
	 */
	public function getController()
	{
		return $this->getData('controller');
	}

	/**
	 * Run webservice
	 *
	 * @return Auctane_Api_Model_Server_Adapter
	 */
	public function run()
	{
		// Basic HTTP Authentication is used here, check on every request.
		// Unlike RPC services there is no session
		/* @var $user Mage_Api_Model_User */
		$user = Mage::getModel('api/user');
        
        $sapi_type = php_sapi_name();            
        if (substr($sapi_type, 0, 3) == 'cgi' && isset($_SERVER['HTTP_AUTHORIZATION'])) {            
           list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':' ,   base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
        }
            
		$auth_user = isset($_SERVER['HTTP_SS_AUTH_USER']) ? $_SERVER['HTTP_SS_AUTH_USER'] : @$_SERVER['PHP_AUTH_USER'];
		$auth_password = isset($_SERVER['HTTP_SS_AUTH_PW']) ? $_SERVER['HTTP_SS_AUTH_PW'] : @$_SERVER['PHP_AUTH_PW'];

		if(!$auth_user)
            		$auth_user = $this->getController()->getRequest()->getParam('SS-UserName');

       		 if(!$auth_password)
            		$auth_password = $this->getController()->getRequest()->getParam('SS-Password');

		if (!$user->authenticate($auth_user, $auth_password)) {
			header(sprintf('WWW-Authenticate: Basic realm="%s"', Mage::getStoreConfig('auctaneapi/config/realm')));
			$this->fault(401, 'Unauthorized');
		}

		try { switch ($this->getController()->getRequest()->getParam('action', 'export')) {
			case 'export':
				$action = Mage::getModel('auctaneapi/action_export');
				$action->process($this->getController()->getRequest(),
								$this->getController()->getResponse());
				break;
			case 'shipnotify':
				$action = Mage::getModel('auctaneapi/action_shipnotify');
				$action->process($this->getController()->getRequest());
				// if there hasn't been an error yet the work is done and a "200 OK" is given
				break;
		}}
		catch (Exception $e) {
			$this->fault($e->getCode(), $e->getMessage());
		}

		return $this;
	}

	/**
	 * Dispatch webservice fault
	 *
	 * @param int $code
	 * @param string $message
	 */
	public function fault($code, $message)
	{
		if (is_numeric($code) && strlen((int) $code) === 3) {
			header(sprintf('%s %03d Fault', $_SERVER['SERVER_PROTOCOL'], $code));
		}
		header('Content-Type: text/xml; charset=UTF-8');
		die('<?xml version="1.0" encoding="UTF-8"?>
<fault>
	<faultcode>' . $code . '</faultcode>
	<faultstring>' . $message . '</faultstring>
</fault>
');
	}

}
