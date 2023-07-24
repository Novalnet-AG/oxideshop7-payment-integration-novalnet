<?php
/**
 * Novalnet payment module
 *
 * This file is used for receiving Novalnet response for
 * the redirect payments.
 *
 * @author    Novalnet AG
 * @copyright Copyright by Novalnet
 * @link      https://www.novalnet.de
 * @license   https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: OrderController.php
 */

namespace Novalnet\Controller;
use OxidEsales\Eshop\Core\Registry;

/**
 * Class OrderController.
 */
class OrderController extends OrderController_parent
{

    /**
     * Receives Novalnet response for redirect payment
     *
     * @return boolean
     */
    public function novalnetGatewayReturn()
    {
        $oConfig = Registry::getConfig();
        if ($oConfig->getConfigParam('blConfirmAGB') && !Registry::getRequest()->getRequestEscapedParameter('ord_agb')) {
           $_POST['ord_agb'] = 1;
        }

        if ($oConfig->getConfigParam('blEnableIntangibleProdAgreement')) {
            if (!Registry::getRequest()->getRequestEscapedParameter('oxdownloadableproductsagreement')) {
                $_POST['oxdownloadableproductsagreement'] = 1;
            }

            if (!Registry::getRequest()->getRequestEscapedParameter('oxserviceproductsagreement')) {
                $_POST['oxserviceproductsagreement'] = 1;
            }
        }

        Registry::getLang()->setBaseLanguage(Registry::getRequest()->getRequestEscapedParameter('shop_lang'));
        return $this->execute();
    }
}
