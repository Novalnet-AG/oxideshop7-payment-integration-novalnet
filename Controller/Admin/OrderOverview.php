<?php
/**
 * Novalnet payment module
 *
 * This file is used for real time processing of transaction.
 *
 * This is free contribution made by request.
 * If you have found this file useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author    Novalnet AG
 * @copyright Copyright by Novalnet
 * @link      https://www.novalnet.de
 * @license   https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: OrderOverview.php
 */

namespace Novalnet\Payment\Controller\Admin;

use Novalnet\Payment\Core\NovalnetUtil;

/**
 * Class OrderOverview.
 */
class OrderOverview extends OrderOverview_parent
{
    
    /**
     * Returns name of template to render
     *
     * @return string
     */
    public function render()
    {
        $sTemplate = parent::render();
        $sOxId = $this->getEditObjectId();
        if (isset($sOxId) && $sOxId != "-1") {
            $oOrder = $this->_aViewData['edit'];
            if ($oOrder->oxorder__oxpaymenttype->value == 'novalnetpayments') {
				$sPaymentName = NovalnetUtil::getTableValues('PAYMENT_TYPE', 'novalnet_transaction_detail', 'ORDER_NO', $oOrder->oxorder__oxordernr->value);
				if (!empty($sPaymentName) && !empty($sPaymentName['PAYMENT_TYPE'])) {
					$this->_aViewData['aNovalnetPayment'] = $sPaymentName['PAYMENT_TYPE'];
				}
			}
        }
        return $sTemplate;
    }

  
}
