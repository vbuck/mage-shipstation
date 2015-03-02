<?php

class Auctane_Api_Model_Observer
{
    protected static $_counter = 0;
    
    protected static $_cartRules = array();
    
    protected static $_shippingAmountProcessed = array();
    
    /**
     * Calculate discounts by sales rules
     * @param Varien_Event_Observer $observer
     */    
    public function salesruleProcess($observer)
    {                
        $quote = $observer->getQuote();
        $address = $observer->getAddress();
        $rule = $observer->getRule();
                
        $discounts = @unserialize($quote->getAuctaneapiDiscounts());

        if (!self::$_counter) {
            $discounts = array();
            $address->setBaseShippingDiscountAmount(0);
            self::$_counter++;
        } 

        if (!isset(self::$_shippingAmountProcessed[$rule->getId()]) &&  $address->getShippingAmount()) {
            $shippingAmount = $address->getShippingAmountForDiscount();
            if ($shippingAmount !== null) {
                $baseShippingAmount = $address->getBaseShippingAmountForDiscount();
            } else {
                $baseShippingAmount = $address->getBaseShippingAmount();
            }

            //check for discount applied on shipping amount or not
            if(!$rule['apply_to_shipping'])
                $baseShippingAmount = 0;


            $baseDiscountAmount = 0;
            $rulePercent = min(100, $rule->getDiscountAmount());
            switch ($rule->getSimpleAction()) {
                case Mage_SalesRule_Model_Rule::TO_PERCENT_ACTION:
                    $rulePercent = max(0, 100 - $rule->getDiscountAmount());
                case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
                    $baseDiscountAmount = ($baseShippingAmount -
                            $address->getBaseShippingDiscountAmount()) * $rulePercent / 100;
                    break;
                case Mage_SalesRule_Model_Rule::TO_FIXED_ACTION:
                    $baseDiscountAmount = $baseShippingAmount - $rule->getDiscountAmount();
                    break;
                case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
                    $baseDiscountAmount = $rule->getDiscountAmount();
                    break;
                case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
                    self::$_cartRules = $address->getCartFixedRules();
                    if (!isset(self::$_cartRules[$rule->getId()])) {
                        self::$_cartRules[$rule->getId()] = $rule->getDiscountAmount();
                    }
                    if (self::$_cartRules[$rule->getId()] > 0) {
                        $baseDiscountAmount = min(
                                $baseShippingAmount - $address->getBaseShippingDiscountAmount(), self::$_cartRules[$rule->getId()]
                        );
                        self::$_cartRules[$rule->getId()] -= $baseDiscountAmount;
                    }
                    break;
            }
            
            $ruleDiscount = 0;                
            $left = $baseShippingAmount - ($address->getBaseShippingDiscountAmount() + $baseDiscountAmount);                
            if ($left >= 0)
                $ruleDiscount = $baseDiscountAmount;
                
            $discounts[$rule->getId() . '-' . $observer->getItem()->getId() . '-' . uniqid()] =
                    $observer->getResult()->getBaseDiscountAmount() + $ruleDiscount;

            $address->setBaseShippingDiscountAmount(min(
                   $address->getBaseShippingDiscountAmount() + $baseDiscountAmount, 
                    $baseShippingAmount
            ));
            
            self::$_shippingAmountProcessed[$rule->getId()] = true;
        }
        else {
              $discounts[$rule->getId() . '-' . $observer->getItem()->getId() . '-' . uniqid()] =
                    $observer->getResult()->getBaseDiscountAmount();
        }
                
        $quote->setAuctaneapiDiscounts(@serialize($discounts));
    }
    
    /**
     * Export quote discounts info to order
     * @param Varien_Event_Observer $observer
     */    
    public function salesConvertQuoteToOrder($observer)
    {
        $observer->getOrder()->setAuctaneapiDiscounts($observer->getQuote()->getAuctaneapiDiscounts());
    }  
}