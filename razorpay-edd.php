<?php
/*
Plugin Name: Razorpay for Easy Digital Downloads
Description: Razorpay gateway for Easy Digital Downloads
Version: 2.0.0
Stable tag: 2.0.0
Author: Team Razorpay
Author URI: http://razorpay.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) exit;

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

const RAZORPAY_PAYMENT_ID   = 'razorpay_payment_id';
const RAZORPAY_ORDER_ID     = 'razorpay_order_id';
const RAZORPAY_SIGNATURE    = 'razorpay_signature';

const CAPTURE            = 'capture';
const AUTHORIZE          = 'authorize';
const EDD_ORDER_ID       = 'edd_order_id';


// registers the gateway
function razorpay_register_gateway($gateways)
{
    $gateways['razorpay'] = array(
        'admin_label'    => 'Razorpay',
        'checkout_label' => __('Razorpay', 'Razorpay')
    );

    return $gateways;
}

add_filter('edd_payment_gateways', 'razorpay_register_gateway');
add_action('admin_notices', 'razorpay_admin_notices');

add_action('edd_razorpay_cc_form', '__return_false');

function razorpay_admin_notices()
{
    if (! is_plugin_active('easy-digital-downloads/easy-digital-downloads.php')) {
        $message = '<b>' . __('Easy Digital Downloads Payment Gateway by Razorpay', 'edd-razorpay-gateway') . '</b> ' . __('add-on requires', 'edd-razorpay-gateway') . ' ' . '<a href="https://easydigitaldownloads.com" target="_new">' . __('Easy Digital Downloads', 'edd-razorpay-gateway') . '</a>' . ' ' . __('plugin. Please install and activate it.', 'edd-razorpay-gateway');
    }
    elseif (! function_exists('curl_init')) {
        $message = '<b>' . __('Easy Digital Downloads Payment Gateway by Razorpay', 'edd-razorpay-gateway') . '</b> ' . __('requires ', 'edd-razorpay-gateway') . __('PHP CURL.', 'edd-razorpay-gateway') . '</a>' . ' ' . __(' Please install/enable php_curl!', 'edd-razorpay-gateway');
    }

    echo isset($message) ? '<div id="notice" class="error"><p>' . $message.  '</p></div>' : '';
}

function razorpay_get_redirect_response()
{
    $redirect_response = $_POST;

    if (isset($redirect_response['gateway']) && $redirect_response['gateway'] === 'razorpay_gateway' && isset($redirect_response['merchant_order_id']))
    {
        razorpay_check_response($redirect_response, $redirect_response['merchant_order_id']);
    }
    else
    {
        return;
    }
}

add_action('init', 'razorpay_get_redirect_response');

function razorpay_check_response($response, $order_no)
{
    $success = false;
    $error_message = 'Payment failed. Please try again.';

    if ($order_no  and !empty($response[RAZORPAY_PAYMENT_ID]))
    {
        $error = "";
        $success = false;

        try
        {
            verifySignature($order_no, $response);
            $success = true;
            $razorpayPaymentId = sanitize_text_field($response[RAZORPAY_PAYMENT_ID]);
        }
        catch (Errors\SignatureVerificationError $e)
        {
            $error = 'EDD_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();
        }
    }

    if ($success === true)
    {
        $comments = __( 'Razorpay Transaction ID: ', 'edd-razorpay-gateway' ) . $razorpayPaymentId . "\n";
        $response_text = 'publish';
        $comments .= $response_text;
        $comments = html_entity_decode( $comments, ENT_QUOTES, 'UTF-8' );

        $notes = array(
            'ID'            => $order_no,
            'post_excerpt'  => $comments
        );

        wp_update_post($notes);
        edd_update_payment_status($order_no, 'publish');

        edd_insert_payment_note($order_no, $comments);

        edd_empty_cart();

        edd_send_to_success_page();
    }
    else
    {
        $comments = '';

        if (isset($razorpayPaymentId))
        {
            $comments = __( 'Razorpay Transaction ID: ', 'edd-razorpay-gateway' ) . $response[RAZORPAY_PAYMENT_ID] . "\n";
        }

        $comments .= $error;
        $comments = html_entity_decode( $comments, ENT_QUOTES, 'UTF-8' );

        $notes = array(
            'ID'            => $order_no,
            'post_excerpt'  => $comments
        );

        wp_update_post($notes);
        edd_update_payment_status($order_no, 'failed' );

        edd_insert_payment_note($order_no, $comments);

        edd_set_error('server_direct_validation', __($error_message, 'edd-razorpay-gateway'));

        edd_send_back_to_checkout();
    }
}

function razorpay_get_customer_data($purchase_data)
{
    $language   = strtoupper(substr(get_bloginfo('language') , 0, 2));
    $firstname  = $purchase_data['user_info']['first_name'];
    $lastname   = $purchase_data['user_info']['last_name'];
    $email      = isset($purchase_data['user_email']) ? $purchase_data['user_email'] : $purchase_data['user_info']['email'];

    if (empty($firstname) || empty($lastname))
    {
        $name = $firstname.$lastname;
        list($firstname, $lastname) = preg_match('/\s/', $name) ? explode(' ', $name, 2) : array($name, $name);
    }

    $customer_data = array(
        'name'      => $firstname . ' '. $lastname,
        'email'     => $email,
        'currency'  => edd_get_currency(),
        'use_utf8'  => 1,
        'lang'      => $language
    );

    return $customer_data;
}

/**
 * Verify the signature on payment success
 * @param  int $order_no
 * @param  array $response
 * @return
 */
function verifySignature(int $order_no, array $response)
{
    $api = getRazorpayApiInstance();

    $attributes = array(
        RAZORPAY_PAYMENT_ID => $response[RAZORPAY_PAYMENT_ID],
        RAZORPAY_SIGNATURE  => $response[RAZORPAY_SIGNATURE],
    );

    $sessionKey = getOrderSessionKey($order_no);
    $attributes[RAZORPAY_ORDER_ID] = EDD()->session->get($sessionKey);

    $api->utility->verifyPaymentSignature($attributes);
}

/**
 * Create the session key name
 * @param  int $order_no
 * @return
 */
function getOrderSessionKey($order_no)
{
    return RAZORPAY_ORDER_ID . $order_no;
}

/**
* @codeCoverageIgnore
*/
function getRazorpayApiInstance()
{
    global $edd_options;

    return new Api($edd_options['key_id'], $edd_options['key_secret']);
}

/**
 * Create razorpay order id
 * @param  int    $order_no
 * @param  array  $payment
 * @return string
 */
function createRazorpayOrderId(int $order_no, array $payment)
{
    global $edd_options;

    $api = getRazorpayApiInstance();

    $data = array(
        'receipt'         => $order_no,
        'amount'          => (int) round($payment['price'] * 100),
        'currency'        => $payment['currency'],
        'payment_capture' => ($edd_options['payment_action'] === AUTHORIZE) ? 0 : 1,
        'notes'           => array(
            EDD_ORDER_ID  => (string) $order_no,
        ),
    );

    try
    {
        $razorpayOrder = $api->order->create($data);
    }
    catch (Exception $e)
    {
        return $e;
    }

    $razorpayOrderId = $razorpayOrder['id'];

    $sessionKey = getOrderSessionKey($order_no);

    EDD()->session->set($sessionKey, $razorpayOrderId);

    return $razorpayOrderId;
}

function razorpay_process_payment($purchase_data)
{
    global $edd_options;

    $payment_code       = 'razorpay';
    $customer_data      = razorpay_get_customer_data($purchase_data);
    $purchase_summary   = edd_get_purchase_summary($purchase_data);
    $mod_version        = get_plugin_data(plugin_dir_path(__FILE__) . 'razorpay-edd.php')['Version'];

    // Config data
    $config_data = array(
        'return_url'            => get_permalink($edd_options['success_page']),
        'return_method'         => 'POST',
        'error_return_url'      => edd_get_checkout_uri() . '?payment-mode=' . $purchase_data['post_data']['edd-gateway'],
        'error_return_method'   => 'POST'
    );

    /**********************************
    * set up the payment details      *
    **********************************/

    $payment = array(
        'price'         => $purchase_data['price'],
        'date'          => $purchase_data['date'],
        'user_email'    => $purchase_data['user_email'],
        'purchase_key'  => $purchase_data['purchase_key'],
        'currency'      => $edd_options['currency'],
        'downloads'     => $purchase_data['downloads'],
        'cart_details'  => $purchase_data['cart_details'],
        'user_info'     => $purchase_data['user_info'],
        'status'        => 'pending'
    );

    $order_no = edd_insert_payment($payment);

    $razorpayOrderId = createRazorpayOrderId($order_no, $payment);

    $purchase_data = array(
        'key_id'                      => $edd_options['key_id'],
        'amount'                      => $payment['price'] * 100,
        'merchant_order'              => $order_no,
        'currency'                    => $payment['currency'],
        'email'                       => $payment['user_info']['email'],
        'name'                        => $customer_data['name'],
        'config'                      => $config_data,
        'merchant_name'               => $edd_options['override_merchant_name'],
        'razorpay_order_id'           => $razorpayOrderId,
        'integration'                 => 'edd',
        'integration_version'         => $mod_version,
        'integration_parent_version'  => EDD_VERSION,
    );

    $errors = edd_get_errors();

    if (!$errors)
    {
        $html = '
        <!doctype html>
        <html>
          <head>
            <title>Razorpay</title>
            <meta name="viewport" content="user-scalable=no,width=device-width,initial-scale=1,maximum-scale=1">
            <meta http-equiv="pragma" content="no-cache">
            <meta http-equiv="cache-control" content="no-cache">
            <meta http-equiv="expires" content="0">
            <style>
                img{max-width: 100%; height: auto;}
                body{font-family: ubuntu,helvetica,verdana,sans-serif; font-size: 14px; text-align: center; color: #414141; padding-top: 40px; line-height: 24px;background:#fff;}
                label{position: absolute; top: 0; left: 0; right: 0; height: 100%; line-height: 32px; padding-left: 30px;}
                input[type=button]{
                    font-family: inherit;
                    padding: 12px 20px;
                    text-decoration: none;
                    border-radius: 2px;
                    border: 0;
                    width: 124px;
                    background: none;
                    margin: 0 5px;
                    color: #fff;
                    cursor: pointer;
                    -webkit-appearance: none;
                }
                input[type=button]:hover{background-image: linear-gradient(transparent,rgba(0,0,0,.05) 40%,rgba(0,0,0,.1))}
                .grey{color: #777; margin-top: 20px; font-size: 12px; line-height: 18px;}
                .danger{background-color: #EF6050!important}
                .success{background-color: #61BC6D!important}
            </style>
            <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
            <script>
                var options = {
                    "key": "' . $purchase_data['key_id'] . '",
                    "amount": ' . $purchase_data['amount'] . ',
                    "name": "' . $purchase_data['merchant_name'] . '",
                    "currency": "' . $purchase_data['currency'] . '",
                    "order_id": "' . $purchase_data['razorpay_order_id'] . '",
                    "description": "' . $purchase_summary . '",
                    "handler": function (response) {
                        document.getElementById("razorpay_id").value = response.razorpay_payment_id;
                        document.getElementById("razorpay_order_id").value = response.razorpay_order_id;
                        document.getElementById("razorpay_signature").value = response.razorpay_signature;
                        document.getElementById("razorpay").submit();
                    },
                    "modal": {
                        "ondismiss": function() {
                            window.location.href = "' . $config_data['error_return_url'] . '";
                        }
                    },
                    "prefill": {
                        "name": "' . $purchase_data['name'] . '",
                        "email": "' . $purchase_data['email'] . '"
                    },
                    "notes": {
                        "edd_order_id": "' . $purchase_data['merchant_order'] . '"
                    },
                    "_": {
                        "integration": "' . $purchase_data['integration'] . '",
                        "integration_version": "' . $purchase_data['integration_version'] . '",
                        "integration_parent_version": "' . $purchase_data['integration_parent_version'] . '"
                    },
                };
                var rzp = new Razorpay(options);
                rzp.open();

                function openRazorpay()
                {
                    rzp.open();
                }

                function cancel(e)
                {
                    window.location.href = "' . $config_data['error_return_url'] . '";
                }
            </script>
          </head>
          <body>
            <h3>Razorpay Payment</h3>
            Please wait...<br>
            <p>
                <input type="button" value="Pay" onclick="openRazorpay(this)" class="success">
                <input type="button" value="Cancel" onclick="cancel(this)" class="danger">

                <form action="' . $config_data['return_url'] . '" method="' . $config_data['return_method'] . '" id="razorpay">
                    <input type="hidden" name="merchant_order_id" value="' . $purchase_data['merchant_order']  . '">
                    <input type="hidden" name="razorpay_payment_id" id="razorpay_id">
                    <input type="hidden" name="razorpay_order_id" id="razorpay_order_id">
                    <input type="hidden" name="razorpay_signature" id="razorpay_signature">
                    <input type="hidden" name="gateway" value="razorpay_gateway">
                </form>
            </p>
            <p>

            </p>
            <p class="grey">
            </p>
            </form>
          </body>
        </html>';

        echo $html;
        exit;
    }
}
add_action('edd_gateway_razorpay', 'razorpay_process_payment');

function razorpay_add_settings($settings)
{
    $razorpay_settings = array(
        array(
            'id'   => 'razorpay_settings',
            'name' => '<strong>' . __('Razorpay', 'razorpay') . '</strong>',
            'desc' => __('Configure the Razorpay settings', 'razorpay'),
            'type' => 'header'
        ),
        array(
            'id'   => 'title',
            'name' => __('Title:', 'razorpay'),
            'desc' => __('This controls the title which the user sees during checkout.', 'razorpay'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => 'description',
            'name' => __('Description', 'razorpay'),
            'type' => 'textarea',
            'desc' => __('This controls the description which the user sees during checkout.', 'razorpay'),
        ),
        array(
            'id'   => 'key_id',
            'name' => __('Key ID', 'razorpay'),
            'desc' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 'razorpay'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => 'key_secret',
            'name' => __('Key Secret', 'razorpay'),
            'desc' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 'razorpay'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => 'payment_action',
            'name' => __('Payment Action', 'razorpay'),
            'desc' => __('Payment action on order compelete.', 'razorpay'),
            'type' => 'select',
            'size' => 'regular',
            'default' => CAPTURE,
            'options' => array(
                CAPTURE   => 'Authorize and Capture',
                AUTHORIZE => 'Authorize',
            )
        ),
        array(
            'id'   => 'override_merchant_name',
            'name' => __('Merchant Name', 'razorpay'),
            'desc' => __('Merchant name to be displayed on Razorpay screen.', 'razorpay'),
            'type' => 'text',
            'size' => 'regular'
        )
    );

    return array_merge($settings, $razorpay_settings);
}

add_filter('edd_settings_gateways', 'razorpay_add_settings');
