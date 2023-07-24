<?php
/**
 * Novalnet payment module
 *
 * This file is used for metadata information of Novalnet payment module.
 *
 * @author    Novalnet AG
 * @copyright Copyright by Novalnet
 * @license   https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: metadata.php
 */

/**
 * Metadata version
 */
$sMetadataVersion = '2.1';

/**
 * Module information
 */
$aModule = [
        'id'          => 'novalnet',
        'title'       => [
            'de' => 'Novalnet',
            'en' => 'Novalnet',
        ],
        'description' => [ 'de' => 'Bevor Sie beginnen, lesen Sie bitte die Installationsanleitung und melden Sie sich mit Ihrem Händlerkonto im <a href ="https://admin.novalnet.de" target="_blank" style="color: #777;text-decoration: none;
    border-bottom: 1px dotted #999;">Novalnet Admin-Portal</a> an. Um ein Händlerkonto zu erhalten, senden Sie bitte eine E-Mail an sales@novalnet.de oder rufen Sie uns unter +49 89 923068320 an' . '<br><br>' . 'Die Konfigurationen der Zahlungsplugins sind jetzt im <a href ="https://admin.novalnet.de" target="_blank" style="color: #777;text-decoration: none;
    border-bottom: 1px dotted #999;">Novalnet Admin-Portal</a> verfügbar. Navigieren Sie zu Konto -> Konfiguration des Shops Ihrer Projekte, um sie zu konfigurieren.' . '<br><br>' . 'Novalnet ermöglicht es Ihnen, das Verhalten der Zahlungsmethode zu überprüfen, bevor Sie in den Produktionsmodus gehen, indem Sie Testzahlungsdaten verwenden. Zugang zu den <a href ="https://developer.novalnet.de/testing" target="_blank" style="color: #777;text-decoration: none;border-bottom: 1px dotted #999;">Novalnet-Testzahlungsdaten</a> finden Sie hier',
                       'en' => 'Please read the Installation Guide before you start and login to the <a href ="https://admin.novalnet.de" target="_blank" style="color: #777;text-decoration: none;border-bottom: 1px dotted #999;">Novalnet Admin Portal</a> using your merchant account. To get a merchant account, mail to sales@novalnet.de or call +49 (089) 923068320' . '<br><br>' . 'Payment plugin configurations are now available in the <a href ="https://admin.novalnet.de" target="_blank" style="color: #777;text-decoration: none;border-bottom: 1px dotted #999;">Novalnet Admin Portal</a>. Navigate to the Account -> Payment plugin configuration of your projects to configure them.' . '<br><br>' . 'Our platform offers a test mode for all requests; You can control the behaviour of the payment methods by using the <a href ="https://developer.novalnet.de/testing" target="_blank" style="color: #777;text-decoration: none;border-bottom: 1px dotted #999;">Novalnet test payment data</a>',
        ],
        'thumbnail'   => 'img/novalnet_logo.png',
        'version'     => '13.0.0',
        'author'      => 'Novalnet AG',
        'url'         => 'https://www.novalnet.de',
        'email'       => 'technic@novalnet.de',
        'extend'      => [
            \OxidEsales\Eshop\Application\Controller\PaymentController::class        => Novalnet\Controller\PaymentController::class,
            \OxidEsales\Eshop\Core\InputValidator::class                             => Novalnet\Core\InputValidator::class,
            \OxidEsales\Eshop\Application\Model\PaymentGateway::class                => Novalnet\Model\PaymentGateway::class,
            \OxidEsales\Eshop\Application\Model\Order::class                         => Novalnet\Model\Order::class,
            \OxidEsales\Eshop\Application\Model\Payment::class                       => Novalnet\Model\Payment::class,
            \OxidEsales\Eshop\Application\Model\UserPayment::class                   => Novalnet\Model\UserPayment::class,
            \OxidEsales\Eshop\Application\Controller\AccountOrderController::class   => Novalnet\Controller\AccountOrderController::class,
            \OxidEsales\Eshop\Application\Controller\OrderController::class          => Novalnet\Controller\OrderController::class,
            \OxidEsales\Eshop\Application\Controller\Admin\OrderOverview::class      => Novalnet\Controller\Admin\OrderOverview::class,
            \OxidEsales\Eshop\Core\ViewConfig::class                                 => Novalnet\Core\ViewConfig::class,
            \OxidEsales\Eshop\Application\Controller\ThankYouController::class       => Novalnet\Controller\NovalnetThankyou::class,

        ],
        'controllers'  => [
            'novalnetconfiguration'         => Novalnet\Controller\Admin\NovalnetConfiguration::class,
            'novalnetcallback'              => Novalnet\Controller\CallbackController::class,
            'novalnet_order'                => Novalnet\Controller\Admin\OrderController::class,
        ],
        'settings'      => [
            // Global configuration settings
            ['group' => 'novalnetGlobalSettings', 'name' => 'sProductActivationKey','type' => 'str',   'value'  => '', 'position' => 1 ],
            ['group' => 'novalnetGlobalSettings', 'name' => 'sPaymentAccessKey',    'type' => 'str',   'value'  => '', 'position' => 2 ],
            ['group' => 'novalnetGlobalSettings','name'  => 'sTariffId',            'type' => 'select','value'  => '', 'position' => 3],
            ['group' => 'novalnetGlobalSettingsWebhook','name'  => 'sWebhooksUrl',         'type' => 'str',    'value' => '',      'position' => 4],
            ['group' => 'novalnetGlobalSettingsWebhook','name'  => 'blWebhookNotification','type' => 'bool',   'value' => 'false', 'position' => 5],
            ['group' => 'novalnetGlobalSettingsWebhook','name'  => 'blWebhookSendMail',    'type' => 'str',    'value' => '',      'position' => 6],
        ],
        'events'    => [
           'onActivate'    => \Novalnet\Core\Events::class.'::onActivate',
           'onDeactivate'  => \Novalnet\Core\Events::class.'::onDeactivate',
        ],
];
