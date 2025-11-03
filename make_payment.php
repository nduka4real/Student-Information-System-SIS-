<?php
// /local/sis/pay/make_payment.php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php');

require_login();
global $USER, $DB, $PAGE, $OUTPUT;

// ONLY block admins/editors — students can access
if (!is_student_user($USER->id)) {
    throw new moodle_exception('accessdenied', 'admin');
}

$PAGE->set_url(new moodle_url('/local/sis/pay/make_payment.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Make Payment");
$PAGE->set_heading("Make Payment");
$PAGE->navbar->add("Payments", new moodle_url('/local/sis/index.php'));
$PAGE->navbar->add("Make Payment");
$PAGE->requires->css(new moodle_url('/local/sis/styles/payment.css'));

echo $OUTPUT->header();
echo html_writer::start_div('container-fluid');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    handle_payment_initiation();
}

show_payment_options();

echo html_writer::end_div();
echo $OUTPUT->footer();

/* ---------------- FUNCTIONS ---------------- */

function show_payment_options() {
    global $USER, $DB, $OUTPUT;

    echo $OUTPUT->heading("Select Payment Option");

    // Load categories linked to student's enrollments
    $sql = "
        SELECT DISTINCT cat.id, cat.name, 
            pc.course_fee, pc.registration_fee, pc.exam_fee,
            pc.library_fee, pc.other_fee, pc.currency, pc.payallfees
        FROM {course_categories} cat
        JOIN {course} c ON c.category = cat.id
        JOIN {enrol} e ON e.courseid = c.id
        JOIN {user_enrolments} ue ON ue.enrolid = e.id
        LEFT JOIN {local_sis_payment_categories} pc 
             ON pc.categoryid = cat.id AND pc.enabled = 1
        WHERE ue.userid = ? AND c.visible = 1
        ORDER BY cat.name ASC
    ";
    $categories = $DB->get_records_sql($sql, [$USER->id]);

    if (!$categories) {
        echo $OUTPUT->notification("No payment options found", 'notifyinfo');
        return;
    }

    // Calculate total of all fees across all categories
    $total_all_categories_fees = 0;
    $all_categories_currency = 'NGN'; // Default
    $all_fees_breakdown = [];
    $currencies_used = [];

    $fields = [
        'course_fee' => 'Course Fee',
        'registration_fee' => 'Registration Fee',
        'exam_fee' => 'Exam Fee',
        'library_fee' => 'Library Fee',
        'other_fee' => 'Other Fee'
    ];

    foreach ($categories as $cat) {
        foreach ($fields as $field => $label) {
            if ($cat->$field > 0) {
                $total_all_categories_fees += $cat->$field;
                $all_categories_currency = $cat->currency;
                $currencies_used[$cat->currency] = true;
                
                $all_fees_breakdown[] = [
                    'category_name' => format_string($cat->name),
                    'fee_type' => $label,
                    'amount' => $cat->$field,
                    'currency' => $cat->currency,
                    'currency_symbol' => get_currency_symbol($cat->currency) // Pre-get the symbol
                ];
            }
        }
    }

    // Check if multiple currencies are used
    $multiple_currencies = count($currencies_used) > 1;

    $gateways = \local_sis_payment_manager::get_available_gateways();
    if (!$gateways) {
        echo $OUTPUT->notification("No payment gateways enabled.", 'notifyproblem');
        return;
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'">';

    echo '<div class="row"><div class="col-md-8">';
    echo '<div class="card"><div class="card-header bg-primary text-white">
            <h5>Payment Options</h5>
          </div><div class="card-body"><div class="row">';

    /* --- Pay All Fees Across All Categories --- */
    if ($total_all_categories_fees > 0 && !$multiple_currencies) {
        $currency_symbol = get_currency_symbol($all_categories_currency);

        echo '
        <div class="col-md-12 mb-3">
          <div class="card border-success pay-all-categories-card">
            <div class="card-body text-center">
              <input type="radio" name="payment_option"
                  value="all_categories"
                  data-amount="'.$total_all_categories_fees.'"
                  data-currency="'.$all_categories_currency.'"
                  data-description="All Fees - All Categories"
                  class="payment-option">
                  
              <h5 class="text-success mb-1"><i class="fa fa-check-circle"></i> Pay ALL Fees (All Categories)</h5>
              <p class="text-muted small">Complete all payments across all your categories in one transaction</p>
              <strong class="h4 text-success">'.$currency_symbol.' '.number_format($total_all_categories_fees,2).'</strong>
              
              <!-- Fee Breakdown -->
              <div class="fee-breakdown mt-3" style="display: none;">
                <hr>
                <h6>Fee Breakdown:</h6>';
        
        foreach ($all_fees_breakdown as $item) {
            echo '<div class="d-flex justify-content-between small mb-1">
                    <span>'.$item['category_name'].' - '.$item['fee_type'].'</span>
                    <span>'.$item['currency_symbol'].' '.number_format($item['amount'], 2).'</span>
                  </div>';
        }
        
        echo '</div>
            </div>
          </div>
        </div>';
    } elseif ($multiple_currencies) {
        echo '
        <div class="col-md-12 mb-3">
          <div class="card border-secondary">
            <div class="card-body text-center">
              <h6 class="text-muted">Pay All Fees (Multiple Currencies)</h6>
              <p class="text-muted small">Cannot process combined payment due to multiple currencies</p>
              <small class="text-warning">Please pay fees separately for each currency</small>
            </div>
          </div>
        </div>';
    }

    /* --- Individual Category Pay All Fees --- */
    foreach ($categories as $cat) {
        if (!empty($cat->payallfees)) {
            $total = ($cat->course_fee + $cat->registration_fee + $cat->exam_fee + $cat->library_fee + $cat->other_fee);

            $currency_symbol = get_currency_symbol($cat->currency);

            echo '
            <div class="col-md-6 mb-3">
              <div class="card border-primary pay-all-card">
                <div class="card-body">
                  <input type="radio" name="payment_option"
                      value="all_'.$cat->id.'"
                      data-amount="'.$total.'"
                      data-currency="'.$cat->currency.'"
                      data-description="Full Payment - '.format_string($cat->name).'"
                      class="payment-option">
                      
                  <h6 class="text-primary mb-1">Pay ALL Fees ('.format_string($cat->name).')</h6>
                  <strong>'.$currency_symbol.' '.number_format($total,2).'</strong>
                </div>
              </div>
            </div>';
        }
    }

    /* --- Single Fee Options --- */
    foreach ($categories as $cat) {
        foreach ($fields as $field => $label) {
            if ($cat->$field > 0) {
                $currency_symbol = get_currency_symbol($cat->currency);
                $simple = str_replace('_fee','',$field);

                echo '
                <div class="col-md-6 mb-3">
                  <div class="card payment-option-card">
                    <div class="card-body">
                      <input type="radio" name="payment_option"
                        class="payment-option"
                        value="cat_'.$cat->id.'_'.$simple.'"
                        data-amount="'.$cat->$field.'"
                        data-currency="'.$cat->currency.'"
                        data-description="'.$label.' - '.format_string($cat->name).'">
                      
                      <h6>'.format_string($cat->name).'</h6>
                      <p class="text-muted small">'.$label.'</p>
                      <h5>'.$currency_symbol.' '.number_format($cat->$field, 2).'</h5>
                    </div>
                  </div>
                </div>';
            }
        }
    }

    /* --- Custom payment --- */
    echo '
    <div class="col-md-6 mb-3">
      <div class="card payment-option-card">
        <div class="card-body">
          <input type="radio" name="payment_option" value="custom" class="payment-option">
          <h6>Custom Amount</h6>
          <input type="number" name="custom_amount" min="1" class="form-control mb-2" placeholder="Amount">
          <select name="custom_currency" class="form-control">
            <option value="NGN">NGN (&#8358;)</option>
            <option value="USD">USD ($)</option>
            <option value="EUR">EUR (€)</option>
            <option value="GBP">GBP (£)</option>
          </select>
        </div>
      </div>
    </div>';

    echo '</div></div></div></div>'; // close cards/cols

    /* Right summary/gateway */
    echo '<div class="col-md-4">
      <div class="card"><div class="card-header bg-success text-white"><h5>Summary</h5></div>
        <div class="card-body">
          <p id="payment-description">Select option</p>
          <div id="payment-amount" class="h3">--</div>
          <div id="fee-breakdown" class="mt-3" style="display: none;">
            <h6>Included Fees:</h6>
            <div id="breakdown-list"></div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header bg-info text-white"><h5>Payment Gateway</h5></div>
        <div class="card-body">';

    foreach ($gateways as $gw) {
        echo '<div class="form-check mb-2">
                <input type="radio" class="form-check-input" name="payment_gateway" value="'.$gw.'">
                <label class="form-check-label">'.ucfirst($gw).'</label>
              </div>';
    }

    echo '
        <button class="btn btn-success w-100 mt-2" id="payment-submit" disabled>Pay Now</button>
      </div></div></div></div>
    </form>';

    add_payment_js($all_fees_breakdown);
}

/* ------- Payment submit handler ------- */
function handle_payment_initiation() {
    global $USER, $DB;

    $option = required_param('payment_option', PARAM_TEXT);
    $gateway = required_param('payment_gateway', PARAM_TEXT);

    // Handle custom
    if ($option === 'custom') {
        $amount = required_param('custom_amount', PARAM_FLOAT);
        $currency = required_param('custom_currency', PARAM_TEXT);
        $description = "Custom Payment";
        $catid = null;
        $fee_type = 'custom';
    }
    // Pay all fees across all categories
    elseif ($option === 'all_categories') {
        // Calculate total of all fees across all categories
        $sql = "
            SELECT DISTINCT cat.id, cat.name,
                pc.course_fee, pc.registration_fee, pc.exam_fee,
                pc.library_fee, pc.other_fee, pc.currency
            FROM {course_categories} cat
            JOIN {course} c ON c.category = cat.id
            JOIN {enrol} e ON e.courseid = c.id
            JOIN {user_enrolments} ue ON ue.enrolid = e.id
            LEFT JOIN {local_sis_payment_categories} pc 
                 ON pc.categoryid = cat.id AND pc.enabled = 1
            WHERE ue.userid = ? AND c.visible = 1
        ";
        
        $categories = $DB->get_records_sql($sql, [$USER->id]);
        
        $amount = 0;
        $currency = 'NGN'; // Default
        $fee_fields = ['course_fee', 'registration_fee', 'exam_fee', 'library_fee', 'other_fee'];
        
        foreach ($categories as $cat) {
            foreach ($fee_fields as $field) {
                if ($cat->$field > 0) {
                    $amount += $cat->$field;
                    $currency = $cat->currency; // Use last currency (should be same for all)
                }
            }
        }
        
        $description = "All Fees - All Categories";
        $catid = null;
        $fee_type = 'all_categories';
    }
    // Pay all fees for a specific category
    elseif (strpos($option, 'all_') === 0) {
        list(, $catid) = explode('_', $option);
        $rec = $DB->get_record('local_sis_payment_categories', ['categoryid'=>$catid], '*', MUST_EXIST);
        $amount = $rec->course_fee + $rec->registration_fee + $rec->exam_fee + $rec->library_fee + $rec->other_fee;
        $currency = $rec->currency;
        $cat = $DB->get_record('course_categories',['id'=>$catid]);
        $description = "Full Payment - ".format_string($cat->name);
        $fee_type = 'all_category';
    }
    // Single fee
    else {
        list(, $catid, $simple) = explode('_', $option);

        $map = [
            'course' => 'course_fee',
            'registration' => 'registration_fee',
            'exam' => 'exam_fee',
            'library' => 'library_fee',
            'other' => 'other_fee'
        ];

        if (!isset($map[$simple])) {
            \core\notification::error("Invalid payment type.");
            return;
        }

        $dbfield = $map[$simple];
        $rec = $DB->get_record('local_sis_payment_categories', ['categoryid'=>$catid], '*', MUST_EXIST);
        $amount = $rec->$dbfield;
        $currency = $rec->currency;
        $cat = $DB->get_record('course_categories',['id'=>$catid]);
        $description = ucfirst($simple)." Fee - ".format_string($cat->name);
        $fee_type = $simple;
    }

    if ($amount <= 0) {
        \core\notification::error("Invalid amount.");
        return;
    }

    // Create payment record
    $metadata = [
        'description' => $description,
        'categoryid' => $catid,
        'fee_type' => $fee_type,
        'user_fullname' => fullname($USER)
    ];
    
    $pid = \local_sis_payment_manager::create_payment(
        $USER->id, $amount, $currency, $gateway, null, $metadata
    );

    $pay = \local_sis_payment_manager::get_payment_by_id($pid);

    redirect(new moodle_url("/local/sis/pay/payment_{$gateway}.php", ['reference'=>$pay->reference]));
}

/* ------- JS UI logic ------- */
function add_payment_js($all_fees_breakdown = []) {
    global $PAGE;
    
    // Pre-process the breakdown to include HTML entities for symbols
    $processed_breakdown = [];
    foreach ($all_fees_breakdown as $item) {
        $processed_breakdown[] = [
            'category_name' => $item['category_name'],
            'fee_type' => $item['fee_type'],
            'amount' => $item['amount'],
            'currency' => $item['currency'],
            'currency_symbol' => $item['currency_symbol'] // Already contains HTML entity
        ];
    }
    
    // Convert fee breakdown to JSON for JavaScript
    $fee_breakdown_json = json_encode($processed_breakdown);
    
    $js_code = "
        const feeBreakdown = $fee_breakdown_json;
        
        function getCurrencySymbol(currency) {
            const symbols = {
                'USD': '$',
                'EUR': '€',
                'GBP': '£',
                'NGN': '&#8358;'  // HTML entity for Naira
            };
            return symbols[currency] || currency;
        }

        function updatePayUI(){
            const opt = document.querySelector('input[name=\"payment_option\"]:checked');
            const desc = document.getElementById('payment-description');
            const amt = document.getElementById('payment-amount');
            const btn = document.getElementById('payment-submit');
            const breakdownDiv = document.getElementById('fee-breakdown');
            const breakdownList = document.getElementById('breakdown-list');
            
            if(!opt){
                desc.textContent='Select option';
                amt.innerHTML='--';
                btn.disabled=true;
                breakdownDiv.style.display = 'none';
                return;
            }

            let amount = opt.dataset.amount;
            let currency = opt.dataset.currency;

            if(opt.value==='custom'){
                amount = document.querySelector('[name=\"custom_amount\"]').value;
                currency = document.querySelector('[name=\"custom_currency\"]').value;
                breakdownDiv.style.display = 'none';
            } else if(opt.value==='all_categories'){
                // Show breakdown for all categories
                breakdownDiv.style.display = 'block';
                breakdownList.innerHTML = '';
                
                feeBreakdown.forEach(function(item) {
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'd-flex justify-content-between small mb-1';
                    // Use the pre-processed currency symbol (HTML entity)
                    itemDiv.innerHTML = '<span>' + item.category_name + ' - ' + item.fee_type + '</span><span>' + item.currency_symbol + ' ' + parseFloat(item.amount).toFixed(2) + '</span>';
                    breakdownList.appendChild(itemDiv);
                });
            } else {
                breakdownDiv.style.display = 'none';
            }

            if(!amount || amount<=0){
                desc.textContent='Select option';
                amt.innerHTML='--';
                btn.disabled=true;
                return;
            }

            desc.textContent = opt.dataset.description || 'Custom Payment';
            const symbol = getCurrencySymbol(currency);
            // Use innerHTML to render the HTML entity
            amt.innerHTML = symbol + ' ' + parseFloat(amount).toFixed(2);
            btn.disabled = !document.querySelector('input[name=\"payment_gateway\"]:checked');
        }

        // Toggle fee breakdown visibility for Pay All Categories option
        document.addEventListener('click', function(e) {
            if (e.target && e.target.name === 'payment_option' && e.target.value === 'all_categories') {
                const breakdown = e.target.closest('.pay-all-categories-card').querySelector('.fee-breakdown');
                if (breakdown) {
                    breakdown.style.display = breakdown.style.display === 'none' ? 'block' : 'none';
                }
            }
        });

        document.querySelectorAll('input,select').forEach(function(el) {
            el.addEventListener('change', updatePayUI);
        });
        
        // Also update on input for custom amount
        const customAmountInput = document.querySelector('[name=\"custom_amount\"]');
        if (customAmountInput) {
            customAmountInput.addEventListener('input', updatePayUI);
        }
        
        updatePayUI();
    ";

    $PAGE->requires->js_init_code($js_code);
}

/* ------- Helpers ------- */
function get_currency_symbol($c) {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'NGN' => '&#8358;'  // HTML entity for Naira
    ];
    return $symbols[$c] ?? $c;
}

function is_student_user($uid) {
    $ctx = context_system::instance();
    return !(
        has_capability('moodle/site:config',$ctx) ||
        has_capability('moodle/course:update',$ctx)
    );
}
?>