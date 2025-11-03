<?php
// ? Correct path to Moodle config from /local/sis/pay/
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php'); // Local SIS main library

require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB, $USER, $PAGE, $OUTPUT;

// ? Correct Moodle URL for script inside /pay/
$PAGE->set_url(new moodle_url('/local/sis/pay/payment_categories.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('paymentcategories', 'local_sis'));
$PAGE->set_heading(get_string('paymentcategories', 'local_sis'));

// ? Navbar fixed for /pay/ folder
$PAGE->navbar->add(get_string('pluginname', 'local_sis'), new moodle_url('/local/sis/index.php'));
$PAGE->navbar->add(get_string('paymentsettings', 'local_sis'), new moodle_url('/local/sis/pay/payment_settings.php'));
$PAGE->navbar->add(get_string('paymentcategories', 'local_sis'));

echo $OUTPUT->header();

// ? Buttons
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/sis/index.php'),
        get_string('backtodashboard', 'local_sis'),
        ['class' => 'btn btn-secondary mb-3']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/sis/pay/payment_settings.php'),
        get_string('backtopaymentsettings', 'local_sis'),
        ['class' => 'btn btn-outline-primary mb-3']
    )
);

echo html_writer::start_div('container-fluid');

// ? Handle submission actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if (optional_param('save_categories', false, PARAM_BOOL)) {
        handle_category_payment_save();
    } elseif (optional_param('add_category', false, PARAM_BOOL)) {
        handle_category_payment_add();
    }
}

// ? Render main form
show_payment_categories_form();

echo html_writer::end_div();
echo $OUTPUT->footer();

/* ============================================================
   ? Functions — ORIGINAL LOGIC, NO REMOVALS, NO MODIFICATIONS
   ============================================================ */

function show_payment_categories_form() {
    global $DB, $OUTPUT;

    echo $OUTPUT->heading(get_string('configurecategorypayments', 'local_sis'));

    $categories = $DB->get_records('course_categories', null, 'name ASC');
    $payment_categories = $DB->get_records('local_sis_payment_categories');
    $payment_configs = [];
    foreach ($payment_categories as $pc) {
        $payment_configs[$pc->categoryid] = $pc;
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'payment-categories-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-header bg-primary text-white');
    echo html_writer::tag('h5', get_string('existingpaymentconfigs', 'local_sis'), ['class' => 'mb-0']);
    echo html_writer::end_div();
    echo html_writer::start_div('card-body');

    if (empty($categories)) {
        echo html_writer::div(get_string('nocategories', 'local_sis'), 'alert alert-info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'table table-striped table-bordered';
        $table->head = [
            get_string('category', 'local_sis'),
            get_string('paymenttype', 'local_sis'),
            get_string('amount', 'local_sis'),
            get_string('currency', 'local_sis'),
            get_string('enabled', 'local_sis'),
            get_string('actions', 'local_sis')
        ];

        foreach ($categories as $category) {
            $config = $payment_configs[$category->id] ?? null;

            $amount = $config->amount ?? '';
            $currency = $config->currency ?? 'USD';
            $payment_type = $config->payment_type ?? 'course_fee';
            $enabled = $config->enabled ?? 0;

            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => "categories[{$category->id}][exists]",
                'value' => $config ? '1' : '0'
            ]);

            $actions = '';
            if ($config) {
                $actions = html_writer::link(
                    '#',
                    get_string('delete', 'local_sis'),
                    [
                        'class' => 'btn btn-danger btn-sm delete-category',
                        'data-categoryid' => $category->id
                    ]
                );
            }

            $table->data[] = [
                format_string($category->name),
                html_writer::select(
                    [
                        'course_fee' => get_string('coursefee', 'local_sis'),
                        'registration_fee' => get_string('registrationfee', 'local_sis'),
                        'exam_fee' => get_string('examfee', 'local_sis'),
                        'library_fee' => get_string('libraryfee', 'local_sis'),
                        'other' => get_string('otherfee', 'local_sis')
                    ],
                    "categories[{$category->id}][payment_type]",
                    $payment_type,
                    false,
                    ['class' => 'form-control form-control-sm']
                ),
                html_writer::empty_tag('input', [
                    'type' => 'number',
                    'name' => "categories[{$category->id}][amount]",
                    'value' => $amount,
                    'min' => '0',
                    'step' => '0.01',
                    'class' => 'form-control form-control-sm',
                    'style' => 'width: 120px;'
                ]),
                html_writer::select(
                    [
                        'USD' => 'USD ($)',
                        'EUR' => 'EUR (€)',
                        'GBP' => 'GBP (£)',
                        'NGN' => 'NGN (?)',
                        'KES' => 'KES (KSh)',
                        'GHS' => 'GHS (GH?)'
                    ],
                    "categories[{$category->id}][currency]",
                    $currency,
                    false,
                    ['class' => 'form-control form-control-sm']
                ),
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => "categories[{$category->id}][enabled]",
                    'value' => '1',
                    'class' => 'form-check-input',
                    'checked' => $enabled ? 'checked' : ''
                ]),
                $actions
            ];
        }

        echo html_writer::table($table);
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group text-center');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'save_categories',
        'value' => get_string('savepaymentconfigs', 'local_sis'),
        'class' => 'btn btn-success btn-lg'
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // ? Add new
    echo html_writer::start_div('card mt-4');
    echo html_writer::start_div('card-header bg-info text-white');
    echo html_writer::tag('h5', get_string('addnewpaymentcategory', 'local_sis'), ['class' => 'mb-0']);
    echo html_writer::end_div();

    echo html_writer::start_div('card-body');
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('form-row');

    echo html_writer::start_div('col-md-4 form-group');
    echo html_writer::tag('label', get_string('category', 'local_sis'), ['class' => 'font-weight-bold']);
    
    $categories = $DB->get_records('course_categories');
    $opts = ['' => get_string('selectcategory', 'local_sis')];
    foreach ($categories as $c) {
        $opts[$c->id] = format_string($c->name);
    }

    echo html_writer::select($opts, 'new_category_id', '', false, ['class' => 'form-control', 'required' => 'required']);
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3 form-group');
    echo html_writer::tag('label', get_string('paymenttype', 'local_sis'), ['class' => 'font-weight-bold']);
    echo html_writer::select(
        [
            'course_fee' => get_string('coursefee', 'local_sis'),
            'registration_fee' => get_string('registrationfee', 'local_sis'),
            'exam_fee' => get_string('examfee', 'local_sis'),
            'library_fee' => get_string('libraryfee', 'local_sis'),
            'other' => get_string('otherfee', 'local_sis')
        ],
        'new_payment_type',
        'course_fee',
        false,
        ['class' => 'form-control', 'required' => 'required']
    );
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-2 form-group');
    echo html_writer::tag('label', get_string('amount', 'local_sis'), ['class' => 'font-weight-bold']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'new_amount',
        'min' => '0',
        'step' => '0.01',
        'class' => 'form-control',
        'required' => 'required',
        'placeholder' => '0.00'
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-2 form-group');
    echo html_writer::tag('label', get_string('currency', 'local_sis'), ['class' => 'font-weight-bold']);
    echo html_writer::select(
        [
            'USD' => 'USD ($)',
            'EUR' => 'EUR (€)',
            'GBP' => 'GBP (£)',
            'NGN' => 'NGN (?)',
            'KES' => 'KES (KSh)',
            'GHS' => 'GHS (GH?)'
        ],
        'new_currency',
        'USD',
        false,
        ['class' => 'form-control', 'required' => 'required']
    );
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-1 form-group d-flex align-items-end');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'add_category',
        'value' => get_string('add', 'local_sis'),
        'class' => 'btn btn-primary'
    ]);
    echo html_writer::end_div();

    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();

    add_category_javascript();
}

function handle_category_payment_save() {
    global $DB;

    $categories = optional_param_array('categories', [], PARAM_RAW);

    foreach ($categories as $categoryid => $data) {
        $exists = !empty($data['exists']);
        $amount = !empty($data['amount']) ? floatval($data['amount']) : 0;
        $currency = $data['currency'] ?? 'USD';
        $payment_type = $data['payment_type'] ?? 'course_fee';
        $enabled = !empty($data['enabled']) ? 1 : 0;

        if ($exists) {
            $rec = $DB->get_record('local_sis_payment_categories', ['categoryid' => $categoryid]);
            if ($rec) {
                if ($amount > 0) {
                    $rec->amount = $amount;
                    $rec->currency = $currency;
                    $rec->payment_type = $payment_type;
                    $rec->enabled = $enabled;
                    $rec->updated_at = time();
                    $DB->update_record('local_sis_payment_categories', $rec);
                } else {
                    $DB->delete_records('local_sis_payment_categories', ['id' => $rec->id]);
                }
            }
        } elseif ($amount > 0) {
            $rec = new stdClass();
            $rec->categoryid = $categoryid;
            $rec->amount = $amount;
            $rec->currency = $currency;
            $rec->payment_type = $payment_type;
            $rec->enabled = $enabled;
            $rec->created_at = time();
            $rec->updated_at = time();
            $DB->insert_record('local_sis_payment_categories', $rec);
        }
    }

    \core\notification::success(get_string('paymentconfigssaved', 'local_sis'));
}

function handle_category_payment_add() {
    global $DB;

    $categoryid = required_param('new_category_id', PARAM_INT);
    $amount = required_param('new_amount', PARAM_FLOAT);
    $currency = required_param('new_currency', PARAM_TEXT);
    $payment_type = required_param('new_payment_type', PARAM_TEXT);

    $exists = $DB->get_record('local_sis_payment_categories', ['categoryid' => $categoryid]);
    if ($exists) {
        \core\notification::error(get_string('paymentcategoryexists', 'local_sis'));
        return;
    }

    if ($amount <= 0) {
        \core\notification::error(get_string('invalidamount', 'local_sis'));
        return;
    }

    $rec = new stdClass();
    $rec->categoryid = $categoryid;
    $rec->amount = $amount;
    $rec->currency = $currency;
    $rec->payment_type = $payment_type;
    $rec->enabled = 1;
    $rec->created_at = time();
    $rec->updated_at = time();

    if ($DB->insert_record('local_sis_payment_categories', $rec)) {
        \core\notification::success(get_string('paymentcategoryadded', 'local_sis'));
    } else {
        \core\notification::error(get_string('paymentcategoryaddfailed', 'local_sis'));
    }
}

function add_category_javascript() {
    global $PAGE;
    $PAGE->requires->js_init_code("
        document.querySelectorAll('.delete-category').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('" . get_string('confirmdeletepaymentcategory', 'local_sis') . "')) {
                    var row = this.closest('tr');
                    row.querySelector('input[type=\"number\"]').value = '';
                    row.style.opacity = '0.6';
                    this.disabled = true;
                }
            });
        });
    ");
}