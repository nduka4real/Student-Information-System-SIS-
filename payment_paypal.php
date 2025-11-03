<?php
require_once('../../config.php');
require_once(__DIR__ . '/classes/payment_manager.php');

require_login();

$reference = required_param('reference', PARAM_TEXT);
$payment = local_sis_pay_payment_manager::get_payment_by_reference($reference);

if (!$payment || $payment->userid != $USER->id) {
    throw new moodle_exception('invalidpayment', 'local_sis');
}

$PAGE->set_url(new moodle_url('/local/sis/pay/payment_paypal.php', ['reference' => $reference]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('paymentprocessing', 'local_sis'));

echo $OUTPUT->header();

$client_id = get_payment_config('paypal', 'client_id');
$currency = get_payment_config('paypal', 'currency') ?: 'USD';
$test_mode = get_payment_config('paypal', 'test_mode');

?>
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><?php echo get_string('paymentviapaypal', 'local_sis'); ?></h4>
                </div>
                <div class="card-body text-center">
                    <p><?php echo get_string('paymentamount', 'local_sis') . ': ' . $payment->amount . ' ' . $currency; ?></p>
                    <p><?php echo get_string('paymentreference', 'local_sis') . ': ' . $payment->reference; ?></p>
                    
                    <div id="paypal-button-container"></div>
                    <div id="paymentResponse" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://www.paypal.com/sdk/js?client-id=<?php echo $client_id; ?>&currency=<?php echo $currency; ?>"></script>
<script>
paypal.Buttons({
    createOrder: function(data, actions) {
        return actions.order.create({
            purchase_units: [{
                amount: {
                    value: '<?php echo $payment->amount; ?>'
                },
                description: 'Course Enrollment Payment',
                custom_id: '<?php echo $payment->reference; ?>'
            }]
        });
    },
    onApprove: function(data, actions) {
        return actions.order.capture().then(function(details) {
            // Redirect to verification page
            window.location.href = '<?php echo new moodle_url('/local/sis/pay/payment_verify.php', ['gateway' => 'paypal', 'reference' => $payment->reference, 'transaction_id' => '']); ?>' + details.id;
        });
    },
    onError: function(err) {
        document.getElementById('paymentResponse').innerHTML = 
            '<div class="alert alert-danger">Payment failed: ' + err + '</div>';
    },
    onCancel: function(data) {
        document.getElementById('paymentResponse').innerHTML = 
            '<div class="alert alert-warning">Payment cancelled.</div>';
    }
}).render('#paypal-button-container');
</script>

<?php
echo $OUTPUT->footer();