<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/sis/classes/payment_manager.php');

require_login();
global $USER, $DB, $PAGE, $OUTPUT;

$reference = required_param('reference', PARAM_TEXT);
$payment = local_sis_payment_manager::get_payment_by_reference($reference);

if (!$payment || $payment->userid != $USER->id) {
    throw new moodle_exception('invalidpayment', 'local_sis');
}

$PAGE->set_url(new moodle_url('/local/sis/pay/payment_flutterwave.php', ['reference' => $reference]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title("Flutterwave Payment");
$PAGE->set_heading("Complete Payment");

echo $OUTPUT->header();

// Fetch public key and currency from local_sis_payment_config table
$public_key_record = $DB->get_record('local_sis_payment_config', ['gateway' => 'flutterwave', 'config_key' => 'public_key']);
$currency_record = $DB->get_record('local_sis_payment_config', ['gateway' => 'flutterwave', 'config_key' => 'currency']);

$public_key = $public_key_record ? $public_key_record->config_value : '';
$currency = $currency_record ? $currency_record->config_value : 'NGN';

// Validate configuration
if (empty($public_key)) {
    echo $OUTPUT->notification('Flutterwave public key is not configured. Please contact administrator.', 'error');
    echo $OUTPUT->footer();
    exit;
}

// Build redirect URL for verification
$verifyurl = new moodle_url('/local/sis/pay/payment_verify.php', [
    'gateway' => 'flutterwave',
    'reference' => $payment->reference
]);
?>

<div class="container mt-4 text-center">
    <h4>Amount: <b><?php echo $payment->amount . ' ' . $currency; ?></b></h4>
    <p>Reference: <b><?php echo $payment->reference; ?></b></p>
    <button id="payBtn" class="btn btn-success btn-lg">
        <i class="fa fa-credit-card"></i> Pay Now
    </button>
    <div id="msg" class="mt-3"></div>
</div>

<script src="https://checkout.flutterwave.com/v3.js"></script>
<script>
document.getElementById("payBtn").onclick = function() {
    const payBtn = this;
    const messageDiv = document.getElementById("msg");
    payBtn.disabled = true;
    payBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
    messageDiv.innerHTML = '';

    FlutterwaveCheckout({
        public_key: "<?php echo $public_key; ?>",
        tx_ref: "<?php echo $payment->reference; ?>",
        amount: <?php echo $payment->amount; ?>,
        currency: "<?php echo $currency; ?>",
        payment_options: "card, banktransfer, ussd, mobilemoney",
        redirect_url: "<?php echo $verifyurl->out(false); ?>",
        customer: {
            email: "<?php echo clean_param($USER->email, PARAM_EMAIL); ?>",
            name: "<?php echo addslashes(fullname($USER)); ?>"
        },
        customizations: {
            title: "School Fees Payment",
            description: "Payment for school services - Reference: <?php echo $payment->reference; ?>",
            logo: "<?php echo $CFG->wwwroot; ?>/theme/image.php?theme=<?php echo $CFG->theme; ?>&component=core&image=logo"
        },
        onclose: function() {
            payBtn.disabled = false;
            payBtn.innerHTML = '<i class="fa fa-credit-card"></i> Pay Now';
            messageDiv.innerHTML = '<div class="alert alert-warning">Payment window was closed. Click "Pay Now" to try again.</div>';
        },
        callback: function(response) {
            if (response.status === 'successful') {
                messageDiv.innerHTML = '<div class="alert alert-success">Payment completed! Redirecting...</div>';
                window.location.href = "<?php echo $verifyurl->out(false); ?>";
            } else {
                messageDiv.innerHTML = '<div class="alert alert-danger">Payment failed. Please try again.</div>';
                payBtn.disabled = false;
                payBtn.innerHTML = '<i class="fa fa-credit-card"></i> Pay Now';
            }
        }
    });
};
</script>

<?php echo $OUTPUT->footer(); ?>