<?php
// Correct config path
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/sis/classes/payment_manager.php');

require_login();

global $USER, $DB, $PAGE, $OUTPUT;

// Get params
$gateway = required_param('gateway', PARAM_TEXT);
$reference = required_param('reference', PARAM_TEXT);

// Page setup
$PAGE->set_url(new moodle_url('/local/sis/pay/payment_verify.php', ['gateway' => $gateway, 'reference' => $reference]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('paymentverification', 'local_sis'));
$PAGE->set_heading(get_string('paymentverification', 'local_sis'));

echo $OUTPUT->header();

// Check payment exists
$payment = local_sis_payment_manager::get_payment_by_reference($reference);

if (!$payment || $payment->userid != $USER->id) {
    echo $OUTPUT->notification(get_string('invalidpayment', 'local_sis'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// Verify payment via Flutterwave API
if ($gateway === 'flutterwave') {
    $secret_key = get_config('local_sis', 'flutterwave_secret_key');

    // Call Flutterwave API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.flutterwave.com/v3/transactions/{$reference}/verify");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $secret_key"
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo $OUTPUT->notification('Error connecting to Flutterwave: ' . curl_error($ch), 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }
    curl_close($ch);

    $response = json_decode($result);

    if ($response && isset($response->status) && $response->status === 'success') {
        $tx = $response->data;

        // Check if payment was successful
        if ($tx->status === 'successful' && $tx->amount == $payment->amount) {
            // Update payment record in Moodle
            local_sis_payment_manager::update_payment_status($reference, 'completed', $tx->id, $tx);

            echo $OUTPUT->notification(get_string('paymentcompleted', 'local_sis', $tx->amount . ' ' . $tx->currency), 'notifysuccess');
        } else {
            local_sis_payment_manager::update_payment_status($reference, 'failed', $tx->id, $tx);
            echo $OUTPUT->notification(get_string('paymentfailed', 'local_sis'), 'notifyproblem');
        }
    } else {
        echo $OUTPUT->notification(get_string('paymentverificationfailed', 'local_sis'), 'notifyproblem');
    }
} else {
    echo $OUTPUT->notification(get_string('unsupportedgateway', 'local_sis'), 'notifyproblem');
}

echo $OUTPUT->footer();