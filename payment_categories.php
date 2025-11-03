<?php
// /local/sis/pay/payment_categories.php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php'); // Local SIS main library

require_login();
require_capability('moodle/site:config', context_system::instance());

// Force UTF-8 to fix currency symbols
header('Content-Type: text/html; charset=utf-8');

global $DB, $USER, $PAGE, $OUTPUT;

$PAGE->set_url(new moodle_url('/local/sis/pay/payment_categories.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('paymentcategories', 'local_sis'));
$PAGE->set_heading(get_string('paymentcategories', 'local_sis'));

$PAGE->navbar->add(get_string('pluginname', 'local_sis'), new moodle_url('/local/sis/index.php'));
$PAGE->navbar->add(get_string('paymentsettings', 'local_sis'), new moodle_url('/local/sis/pay/payment_settings.php'));
$PAGE->navbar->add(get_string('paymentcategories', 'local_sis'));

echo $OUTPUT->header();

// Buttons
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

// Handle submission actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if (optional_param('save_categories', false, PARAM_BOOL)) {
        handle_category_payment_save();
    } elseif (optional_param('add_category', false, PARAM_BOOL)) {
        handle_category_payment_add();
    }
}

// Render main form
show_payment_categories_form();

echo html_writer::end_div();
echo $OUTPUT->footer();

/* ============================================================
   Functions
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
            get_string('coursefee', 'local_sis'),
            get_string('registrationfee', 'local_sis'),
            get_string('examfee', 'local_sis'),
            get_string('libraryfee', 'local_sis'),
            get_string('otherfee', 'local_sis'),
            get_string('currency', 'local_sis'),
            get_string('enabled', 'local_sis')
        ];

        foreach ($categories as $category) {
            $config = $payment_configs[$category->id] ?? null;

            $table->data[] = [
                format_string($category->name) .
                html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => "categories[{$category->id}][id]",
                    'value' => $config->id ?? 0
                ]),
                html_writer::empty_tag('input', [
                    'type' => 'number',
                    'name' => "categories[{$category->id}][course_fee]",
                    'value' => $config->course_fee ?? 0,
                    'min' => '0',
                    'step' => '0.01',
                    'class' => 'form-control form-control-sm'
                ]),
                html_writer::empty_tag('input', [
                    'type' => 'number',
                    'name' => "categories[{$category->id}][registration_fee]",
                    'value' => $config->registration_fee ?? 0,
                    'min' => '0',
                    'step' => '0.01',
                    'class' => 'form-control form-control-sm'
                ]),
                html_writer::empty_tag('input', [
                    'type' => 'number',
                    'name' => "categories[{$category->id}][exam_fee]",
                    'value' => $config->exam_fee ?? 0,
                    'min' => '0',
                    'step' => '0.01',
                    'class' => 'form-control form-control-sm'
                ]),
                html_writer::empty_tag('input', [
                    'type' => 'number',
                    'name' => "categories[{$category->id}][library_fee]",
                    'value' => $config->library_fee ?? 0,
                    'min' => '0',
                    'step' => '0.01',
                    'class' => 'form-control form-control-sm'
                ]),
                html_writer::empty_tag('input', [
                    'type' => 'number',
                    'name' => "categories[{$category->id}][other_fee]",
                    'value' => $config->other_fee ?? 0,
                    'min' => '0',
                    'step' => '0.01',
                    'class' => 'form-control form-control-sm'
                ]),
                html_writer::select(
                    [
                        'USD' => 'USD ($)',
                        'EUR' => 'EUR (€)',
                        'GBP' => 'GBP (£)',
                        'NGN' => 'NGN (&#8358;)',  // HTML entity for Naira
                        'KES' => 'KES (KSh)',
                        'GHS' => 'GHS (GH&#8373;)' // HTML entity for Ghana Cedi
                    ],
                    "categories[{$category->id}][currency]",
                    $config->currency ?? 'USD',
                    false,
                    ['class'=>'form-control form-control-sm']
                ),
                html_writer::empty_tag('input', [
                    'type'=>'checkbox',
                    'name'=>"categories[{$category->id}][enabled]",
                    'value'=>'1',
                    'class'=>'form-check-input',
                    'checked'=>!empty($config->enabled) ? 'checked' : ''
                ])
            ];
        }

        echo html_writer::table($table);
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group text-center');
    echo html_writer::empty_tag('input', [
        'type'=>'submit',
        'name'=>'save_categories',
        'value'=>get_string('savepaymentconfigs','local_sis'),
        'class'=>'btn btn-success btn-lg'
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}

function handle_category_payment_save() {
    global $DB;

    $categories = $_POST['categories'] ?? [];

    foreach ($categories as $categoryid => $data) {
        $categoryid = clean_param($categoryid, PARAM_INT);
        $id = isset($data['id']) ? clean_param($data['id'], PARAM_INT) : 0;
        $course_fee = isset($data['course_fee']) ? clean_param($data['course_fee'], PARAM_FLOAT) : 0;
        $registration_fee = isset($data['registration_fee']) ? clean_param($data['registration_fee'], PARAM_FLOAT) : 0;
        $exam_fee = isset($data['exam_fee']) ? clean_param($data['exam_fee'], PARAM_FLOAT) : 0;
        $library_fee = isset($data['library_fee']) ? clean_param($data['library_fee'], PARAM_FLOAT) : 0;
        $other_fee = isset($data['other_fee']) ? clean_param($data['other_fee'], PARAM_FLOAT) : 0;
        $currency = isset($data['currency']) ? clean_param($data['currency'], PARAM_TEXT) : 'USD';
        $enabled = isset($data['enabled']) ? 1 : 0;

        if ($id && $DB->record_exists('local_sis_payment_categories', ['id'=>$id])) {
            $rec = new stdClass();
            $rec->id = $id;
            $rec->course_fee = $course_fee;
            $rec->registration_fee = $registration_fee;
            $rec->exam_fee = $exam_fee;
            $rec->library_fee = $library_fee;
            $rec->other_fee = $other_fee;
            $rec->currency = $currency;
            $rec->enabled = $enabled;
            $rec->updated_at = time();
            $DB->update_record('local_sis_payment_categories', $rec);
        } else {
            $rec = new stdClass();
            $rec->categoryid = $categoryid;
            $rec->course_fee = $course_fee;
            $rec->registration_fee = $registration_fee;
            $rec->exam_fee = $exam_fee;
            $rec->library_fee = $library_fee;
            $rec->other_fee = $other_fee;
            $rec->currency = $currency;
            $rec->enabled = $enabled;
            $rec->created_at = time();
            $rec->updated_at = time();
            $DB->insert_record('local_sis_payment_categories', $rec);
        }
    }

    \core\notification::success(get_string('paymentconfigssaved','local_sis'));
}