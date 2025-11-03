<?php
require_once('../../config.php');
require_once(__DIR__ . '/classes/payment_manager.php');

require_login();

$gateway = required_param('gateway', PARAM_TEXT);
$reference = required_param('reference', PARAM_TEXT);
$transaction_id = optional_param('transaction_id', '', PARAM_TEXT);

$payment = local_sis_pay_payment_manager::get_payment_by_reference($reference);

if (!$payment || $payment->userid != $USER->id) {
    throw new moodle_exception('invalidpayment', 'local_sis');
}

$PAGE->set_url(new moodle_url('/local/sis/pay/payment_verify.php', [
    'gateway' => $gateway,
    'reference' => $reference
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('paymentverification', 'local_sis'));

echo $OUTPUT->header();

// Verify payment based on gateway
$verification_result = verify_payment($gateway, $reference, $transaction_id);

if ($verification_result['success']) {
    echo html_writer::div(
        html_writer::tag('h4', get_string('paymentsuccess', 'local_sis')) .
        html_writer::tag('p', get_string('paymentcompleted', 'local_sis')) .
        html_writer::tag('p', get_string('transactionid', 'local_sis') . ': ' . $verification_result['transaction_id']),
        'alert alert-success'
    );
    
    // Show course enrollment status if applicable
    if ($payment->courseid) {
        $course = $DB->get_record('course', ['id' => $payment->courseid]);
        echo html_writer::div(
            html_writer::tag('p', get_string('enrolledincourse', 'local_sis') . ': ' . $course->fullname) .
            html_writer::link(
                new moodle_url('/course/view.php', ['id' => $course->id]),
                get_string('accesscourse', 'local_sis'),
                ['class' => 'btn btn-primary']
            ),
            'mt-3 p-3 border rounded'
        );
    }
} else {
    echo html_writer::div(
        html_writer::tag('h4', get_string('paymentfailed', 'local_sis')) .
        html_writer::tag('p', $verification_result['message']),
        'alert alert-danger'
    );
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/sis/index.php'),
        get_string('backtodashboard', 'local_sis'),
        ['class' => 'btn btn-secondary']
    ),
    'text-center mt-3'
);

echo $OUTPUT->footer();

function verify_payment($gateway, $reference, $transaction_id) {
    global $DB;
    
    switch ($gateway) {
        case 'flutterwave':
            return verify_flutterwave_payment($reference);
        case 'paystack':
            return verify_paystack_payment($reference);
        case 'paypal':
            return verify_paypal_payment($reference, $transaction_id);
        default:
            return ['success' => false, 'message' => 'Invalid payment gateway'];
    }
}

function verify_flutterwave_payment($reference) {
    $secret_key = get_payment_config('flutterwave', 'secret_key');
    $test_mode = get_payment_config('flutterwave', 'test_mode');
    
    $url = $test_mode ? 
        'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . $reference :
        'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . $reference;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secret_key
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['status'] === 'success' && $result['data']['status'] === 'successful') {
        local_sis_payment_manager::update_payment_status(
            $reference,
            'completed',
            $result['data']['id'],
            $result
        );
        return ['success' => true, 'transaction_id' => $result['data']['id']];
    }
    
    return ['success' => false, 'message' => 'Payment verification failed'];
}

function verify_paystack_payment($reference) {
    $secret_key = get_payment_config('paystack', 'secret_key');
    
    $url = 'https://api.paystack.co/transaction/verify/' . $reference;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secret_key
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['status'] && $result['data']['status'] === 'success') {
        local_sis_payment_manager::update_payment_status(
            $reference,
            'completed',
            $result['data']['id'],
            $result
        );
        return ['success' => true, 'transaction_id' => $result['data']['id']];
    }
    
    return ['success' => false, 'message' => 'Payment verification failed'];
}

function verify_paypal_payment($reference, $transaction_id) {
    $client_id = get_payment_config('paypal', 'client_id');
    $client_secret = get_payment_config('paypal', 'client_secret');
    $test_mode = get_payment_config('paypal', 'test_mode');
    
    $base_url = $test_mode ? 
        'https://api.sandbox.paypal.com' : 
        'https://api.paypal.com';
    
    // Get access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base_url . '/v1/oauth2/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Accept-Language: en_US',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    
    $response = curl_exec($ch);
    $token_data = json_decode($response, true);
    curl_close($ch);
    
    if (!isset($token_data['access_token'])) {
        return ['success' => false, 'message' => 'Failed to get access token'];
    }
    
    // Verify payment
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base_url . '/v2/checkout/orders/' . $transaction_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token_data['access_token'],
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['status'] === 'COMPLETED') {
        local_sis_payment_manager::update_payment_status(
            $reference,
            'completed',
            $transaction_id,
            $result
        );
        return ['success' => true, 'transaction_id' => $transaction_id];
    }
    
    return ['success' => false, 'message' => 'Payment verification failed'];
}