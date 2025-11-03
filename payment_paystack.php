<?php
// /local/sis/pay/payment_paystack.php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/payment_manager.php'); // Make sure this file exists

require_login();
global $USER, $DB, $PAGE, $OUTPUT;

// Force UTF-8 headers for currency symbols
header('Content-Type: text/html; charset=utf-8');

// Get reference from URL
$reference = required_param('reference', PARAM_TEXT);

// Fetch payment
$payment = local_sis_pay_payment_manager::get_payment_by_reference($reference);

if (!$payment || $payment->userid != $USER->id) {
    throw new moodle_exception('invalidpayment', 'local_sis');
}

// Page setup
$PAGE->set_url(new moodle_url('/local/sis/pay/payment_paystack.php', ['reference' => $reference]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('paymentprocessing', 'local_sis'));
$PAGE->set_heading(get_string('paymentprocessing', 'local_sis'));

// Load Paystack config
$public_key = get_payment_config('paystack', 'public_key');
$currency = get_payment_config('paystack', 'currency') ?: 'NGN';
$currency_symbol = ($currency === 'NGN') ? '?' : $currency;

echo $OUTPUT->header();
?>

<div class="container-fluid">
    <div class="row justify-content-center mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><?php echo get_string('paymentviapaystack', 'local_sis'); ?></h4>
                </div>
                <div class="card-body text-center">
                    <p>
                        <?php 
                        echo get_string('paymentamount', 'local_sis') . ': ' . 
                             $currency_symbol . number_format($payment->amount, 2); 
                        ?>
                    </p>
                    <p>
                        <?php 
                        echo get_string('paymentreference', 'local_sis') . ': ' . 
                             $payment->reference; 
                        ?>
                    </p>
                    
                    <button id="paystackPay" class="btn btn-success btn-lg">
                        <?php echo get_string('paynow', 'local_sis'); ?>
                    </button>
                    
                    <div id="paymentResponse" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
document.getElementById('paystackPay').addEventListener('click', function() {
    var handler = PaystackPop.setup({
        key: '<?php echo $public_key; ?>',
        email: '<?php echo $USER->email; ?>',
        amount: <?php echo intval($payment->amount * 100); ?>, // Paystack expects kobo
        currency: '<?php echo $currency; ?>',
        ref: '<?php echo $payment->reference; ?>',
        callback: function(response) {
            // Redirect to verification page
            window.location.href = '<?php echo new moodle_url('/local/sis/pay/payment_verify.php', [
                'gateway' => 'paystack',
                'reference' => $payment->reference
            ]); ?>&transaction_id=' + response.reference;
        },
        onClose: function() {
            document.getElementById('paymentResponse').innerHTML = 
                '<div class="alert alert-warning"><?php echo addslashes(get_string('paymentwindowclosed', 'local_sis')); ?></div>';
        }
    });
    handler.openIframe();
});
</script>

<?php
echo $OUTPUT->footer();
?>