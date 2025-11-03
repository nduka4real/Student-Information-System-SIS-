<?php
// /local/sis/pay/make_payment.php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php');

require_login();

header('Content-Type: text/html; charset=utf-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

global $USER, $DB, $PAGE, $OUTPUT;

if (!is_student_user($USER->id)) {
    throw new moodle_exception('accessdenied', 'admin');
}

$PAGE->set_url(new moodle_url('/local/sis/pay/make_payment.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('makepayment', 'local_sis'));
$PAGE->set_heading(get_string('makepayment', 'local_sis'));
$PAGE->navbar->add(get_string('pluginname', 'local_sis'), new moodle_url('/local/sis/index.php'));
$PAGE->navbar->add(get_string('makepayment', 'local_sis'));
$PAGE->requires->css(new moodle_url('/local/sis/styles/payment.css'));

echo $OUTPUT->header();
echo html_writer::start_div('container-fluid');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    handle_payment_initiation();
}

show_payment_options();

echo html_writer::end_div();
echo $OUTPUT->footer();


/* ----------------- FUNCTIONS ------------------ */

function show_payment_options() {
    global $USER, $DB, $OUTPUT;

    echo $OUTPUT->heading(get_string('selectpaymentoption', 'local_sis'));

    // get categories and SIS fee data
    $sql = "
        SELECT DISTINCT cat.id, cat.name, cat.description,
            pc.course_fee, pc.registration_fee, pc.exam_fee,
            pc.library_fee, pc.other_fee, pc.currency
        FROM {course_categories} cat
        JOIN {course} c ON c.category = cat.id
        JOIN {enrol} e ON e.courseid = c.id
        JOIN {user_enrolments} ue ON ue.enrolid = e.id
        LEFT JOIN {local_sis_payment_categories} pc ON pc.categoryid = cat.id AND pc.enabled = 1
        WHERE ue.userid = ? AND c.visible = 1
        ORDER BY cat.name ASC
    ";

    $categories = $DB->get_records_sql($sql, [$USER->id]);

    if (!$categories) {
        echo $OUTPUT->notification("No payment categories found.", 'notifyinfo');
        return;
    }

    // Payment gateways
    $available_gateways = \local_sis_payment_manager::get_available_gateways();
    if (!$available_gateways) {
        echo $OUTPUT->notification("No payment gateways enabled.", 'notifyproblem');
        return;
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'">';
    echo '<div class="row"><div class="col-md-8">';
    echo '<div class="card"><div class="card-header bg-primary text-white">
            <h5 class="mb-0">Payment Options</h5></div><div class="card-body">';
    echo '<div class="row">';

    $fee_fields = [
        'course_fee' => 'Course Fee',
        'registration_fee' => 'Registration Fee',
        'exam_fee' => 'Exam Fee',
        'library_fee' => 'Library Fee',
        'other_fee' => 'Other Fee'
    ];

    foreach ($categories as $cat) {
        foreach ($fee_fields as $field => $label) {
            $amount = $cat->$field;

            if ($amount > 0) {
                $currency_symbol = get_currency_symbol($cat->currency);

                echo '<div class="col-md-6 mb-3">
                        <div class="card payment-option-card">
                        <div class="card-body">
            
                        <input type="radio" name="payment_option"
                            class="payment-option"
                            value="cat_'.$cat->id.'_'.$field.'"
                            data-amount="'.$amount.'"
                            data-currency="'.$cat->currency.'"
                            data-description="'.$label.' - '.format_string($cat->name).'">
                        
                        <h6>'.format_string($cat->name).'</h6>
                        <p class="text-muted small">'.$label.'</p>
                        <h5>'.$currency_symbol.' '.number_format($amount, 2).'</h5>
                        
                        </div></div>
                    </div>';
            }
        }
    }

    // Custom payment box
    echo '
    <div class="col-md-6 mb-3">
        <div class="card payment-option-card">
            <div class="card-body">
                <input type="radio" name="payment_option" value="custom" class="payment-option" id="custom_payment">
                <h6>Custom Amount</h6>
                <input type="number" name="custom_amount" min="1" step="0.01" class="form-control mb-2" placeholder="Enter amount">
                <select name="custom_currency" class="form-control">
                    <option value="NGN">NGN (&#8358;)</option>
                    <option value="USD">USD ($)</option>
                    <option value="EUR">EUR (€)</option>
                    <option value="GBP">GBP (£)</option>
                </select>
            </div>
        </div>
    </div>';

    echo '</div></div></div></div>';

    // Summary & Gateway
    echo '<div class="col-md-4">
        <div class="card"><div class="card-header bg-success text-white"><h5>Summary</h5></div>
        <div class="card-body">
            <p id="payment-description">Select an option</p>
            <div id="payment-amount" class="h3">--</div>
        </div></div>

        <div class="card mt-3"><div class="card-header bg-info text-white"><h5>Payment Gateway</h5></div>
        <div class="card-body">';

    foreach ($available_gateways as $gw) {
        echo '<div class="form-check mb-2">
                <input type="radio" class="form-check-input" name="payment_gateway" value="'.$gw.'">
                <label class="form-check-label">'.ucfirst($gw).'</label>
            </div>';
    }

    echo '<button class="btn btn-success w-100 mt-2" id="payment-submit" disabled>Pay Now</button></div></div></div></div></form>';

    add_payment_js();
}

/** Submission handler */
function handle_payment_initiation() {
    global $USER, $DB;

    $opt = required_param('payment_option', PARAM_TEXT);
    $gw = required_param('payment_gateway', PARAM_TEXT);

    if ($opt === 'custom') {
        $amount = required_param('custom_amount', PARAM_FLOAT);
        $currency = required_param('custom_currency', PARAM_TEXT);
        $description = "Custom Payment";
        $catid = null;
    } else {
        list(, $catid, $field) = explode('_', $opt);
        $record = $DB->get_record('local_sis_payment_categories', ['categoryid' => $catid], '*', MUST_EXIST);
        $amount = $record->$field;
        $currency = $record->currency;
        $cat = $DB->get_record('course_categories', ['id' => $catid]);
        $description = ucfirst(str_replace('_',' ', $field))." - ".$cat->name;
    }

    if ($amount <= 0) {
        \core\notification::error("Invalid amount.");
        return;
    }

    $payid = \local_sis_payment_manager::create_payment(
        $USER->id, $amount, $currency, $gw, null,
        ['description'=>$description,'categoryid'=>$catid]
    );

    $pay = \local_sis_payment_manager::get_payment_by_id($payid);
    redirect(new moodle_url("/local/sis/pay/payment_{$gw}.php", ['reference'=>$pay->reference]));
}

/** JS */
function add_payment_js() {
    global $PAGE;
    $PAGE->requires->js_init_code("
        function updateUI(){
            const opt = document.querySelector('input[name=\"payment_option\"]:checked');
            const desc = document.getElementById('payment-description');
            const amt = document.getElementById('payment-amount');
            const btn = document.getElementById('payment-submit');

            if(!opt){ desc.textContent='Select an option'; amt.textContent='--'; btn.disabled=true; return; }

            let amount = opt.dataset.amount;
            let currency = opt.dataset.currency;
            if(opt.value==='custom'){
                amount = document.querySelector('[name=\"custom_amount\"]').value;
                currency = document.querySelector('[name=\"custom_currency\"]').value;
            }

            if(!amount || amount <= 0){
                desc.textContent='Select an option';
                amt.textContent='--';
                btn.disabled=true;
                return;
            }

            desc.textContent = opt.dataset.description || 'Custom Payment';
            amt.textContent = currency + ' ' + parseFloat(amount).toFixed(2);
            btn.disabled = !document.querySelector('input[name=\"payment_gateway\"]:checked');
        }

        document.querySelectorAll('input,select').forEach(i => i.addEventListener('change', updateUI));
        updateUI();
    ");
}

/** Currency symbol helper */
function get_currency_symbol($c) {
    return [
        'USD'=>'$','EUR'=>'€','GBP'=>'£','NGN'=>'&#8358;'
    ][$c] ?? $c;
}

function is_student_user($uid) {
    $ctx = context_system::instance();
    return !(has_capability('moodle/site:config',$ctx) || has_capability('moodle/course:update',$ctx));
}
?>