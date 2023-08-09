<?php
/**
 * Novalnet payment module
 *
 * This file is used for asynchronuous process
 *
 * @author    Novalnet AG
 * @copyright Copyright by Novalnet
 * @link      https://www.novalnet.de
 * @license   https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: WebhookController.php
 */

namespace Novalnet\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use Novalnet\Core\NovalnetUtil;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Core\Registry;

/**
 * Class WebhookController.
 */
class WebhookController extends FrontendController
{
    protected $_sThisTemplate    = "@novalnet/callback.html.twig";

    /**
     * Allowed host from Novalnet.
     *
     * @var string
     */
    protected $sNovalnetHostName = 'pay-nn.de';

    protected $_aViewData; // View data array

    /**
     * Mandatory Parameters.
     *
     * @var array
     */
    protected $mandatory = [
        'event' => [
            'type',
            'checksum',
            'tid'
        ],
        'result' => [
            'status'
        ],
    ];

    /**
     * Callback test mode.
     *
     * @var int
     */
    protected $blCallbackTestMode;

    /**
     * Request parameters.
     *
     * @var array
     */
    protected $eventData = array();

    /**
     * Your payment access key value
     *
     * @var string
     */
    protected $sPaymentAccesskey;

    /**
     * Order reference values.
     *
     * @var array
     */
    protected $aorderReference = array();

    /**
     * Recived Event type.
     *
     * @var string
     */
    protected $eventType;

    /**
     * Recived Event TID.
     *
     * @var int
     */
    protected $eventTID;

    /**
     * Recived Event parent TID.
     *
     * @var int
     */
    protected $parentTID;

    /**
     * Order Id.
     *
     * @var int
     */
    protected $orderID;

    /**
     * Received amount.
     *
     * @var int
     */
    protected $receivedAmount;

    /**
     * Get Additional Data.
     *
     * @var array
     */
    protected $aAdditionalData;

    /**
     * Form Novalnet Comments.
     *
     * @var array
     */
    protected $aNovalnetComments;

    /**
     * Get database object.
     *
     * @var object
     */
    protected $oDb;

    /**
     * Returns name of template to render
     *
     * @return string
     */
    public function render()
    {
        return $this->_sThisTemplate;
    }

    /**
     * Novalnet_Webhooks constructor.
     */
    public function handleRequest()
    {
        $this->_aViewData['sNovalnetMessage'] = '';
        try {
            $this->eventData = json_decode(file_get_contents('php://input'), true);
        } catch (\Exception $e) {
            $this->displayMessage(['message' => "Received data is not in the JSON format $e"]);
            return false;
        }
        if (empty($this->eventData)) {
            $this->displayMessage(['message' => "Received data is not in the JSON format"]);
            return false;
        }
        file_put_contents('callback_instalment.txt', print_r($this->eventData, true), FILE_APPEND);

        // Backend callback option.
        $this->blCallbackTestMode  = NovalnetUtil::getNovalnetConfigValue('blWebhookNotification'); // Webhook run with test mode or not
        $this->sPaymentAccesskey = NovalnetUtil::getNovalnetConfigValue('sPaymentAccessKey');// Payment access key value
        $this->oDb = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
        // Authenticating the server request based on IP.
        $mRequestReceivedIp = NovalnetUtil::getIpAddress();
        // Host based validation
        if (!empty($this->sNovalnetHostName)) {
            $mNovalnetHostIp  = gethostbyname($this->sNovalnetHostName);
            if (!empty($mNovalnetHostIp) && ! empty($mRequestReceivedIp)) {
                if ($mNovalnetHostIp != $mRequestReceivedIp && !$this->blCallbackTestMode) {
                    $this->displayMessage(['message' => 'Unauthorised access from the IP ' . $mRequestReceivedIp]);
                    return false;
                }
            } else {
                $this->displayMessage(['message' => 'Unauthorised access from the IP']);
                return false;
            }
        } else {
            $this->displayMessage(['message' => 'Unauthorised access from the IP']);
            return false;
        }
        // Validate mandatory parameters
        if(!$this->validateEventData()) {
            return false;
        }
        // Validate checksum
        if(!$this->validateChecksum()) {
            return false;
        }
        if (!empty($this->eventData['custom']['shop_invoked'])) {
            $this->displayMessage([ 'message' => 'Process already handled in the shop.']);
            return false;
        }
        // Set Event data
        $this->eventType = $this->eventData['event']['type'];
        $this->parentTID = !empty($this->eventData['event']['parent_tid']) ? $this->eventData['event']['parent_tid'] : $this->eventData ['event']['tid'];
        $this->eventTID  = $this->eventData['event']['tid'];
        // Get order reference.
        $this->aorderReference = $this->getOrderReference();
        if (empty($this->aorderReference)) {
            return false;
        }
        $this->aAdditionalData = json_decode($this->aorderReference['ADDITIONAL_DATA'], true);
        $this->aNovalnetComments = [];
        $this->orderID = $this->eventData['transaction']['order_no'] ? $this->eventData['transaction']['order_no'] : $this->aorderReference['ORDER_NO'];
        // Format amount as per shop structure
        $this->receivedAmount = NovalnetUtil::formatCurrency($this->eventData['transaction']['amount'], $this->eventData['transaction']['currency']) . ' ' . $this->eventData['transaction']['currency'];

        switch ($this->eventType) {
        case "PAYMENT":
            // Handle initial PAYMENT notification (incl. communication failure, Authorization).
            $this->displayMessage(['message' => "The webhook notification received ($this->eventType) for the TID: $this->eventTID."]);
            break;
        case "TRANSACTION_CAPTURE":
        case "TRANSACTION_CANCEL":
            $this->handleTransactionCaptureCancel();
            break;
        case "TRANSACTION_REFUND":
            $this->handleTransactionRefund();
            break;
        case "TRANSACTION_UPDATE":
            $this->handTransactionUpdate();
            break;
        case "CREDIT":
            $this->handleCredit();
            break;
        case "CHARGEBACK":
            $this->handleChargeback();
            break;
        case "INSTALMENT":
            $this->handleInstalment();
            break;
        case "INSTALMENT_CANCEL":
            $this->handleInstalmentCancel();
            break;
        case "PAYMENT_REMINDER_1":
            $this->handlePaymentRemainterAndCollection();
            break;
        case "PAYMENT_REMINDER_2":
            $this->handlePaymentRemainterAndCollection();
            break;
        case "SUBMISSION_TO_COLLECTION_AGENCY":
            $this->handlePaymentRemainterAndCollection();
            break;
        default:
            $this->displayMessage(['message' => "The webhook notification has been received for the unhandled EVENt type($this->eventType)"]);
        }
    }

    /**
     * Validate server request
     *
     * @return boolean
     */
    protected function validateEventData()
    {
        // Validate required parameter
        foreach ($this->mandatory as $category => $parameters) {
            if (empty($this->eventData[$category])) {
                // Could be a possible manipulation in the notification data
                $this->displayMessage(['message' => "Required parameter category($category) not received" ]);
                return false;
            } elseif (!empty($parameters)) {
                foreach ($parameters as $parameter) {
                    if (empty($this->eventData[$category][$parameter])) {
                        // Could be a possible manipulation in the notification data
                        $this->displayMessage(['message' => "Required parameter($parameter) in the category($category) not received"]);
                        return false;
                    } elseif (in_array($parameter, ['tid'], true) && !NovalnetUtil::validTid($this->eventData[$category][$parameter])) {
                        $this->displayMessage(['message' => "Invalid TID received in the category($category) not received $parameter"]);
                        return false;
                    }
                }
            }
        }
        // Validate TID's from the event data
        if (!NovalnetUtil::validTid($this->eventData['event']['tid'])) {
            $this->displayMessage(['message' => "Invalid event TID: " . $this->eventData ['event']['tid'] . " received for the event(". $this->eventData ['event']['type'] .")"]);
            return false;
        } elseif (!empty($this->eventData['event']['parentTID']) && !NovalnetUtil::validTid($this->eventData['event']['parentTID'])) {
            $this->displayMessage(['message' => "Invalid event TID: " . $this->eventData['event']['parentTID'] . " received for the event(". $this->eventData ['event']['type'] .")"]);
            return false;
        }
        return true;
    }

    /**
     * Handle transaction capture/cancel
     *
     * @return null
     */
    public function handleTransactionCaptureCancel()
    {
        if ($this->aorderReference['GATEWAY_STATUS'] == 'ON_HOLD') {
            $sMessage = ($this->eventType == 'TRANSACTION_CAPTURE') ? 'NOVALNET_STATUS_UPDATE_CONFIRMED_MESSAGE' : 'NOVALNET_STATUS_UPDATE_CANCELED_MESSAGE';
            $oxpaid = '0000-00-00 00:00:00';
            $this->aNovalnetComments[] = [$sMessage => [NovalnetUtil::getFormatDate(), date('H:i:s')]];
            $aUpdateData = [];
            if ($this->eventType == 'TRANSACTION_CAPTURE') {
                $oxpaid = ($this->eventData['transaction']['payment_type'] == 'INVOICE') ? '0000-00-00 00:00:00' : NovalnetUtil::getFormatDateTime();
                if (in_array($this->eventData['transaction']['payment_type'], ['INVOICE', 'GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
                    if ($this->aorderReference['GATEWAY_STATUS'] == 'ON_HOLD') {
                        if (!empty($this->eventData['transaction']['due_date'])) {
                            foreach ($this->aAdditionalData['bank_details'] as $key => $array) {
                                foreach($array as $sLangText => $sLangValue) {
                                    if ($sLangText == 'NOVALNET_INSTALMENT_INVOICE_BANK_DESC') {
                                        $this->aNovalnetComments[] = ['NOVALNET_INSTALMENT_INVOICE_BANK_DESC_WITH_DUE' => [$this->receivedAmount, $this->eventData['transaction']['due_date']]];
                                    } elseif ($sLangText == 'NOVALNET_INVOICE_BANK_DESC') {
                                        $this->aNovalnetComments[] = ['NOVALNET_INVOICE_BANK_DESC_WITH_DUE' => [$this->receivedAmount, $this->eventData['transaction']['due_date']]];
                                    } else {
                                        $this->aNovalnetComments[] = [$sLangText => $sLangValue];
                                    }
                                }
                            }
                        }
                    }
                }
                if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                    $this->aAdditionalData['instalment_comments'] = NovalnetUtil::formInstalmentData($this->eventData, $this->aorderReference['AMOUNT']);
                }
                if ($this->eventData['transaction']['payment_type'] == 'INVOICE') {
                    $iCreditedAmount = 0;
                } else {
                    $iCreditedAmount = $this->eventData['transaction']['amount'];
                }
                $aUpdateData['CREDITED_AMOUNT'] = $iCreditedAmount;
            }
            $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
            NovalnetUtil::updateTableValues('oxorder', ['OXPAID' => $oxpaid], 'OXORDERNR', $this->orderID);
            $aUpdateData['ADDITIONAL_DATA'] = json_encode($this->aAdditionalData);
            $aUpdateData['GATEWAY_STATUS'] = $this->eventData['transaction']['status'];
            NovalnetUtil::updateTableValues('novalnet_transaction_detail', $aUpdateData, 'ORDER_NO', $this->orderID);
            $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
            $this->sendNotifyMail($sComments);
            $this->displayMessage(['message' => $sComments]);
        } else {
            $this->displayMessage(['message' => 'Payment type ('.$this->eventData['transaction']['payment_type'].') is not applicable for this process!']);
        }
    }

    /**
     * Handle transaction refund
     *
     * @return null
     */
    public function handleTransactionRefund()
    {
        if ($this->aorderReference['GATEWAY_STATUS'] == 'CONFIRMED' && !empty($this->eventData['transaction']['refund']['amount']) && $this->aorderReference['CREDITED_AMOUNT'] > 0) {
            $dRefundAmount = NovalnetUtil::formatCurrency($this->eventData['transaction']['refund']['amount'], $this->eventData['transaction']['currency']) . ' ' . $this->eventData['transaction']['currency'];
            if (isset($this->eventData['transaction']['refund']['tid']) && !empty($this->eventData['transaction']['refund']['tid'])) {
                $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_REFUND_TID_TEXT' => [$this->eventData['event']['parent_tid'], $dRefundAmount, $this->eventData['event']['tid']]];
            } else {
                $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_REFUND_TEXT' => [$this->eventData['transaction']['tid'], $dRefundAmount]];
            }
            if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $this->aAdditionalData['instalment_comments'] = $this->updateInstalmentData();
            }
            $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
            $iCreditedAmount = $this->aorderReference['CREDITED_AMOUNT'] - $this->eventData['transaction']['refund']['amount'];
            NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['CREDITED_AMOUNT' => $iCreditedAmount, 'ADDITIONAL_DATA' => json_encode($this->aAdditionalData)], 'ORDER_NO', $this->orderID);
            $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
            $this->sendNotifyMail($sComments);
            $this->displayMessage(['message' => $sComments]);
        } else {
            $this->displayMessage(['message' => 'Order already refunded with full amount']);
        }
    }

    /**
     * Handle transaction update
     *
     * @return null
     */
    public function handTransactionUpdate()
    {
        $aUpdateData = [];
        $aInvoiceDetails = [];
        if ($this->eventData['transaction']['update_type'] == 'STATUS') {
            if (in_array($this->eventData['transaction']['status'], ['PENDING', 'ON_HOLD', 'CONFIRMED', 'DEACTIVATED'])) {
                if ($this->eventData['transaction']['status'] == 'DEACTIVATED') {
                    $oxpaid    = '0000-00-00 00:00:00';
                    $this->aNovalnetComments[] = ['NOVALNET_STATUS_UPDATE_CANCELED_MESSAGE' => [NovalnetUtil::getFormatDate(), date('H:i:s')]];
                    NovalnetUtil::updateTableValues('oxorder', ['OXPAID' => $oxpaid], 'OXORDERNR', $this->orderID);
                } else {
                    if ($this->eventData['transaction']['status'] == 'ON_HOLD') {
                        $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_UPDATE_STATUS_ONHOLD' => [$this->eventData['transaction']['tid'], NovalnetUtil::getFormatDateTime()]];
                        if (in_array($this->eventData['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
                            $aInvoiceDetails = NovalnetUtil::getInvoiceComments($this->eventData, $this->orderID);
                            $this->aAdditionalData['bank_details'] = $aInvoiceDetails;
                        }
                    } elseif ($this->eventData['transaction']['status'] == 'CONFIRMED') {
                        $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_UPDATE_STATUS_UPDATE' => [$this->eventData['transaction']['tid'], NovalnetUtil::getFormatDate()]];
                        if (in_array($this->eventData['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
                            $aInvoiceDetails = NovalnetUtil::getInvoiceComments($this->eventData, $this->orderID);
                            $this->aAdditionalData['bank_details'] = $aInvoiceDetails;
                        }
                        if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                            $this->aAdditionalData['instalment_comments'] = NovalnetUtil::formInstalmentData($this->eventData, $this->aorderReference['AMOUNT']);
                        }
                        if ($this->eventData['transaction']['payment_type'] != 'INVOICE') {
                            $aUpdateData = ['CREDITED_AMOUNT' => $this->eventData['transaction']['amount']];
                            NovalnetUtil::updateTableValues('oxorder', ['OXPAID' => NovalnetUtil::getFormatDateTime()], 'OXORDERNR', $this->orderID);
                        }
                    }
                }
                $aUpdateData['GATEWAY_STATUS'] = $this->eventData['transaction']['status'];
            }
        } elseif (in_array($this->eventData['transaction']['update_type'], ['DUE_DATE', 'AMOUNT_DUE_DATE'])) {
            $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_UPDATE_DUEDATE' => [$this->eventData['transaction']['tid'], $this->receivedAmount, $this->eventData['transaction']['due_date']]];
            $aUpdateData = ['AMOUNT' => $this->eventData['transaction']['amount']];
        } elseif ($this->eventData['transaction']['update_type'] == 'AMOUNT') {
            $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_UPDATE_AMOUNT' => [$this->eventData['transaction']['tid'], $this->receivedAmount]];
            $aUpdateData = ['AMOUNT' => $this->eventData['transaction']['amount']];
        }
        $aComments = array_merge($this->aNovalnetComments, $aInvoiceDetails);
        $this->aAdditionalData['novalnet_comments'][] = $aComments;
        $aUpdateData['ADDITIONAL_DATA'] = json_encode($this->aAdditionalData);
        NovalnetUtil::updateTableValues('novalnet_transaction_detail', $aUpdateData, 'ORDER_NO', $this->orderID);
        $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
        if (!empty($sComments)) {
            $this->sendNotifyMail($sComments);
            $this->displayMessage(['message' => $sComments]);
        }
    }

     /**
      * Handle transaction credit
      *
      * @return null
      */
    public function handleCredit()
    {
        $dTotalAmount = $this->aorderReference['CREDITED_AMOUNT'] + $this->eventData['transaction']['amount'];
        if ($dTotalAmount >= $this->aorderReference['AMOUNT']) {
            NovalnetUtil::updateTableValues('oxorder', ['OXPAID' => NovalnetUtil::getFormatDateTime()], 'OXORDERNR', $this->orderID);
        }
        $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_CREDIT' => [$this->eventData['event']['parent_tid'], $this->receivedAmount, NovalnetUtil::getFormatDate(), $this->eventData['transaction']['tid']]];
        $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
        NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['GATEWAY_STATUS' => $this->eventData['transaction']['status'], 'CREDITED_AMOUNT' => $dTotalAmount, 'ADDITIONAL_DATA' => json_encode($this->aAdditionalData)], 'ORDER_NO', $this->orderID);
        $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
        if (!empty($sComments)) {
            $this->sendNotifyMail($sComments);
            $this->displayMessage(['message' => $sComments]);
        }
    }

    /**
     * Handle chargeback/ return debit
     *
     * @return null
     */
    public function handleChargeback()
    {
        if ($this->aorderReference['GATEWAY_STATUS'] == 'CONFIRMED' && $this->aorderReference['AMOUNT'] != 0 && !empty($this->eventData['transaction']['amount'])) {
            $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_CHARGEBACK' => [$this->eventData['event']['parent_tid'], $this->receivedAmount, NovalnetUtil::getFormatDate(), $this->eventData['event']['tid']]];
            $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
            $iCreditedAmount = $this->aorderReference['CREDITED_AMOUNT'] - $this->eventData['transaction']['amount'];
            NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['CREDITED_AMOUNT' => $iCreditedAmount, 'ADDITIONAL_DATA' => json_encode($this->aAdditionalData)], 'ORDER_NO', $this->orderID);
            $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
            $this->sendNotifyMail($sComments);
            $this->displayMessage(['message' => $sComments]);
        }
    }

    /**
     * Handle instalment
     *
     * @return null
     */
    public function handleInstalment()
    {
        if ('CONFIRMED' == $this->aorderReference['GATEWAY_STATUS'] && $this->eventData['instalment']['cycles_executed'] != '0') {
            // Check the total instalment cycle
            $total_cycle = $this->aAdditionalData['instalment_comments'];
            if ($this->eventData['instalment']['cycles_executed'] <= $total_cycle['instalment_total_cycles']) {
                $dInstalmentAmount = NovalnetUtil::formatCurrency($this->eventData['instalment']['cycle_amount'], $this->eventData['transaction']['currency']) . ' ' . $this->eventData['transaction']['currency'];
                $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_INSTALMENT_MESSAGE' => [$this->eventData['event']['parent_tid'], $dInstalmentAmount, $this->eventData['transaction']['tid']]];
                $this->aAdditionalData['instalment_comments'] = $this->storeInstalmentData();
                $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
                $iCreditedAmount = $this->aorderReference['CREDITED_AMOUNT'] + $this->eventData['instalment']['cycle_amount'];
                NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['CREDITED_AMOUNT' => $iCreditedAmount, 'ADDITIONAL_DATA' => json_encode($this->aAdditionalData)], 'ORDER_NO', $this->orderID);
                $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
                $this->sendNotifyMail($sComments);
                $this->displayMessage(['message' => $sComments]);
            } else {
                $this->displayMessage(['message' => 'Instalment cycle already completed..!']);
            }
        }
    }

    /**
     * Handle instalment cancel
     *
     * @return null
     */
    public function handleInstalmentCancel()
    {
        if ($this->eventData['transaction']['status'] == 'CONFIRMED') {
            $this->aNovalnetComments[] = ['NOVALNET_CALLBACK_INSTALMENT_CANCEL_MESSAGE' => [$this->eventData['transaction']['tid'], NovalnetUtil::getFormatDate()]];
            $oxpaid = '0000-00-00 00:00:00';
            $aInstalmentDetails = $this->aAdditionalData['instalment_comments'];
            for ($dcycle = 1; $dcycle <= $aInstalmentDetails['instalment_total_cycles']; $dcycle++) {
                if (!isset($aInstalmentDetails['instalment' . $dcycle]['tid'])) {
                    $aInstalmentDetails['instalment' . $dcycle]['status'] = 'NOVALNET_INSTALMENT_STATUS_CANCELLED';
                } else {
                    $aInstalmentDetails['instalment' . $dcycle]['status'] = 'NOVALNET_INSTALMENT_STATUS_REFUNDED';
                    $aInstalmentDetails['instalment' . $dcycle]['amount'] = NovalnetUtil::formatCurrency(($aInstalmentDetails['instalment' . $dcycle]['amount'] - $aInstalmentDetails['instalment' . $dcycle]['amount']), $this->eventData['transaction']['currency']);
                }
            }
            $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
            $this->aAdditionalData['instalment_comments'] = $aInstalmentDetails;
            NovalnetUtil::updateTableValues('oxorder', ['OXPAID' => $oxpaid], 'OXORDERNR', $this->orderID);
            NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['ADDITIONAL_DATA' => json_encode($this->aAdditionalData), 'GATEWAY_STATUS' => $this->eventData['transaction']['status'], 'AMOUNT' => 0], 'ORDER_NO', $this->orderID);
            $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
            $this->sendNotifyMail($sComments);
            $this->displayMessage(['message' => $sComments]);
        }
    }
    /**
     * Handle payment remainters and collections
     *
     * @return null
     */
    public function handlePaymentRemainterAndCollection()
    {
        if ($this->eventType == 'SUBMISSION_TO_COLLECTION_AGENCY') {
            $this->aNovalnetComments[] = ['NOVALNET_COLLECTION_AGENCY_MESSAGE' => [$this->eventData['collection']['reference']]];
        } else {
            $this->aNovalnetComments[] = ($this->eventType == 'PAYMENT_REMINDER_1') ? ['NOVALNET_PAYMENT_REMAINTER1_MESSAGE' => [null]] : ['NOVALNET_PAYMENT_REMAINTER2_MESSAGE' => [null]];
        }
        $this->aAdditionalData['novalnet_comments'][] = $this->aNovalnetComments;
        NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['ADDITIONAL_DATA' => json_encode($this->aAdditionalData)], 'ORDER_NO', $this->orderID);
        $sComments = NovalnetUtil::getTranslateComments($this->aNovalnetComments, $this->aorderReference['LANG']);
        $this->sendNotifyMail($sComments);
        $this->displayMessage(['message' => $sComments]);
    }

    /**
     * Form instalment comment
     *
     * @return array $aInstalmentDetails
     */
    public function storeInstalmentData()
    {
        $aInstalmentDetails = $this->aAdditionalData['instalment_comments'];
        if (!empty($aInstalmentDetails)) {
            $iCyclesExecuted = $this->eventData['instalment']['cycles_executed'];
            $aNextInstalment['tid'] = $this->eventData['transaction']['tid'];
            $aNextInstalment['amount'] = NovalnetUtil::formatCurrency($this->eventData['instalment']['cycle_amount'], $this->eventData['transaction']['currency']);
            $aNextInstalment['paid_amount'] = NovalnetUtil::formatCurrency($this->eventData['instalment']['cycle_amount'], $this->eventData['transaction']['currency']);
            $aNextInstalment['paid_date'] = date('Y-m-d', strtotime(NovalnetUtil::getFormatDateTime()));
            $aNextInstalment['status'] = 'NOVALNET_INSTALMENT_STATUS_COMPLETED';
            $aNextInstalment['next_instalment_date'] = date('Y-m-d', strtotime($this->eventData['instalment']['next_cycle_date']));
            $aNextInstalment['instalment_cycles_executed'] = $this->eventData['instalment']['cycles_executed'];
            $aNextInstalment['due_instalment_cycles'] = $this->eventData['instalment']['pending_cycles'];
            $aInstalmentDetails['instalment' . $iCyclesExecuted] = $aNextInstalment;
        }
        return $aInstalmentDetails;
    }

    /**
     * Update instalment comment
     *
     * @return array $aInstalmentDetails
     */
    public function updateInstalmentData()
    {
        $aInstalmentDetails = $this->aAdditionalData['instalment_comments'];
        for ($dCycleCount = 1; $dCycleCount <= $aInstalmentDetails['instalment_total_cycles']; $dCycleCount++) {
            if (isset($aInstalmentDetails['instalment' . $dCycleCount]['tid']) && $aInstalmentDetails['instalment' . $dCycleCount]['tid'] == $this->parentTID) {
                $iAmount = $this->eventData['transaction']['amount'] - $this->eventData['transaction']['refunded_amount'];
                if ($aInstalmentDetails['instalment' . $dCycleCount]['amount'] == NovalnetUtil::formatCurrency($this->eventData['transaction']['refund']['amount'], $this->eventData['transaction']['currency'])) {
                    $aInstalmentDetails['instalment' . $dCycleCount]['status'] = 'NOVALNET_INSTALMENT_STATUS_REFUNDED';
                }
                $aInstalmentDetails['instalment' . $dCycleCount]['amount'] = NovalnetUtil::formatCurrency($iAmount, $this->eventData['transaction']['currency']);
            }
        }
        return $aInstalmentDetails;
    }

    /**
     * Validate checksum
     *
     * @return boolean
     */
    protected function validateChecksum()
    {
        $mxTokenString  = $this->eventData['event']['tid'] . $this->eventData['event']['type'] . $this->eventData['result']['status'];
        if (isset($this->eventData['transaction']['amount'])) {
            $mxTokenString .= $this->eventData['transaction']['amount'];
        }
        if (isset($this->eventData['transaction']['currency'])) {
            $mxTokenString .= $this->eventData['transaction']['currency'];
        }
        if (!empty($this->sPaymentAccesskey)) {
            $mxTokenString .= strrev($this->sPaymentAccesskey);
        }
        $mxGeneratedChecksum = hash('sha256', $mxTokenString);
        if ($mxGeneratedChecksum != $this->eventData['event']['checksum']) {
            $this->displayMessage(['message' => "While notifying some data has been changed. The hash check failed"]);
            return false;
        }
        return true;
    }

    /**
     * Get order reference.
     *
     * @return array
     */
    protected function getOrderReference()
    {
        $aDbValue = NovalnetUtil::getTableValues('*', 'novalnet_transaction_detail', 'TID', $this->parentTID);

        $sOrderNo = (isset($aDbValue['ORDER_NO']) &&  !empty($aDbValue['ORDER_NO']))? $aDbValue['ORDER_NO'] : $this->eventData['transaction']['order_no'];
        $aResult = NovalnetUtil::getTableValues('OXPAYMENTTYPE, OXLANG', 'oxorder', 'OXORDERNR', $sOrderNo);
        if (empty($aResult['OXPAYMENTTYPE']) || strpos($aResult['OXPAYMENTTYPE'], 'novalnet')) {
            return false;
        }
        //Update old txn details to New format
        if (!empty($aDbValue) && $aResult['OXPAYMENTTYPE'] != 'novalnetpayments') {
            $aAdditionalData = unserialize($aDbValue['ADDITIONAL_DATA']);
            if(empty($aAdditionalData)) {
                $aAdditionalData = json_decode($aDbValue['ADDITIONAL_DATA'], true);
            }
            if (!isset($aAdditionalData['updated_old_txn_details']) && $aAdditionalData['updated_old_txn_details'] != true) {
                NovalnetUtil::convertOldTxnDetailsToNewFormat($this->eventData['transaction']['order_no']);
                $aDbValue = NovalnetUtil::getTableValues('*', 'novalnet_transaction_detail', 'TID', $this->parentTID);
            }
        }
        if (empty($aDbValue)) {
            if ($this->eventData['event']['type'] == 'PAYMENT' || $this->eventData['transaction']['payment_type'] == 'ONLINE_TRANSFER_CREDIT') {
                if (! empty($this->parentTID)) {
                    $this->eventData['transaction']['tid'] = $this->parentTID;
                }
                // Handle communication failure
                $this->handleCommunicationFailure();
                return false;

            } else {
                $this->displayMessage(['message' => 'event type mismatched for TID' . $this->parentTID]);
                return false;
            }
        }
        $aDbValue['LANG'] = $aResult['OXLANG'];
        if (!empty($this->eventData['transaction']['order_no']) && ($sOrderNo != $this->eventData['transaction']['order_no'])) {
            $this->displayMessage(['message' => 'Transaction mapping failed']);
            return false;
        }
        return $aDbValue;
    }

    /**
     * Display callback messages
     *
     * @param $sMessage
     *
     * @return null
     */
    protected function displayMessage($sMessage)
    {
        $this->_aViewData['sNovalnetMessage'] = $sMessage['message'];
    }

    /**
     * Send notification mail to the merchant
     *
     * @param array $data
     *
     * @return null
     */
    protected function sendNotifyMail($data)
    {
        $blCallbackMail = NovalnetUtil::getNovalnetConfigValue('blWebhookSendMail');
        if (!empty($blCallbackMail)) {
            $oMail = oxNew(\OxidEsales\Eshop\Core\Email::class);
            $sEmailSubject = 'Novalnet Callback Script Access Report';
            $oShop = $oMail->getShop();
            $oMail->setFrom($oShop->oxshops__oxorderemail->value);
            $oMail->setSubject($sEmailSubject);
            $oMail->setBody($data);
            $oMail->setRecipient($blCallbackMail);
            if ($oMail->send()) {
                return 'Mail sent successfully<br>';
            }
        }
    }

    /**
     * Handle communication failure
     *
     * @return boolean
     */
    protected function handleCommunicationFailure()
    {
        // Get shop details
        $aPaymentDetails = NovalnetUtil::getTableValues('OXID, OXPAYMENTTYPE, OXLANG, OXUSERID, OXPAID', 'oxorder', 'OXORDERNR', $this->eventData['transaction']['order_no']);
        $sWord   = 'novalnet';
        if (!empty($aPaymentDetails['OXPAYMENTTYPE']) && strpos($aPaymentDetails['OXPAYMENTTYPE'], $sWord) !== false) {
            $aOrderData = NovalnetUtil::getTableValues('*', 'novalnet_transaction_detail', 'ORDER_NO', $this->eventData['transaction']['order_no']);
            $bTestMode = ($this->eventData['transaction']['test_mode']);
            // Form transaction comments
            $aNovalnetComments = $this->formPaymentComments($bTestMode);
            $aAdditionalData['novalnet_comments'][] = $aNovalnetComments;
            if (in_array($this->eventData['transaction']['status'], ['ON_HOLD','PENDING', 'CONFIRMED'])) {
                if (in_array($this->eventData['transaction']['status'], ['ON_HOLD','PENDING'])) {
                    $iCredited = 0;
                } else {
                    $iCredited = $this->eventData['transaction']['amount'];
                }
                if (empty($aOrderData) && empty($aOrderData['ORDER_NO'])) {
                    $sPayment = NovalnetUtil::getNovalnetPaymentName($aPaymentDetails['OXPAYMENTTYPE']);
                    $aAdditionalData['updated_old_txn_details'] = true;
                    $this->oDb->execute('INSERT INTO novalnet_transaction_detail (ORDER_NO, PAYMENT_TYPE, TID, AMOUNT, GATEWAY_STATUS, CREDITED_AMOUNT, ADDITIONAL_DATA) VALUES (?, ?, ?, ?, ? ,? ,?)', [$this->eventData['transaction']['order_no'], $sPayment, $this->parentTID, $this->eventData['transaction']['amount'], $this->eventData['transaction']['status'], $iCredited, json_encode($aAdditionalData)]);
                } else {
                    NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['TID' => $this->parentTID, 'AMOUNT' => $this->eventData['transaction']['amount'], 'GATEWAY_STATUS' => $this->eventData['transaction']['status'],  'CREDITED_AMOUNT' => $iCredited, 'ADDITIONAL_DATA' => json_encode($aAdditionalData)],  'ORDER_NO', $this->eventData['transaction']['order_no']);
                }
                 // Set empty paid date for pending transaction status
                if(in_array($this->eventData['transaction']['payment_type'], array('PAYPAL', 'PRZELEWY24', 'INVOICE', 'PREPAYMENT', 'CASHPAYMENT', 'MULTIBANCO'))
                    && $this->eventData['transaction']['status'] == 'PENDING'
                ) {
                    $sOrderStatus = '0000-00-00 00:00:00';
                } else { // Set paid date
                    $sOrderStatus = NovalnetUtil::getFormatDateTime();
                }
                $sComments = NovalnetUtil::getTranslateComments($aNovalnetComments, $this->aorderReference['LANG']);
                NovalnetUtil::updateTableValues('oxorder', ['OXPAID' => $sOrderStatus, 'OXFOLDER' => 'ORDERFOLDER_NEW', 'OXTRANSSTATUS' => 'OK'], 'OXORDERNR', $this->eventData['transaction']['order_no']);
                $this->sendNotifyMail($sComments);
                $this->displayMessage(['message' => 'Novalnet Callback Script executed successfully, Transaction details are updated']);
                return false;
            } elseif ($aPaymentDetails['OXPAID'] == '0000-00-00 00:00:00') {
                NovalnetUtil::updateArticleStockFailureOrder($this->eventData['transaction']['order_no']);
                if (empty($aOrderData) && empty($aOrderData['ORDER_NO'])) {
                    $sPayment = NovalnetUtil::getNovalnetPaymentName($aPaymentDetails['OXPAYMENTTYPE']);
                    $aAdditionalData['updated_old_txn_details'] = true;
                    $this->oDb->execute('INSERT INTO novalnet_transaction_detail (ORDER_NO, PAYMENT_TYPE, TID, AMOUNT, GATEWAY_STATUS, CREDITED_AMOUNT, ADDITIONAL_DATA) VALUES (?, ?, ?, ?, ? ,? ,?)', [$this->eventData['transaction']['order_no'], $sPayment, $this->parentTID, $this->eventData['transaction']['amount'], $this->eventData['transaction']['status'], 0, json_encode($aAdditionalData)]);
                } else {
                    NovalnetUtil::updateTableValues('novalnet_transaction_detail', ['TID' => $this->parentTID, 'AMOUNT' => $this->eventData['transaction']['amount'], 'GATEWAY_STATUS' => $this->eventData['transaction']['status'], 'ADDITIONAL_DATA' => json_encode($aAdditionalData)],  'ORDER_NO', $this->eventData['transaction']['order_no']);
                }
                NovalnetUtil::updateTableValues('oxorder', ['OXFOLDER' => 'ORDER_STATE_PAYMENTERROR'], 'OXORDERNR', $this->eventData['transaction']['order_no']);
                $this->displayMessage(['message' => 'Novalnet Callback Script executed successfully, Order no: '. $this->eventData['transaction']['order_no']]);
                return false;
            }
        }
    }

    /**
     * Form transaction details
     *
     * @param integer $bTestMode
     * @param string  $sLang
     *
     * @return string
     */
    public function formPaymentComments($iTestMode)
    {
        $this->aNovalnetComments = [];
        $this->aNovalnetComments[] = ['NOVALNET_TRANSACTION_ID' => [$this->eventData['transaction']['tid']]];
        if(!empty($iTestMode)) {
            $this->aNovalnetComments[] = ['NOVALNET_TEST_ORDER' => [null]];
        }
        if (!empty($this->eventData['transaction']['status_code']) && !in_array($this->eventData['transaction']['status_code'], array(75, 85, 86, 90, 91, 98, 99, 100))) { // Failure transaction
            $this->aNovalnetComments[] = ['NOVALNET_PAYMENT_FAILED' => [$this->eventData['result']['status_text']]];
        }
        return $this->aNovalnetComments;
    }
}
