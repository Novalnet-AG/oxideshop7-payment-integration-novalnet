/**
 * Novalnet payment module
 *
 * This file is used for loading Seamless payment form.
 *
 * @author    Novalnet AG
 * @copyright Copyright by Novalnet
 * @link      https://www.novalnet.de
 * @license   https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: novalnet_seamless.js
 */
const allButtons = document.querySelectorAll('button');
let paymentSubmitButton = null;

allButtons.forEach(button => {
    const onclickValue = button.getAttribute('onclick');

    if (onclickValue === "document.querySelector('#payment').requestSubmit();") {
        paymentSubmitButton = button;
    } else if (onclickValue === "document.getElementById('payment').submit();") {
        paymentSubmitButton = button;
    }
});

if (paymentSubmitButton) {
    paymentSubmitButton.setAttribute('id', 'placeorder');
    paymentSubmitButton.removeAttribute('onclick');
}

var submitButton = document.querySelector('#placeorder');
submitButton.style.display = 'none';

window.onload = function() {
    var unchecked = true;
    var paymentMethods = document.querySelectorAll('input[name="paymentid"]');
    if ($("input[name ='paymentid']:checked").val() == 'novalnetpayments') {
        unchecked = false;
        submitButton.style.display = 'none';
    } else {
        submitButton.style.display = 'block';
    }
    const postMessageData = {
        iframe : '#novalnetiframe',
        initForm: {
            orderInformation : {
                lineItems: JSON.parse($('#orderDetails').val()),
                billing: {}
            },
            uncheckPayments: unchecked,
            setWalletPending: true,
            showButton: false,
        },
    };
    const v13PaymentForm = new NovalnetPaymentForm();
    if ($.cookie('oxidNovalnetpayNameCookie') != false && $.cookie('oxidNovalnetpayNameCookie') != undefined && !unchecked ) {
         postMessageData.initForm.checkPayment = $.cookie('oxidNovalnetpayNameCookie');
    }
    // Initiate form
    v13PaymentForm.initiate(postMessageData);
    paymentMethods.forEach((function(payment) {
        if (payment.checked == false && payment.value == 'novalnetpayments' ) {
            v13PaymentForm.uncheckPayment();
        }
        payment.addEventListener('click', (el) => {
            if (el.target.value != 'novalnetpayments') {
                v13PaymentForm.uncheckPayment();
                submitButton.style.display = 'block';
            }
        });
    }));
    // Receive wallet payments like gpay and applepay response
    v13PaymentForm.walletResponse({
        onProcessCompletion: async (response) => {
            if(response.result.status == 'FAILURE' || response.result.status == 'ERROR' ) {
                $('#novalnet_payment_error').val(response.result.message);
                return {status: 'FAILURE', statusText: 'failure'};
            } else {
                $('#novalnet_payment_details').val(JSON.stringify(response));
                $('#payment').submit();
                return {status: 'SUCCESS', statusText: 'successfull'};
            }
        }
    });

    // Receive form selected payment action
    v13PaymentForm.selectedPayment(
        (data)=>{
            submitButton.style.display = 'block';
            if ($("input[name ='paymentid']:checked").val() != 'novalnetpayments') {
                document.querySelector('#payment_novalnetpayments').checked = true;
                $( "#payment_novalnetpayments" ).trigger( "click" );
            }
            $.cookie('oxidNovalnetpayNameCookie', data.payment_details.type);
            if (submitButton != undefined && data.payment_details.type == 'GOOGLEPAY' || data.payment_details.type == 'APPLEPAY')
            {
                submitButton.style.display = 'none';
            } else {
                submitButton.style.display = 'block';
            }
        }
    );

    $("#placeorder").click(function(e){
        if ($("input[name ='paymentid']:checked").val() == 'novalnetpayments') {
            e.preventDefault();
            e.stopImmediatePropagation();
            // Callback for checkout button clicked
            v13PaymentForm.getPayment(
                (data)=>{
                    if(data.result.status == 'ERROR') {
                        $('#novalnet_payment_error').val(data.result.message);
                    }
                        $('#novalnet_payment_details').val(JSON.stringify(data));
                        $('#novalnetiframe').closest('form').submit();
                }
            );
        } else {
            $('#payment').submit();
        }
    });
    

}
