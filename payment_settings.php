<?php
// This file is part of Local SIS Plugin

// ? Correct path (pay folder is 2 levels deep under local/sis/)
require_once(__DIR__ . '/../../../config.php');

// Debug: Check if basic Moodle is loaded
if (!isset($CFG)) {
    die('Moodle config not loaded');
}

// Debug: Check required files
$required_files = [
    __DIR__ . '/../../../config.php' => file_exists(__DIR__ . '/../../../config.php'),
    $CFG->libdir . '/formslib.php' => file_exists($CFG->libdir . '/formslib.php'),
    __DIR__ . '/../lib.php' => file_exists(__DIR__ . '/../lib.php') // ? fixed
];

foreach ($required_files as $file => $exists) {
    if (!$exists) {
        echo "Missing file: $file<br>";
    }
}

require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php'); // ? correct reference to main lib

try {
    require_login();
    
    if (!has_capability('moodle/site:config', context_system::instance())) {
        throw new moodle_exception('nopermissions', 'error', '', 'moodle/site:config');
    }

    global $DB, $USER, $PAGE, $OUTPUT;

    // ? Correct URL for pay folder
    $PAGE->set_url(new moodle_url('/local/sis/pay/payment_settings.php'));
    $PAGE->set_context(context_system::instance());
    $PAGE->set_title('Payment Settings');
    $PAGE->set_heading('Payment Settings');

    $PAGE->navbar->add('SIS', new moodle_url('/local/sis/index.php'));
    $PAGE->navbar->add('Payment Settings');

    if ($_POST && confirm_sesskey()) {
        handle_payment_settings_save();
    }

    echo $OUTPUT->header();

    echo html_writer::link(
        new moodle_url('/local/sis/index.php'),
        'Back to Dashboard',
        ['class' => 'btn btn-secondary mb-3']
    );

    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/sis/pay/payment_categories.php'),
            'Manage Payment Categories',
            ['class' => 'btn btn-info mb-3']
        )
    );

    show_payment_settings_form();

    echo $OUTPUT->footer();

} catch (Exception $e) {
    if (isset($OUTPUT)) {
        echo $OUTPUT->header();
        echo '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        echo $OUTPUT->footer();
    } else {
        echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">';
        echo '<h3>Error</h3>';
        echo '<p>' . $e->getMessage() . '</p>';
        echo '<pre>Debug: ' . print_r($required_files, true) . '</pre>';
        echo '</div>';
    }
}



function handle_payment_settings_save() {
    global $DB;
    
    $gateways = ['flutterwave', 'paystack', 'paypal'];
    
    foreach ($gateways as $gateway) {
        $enabled = optional_param("{$gateway}_enabled", 0, PARAM_INT);
        $configs = [
            'enabled' => $enabled,
            'test_mode' => optional_param("{$gateway}_test_mode", 0, PARAM_INT),
            'public_key' => optional_param("{$gateway}_public_key", '', PARAM_TEXT),
            'secret_key' => optional_param("{$gateway}_secret_key", '', PARAM_TEXT),
            'currency' => optional_param("{$gateway}_currency", 'USD', PARAM_TEXT)
        ];
        
        if ($gateway === 'paypal') {
            $configs['client_id'] = optional_param("{$gateway}_client_id", '', PARAM_TEXT);
            $configs['client_secret'] = optional_param("{$gateway}_client_secret", '', PARAM_TEXT);
        }
        
        foreach ($configs as $key => $value) {
            $tables = $DB->get_tables();
            if (!in_array('local_sis_payment_config', $tables)) {
                echo '<div class="alert alert-warning">Table local_sis_payment_config does not exist yet.</div>';
                return;
            }
            
            $record = $DB->get_record('local_sis_payment_config', [
                'gateway' => $gateway,
                'config_key' => $key
            ]);
            
            $is_secret = in_array($key, ['secret_key', 'client_secret']);
            $time = time();
            
            if ($record) {
                $record->config_value = $value;
                $record->is_secret = $is_secret ? 1 : 0;
                $record->timemodified = $time;
                $DB->update_record('local_sis_payment_config', $record);
            } else {
                $record = new stdClass();
                $record->gateway = $gateway;
                $record->config_key = $key;
                $record->config_value = $value;
                $record->is_secret = $is_secret ? 1 : 0;
                $record->timecreated = $time;
                $record->timemodified = $time;
                $DB->insert_record('local_sis_payment_config', $record);
            }
        }
    }
    
    echo '<div class="alert alert-success">Payment settings saved successfully!</div>';
}



function show_payment_settings_form() {
    global $DB;
    
    $gateways = [
        'flutterwave' => 'Flutterwave',
        'paystack' => 'Paystack', 
        'paypal' => 'PayPal'
    ];
    
    echo '<div class="container-fluid">';
    echo '<form method="post">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    
    foreach ($gateways as $gateway => $name) {
        echo '<div class="card mb-4">';
        echo '<div class="card-header bg-primary text-white">';
        echo '<h5 class="mb-0">' . $name . ' Configuration</h5>';
        echo '</div>';
        echo '<div class="card-body">';
        
        // Enabled checkbox
        $enabled = get_payment_config($gateway, 'enabled');
        echo '<div class="form-group row">';
        echo '<label class="col-sm-3 col-form-label font-weight-bold">Enable</label>';
        echo '<div class="col-sm-9">';
        echo '<div class="form-check">';
        echo '<input type="checkbox" name="' . $gateway . '_enabled" value="1" class="form-check-input mr-2" ' . ($enabled ? 'checked' : '') . '>';
        echo '<label class="form-check-label">Enable ' . $name . '</label>';
        echo '</div></div></div>';
        
        // Test mode
        $test_mode = get_payment_config($gateway, 'test_mode');
        echo '<div class="form-group row">';
        echo '<label class="col-sm-3 col-form-label">Test Mode</label>';
        echo '<div class="col-sm-9"><div class="form-check">';
        echo '<input type="checkbox" name="' . $gateway . '_test_mode" value="1" class="form-check-input mr-2" ' . ($test_mode ? 'checked' : '') . '>';
        echo '<label class="form-check-label">Test Mode</label>';
        echo '</div></div></div>';
        
        // Public Key
        $public_key = get_payment_config($gateway, 'public_key');
        echo '<div class="form-group row">';
        echo '<label class="col-sm-3 col-form-label">Public Key</label>';
        echo '<div class="col-sm-9">';
        echo '<input type="text" name="' . $gateway . '_public_key" value="' . $public_key . '" class="form-control" placeholder="Enter public key">';
        echo '</div></div>';
        
        // Secret Key
        $secret_key = get_payment_config($gateway, 'secret_key');
        echo '<div class="form-group row">';
        echo '<label class="col-sm-3 col-form-label">Secret Key</label>';
        echo '<div class="col-sm-9">';
        echo '<input type="password" name="' . $gateway . '_secret_key" value="' . $secret_key . '" class="form-control" placeholder="Enter secret key">';
        echo '</div></div>';
        
        if ($gateway === 'paypal') {
            $client_id = get_payment_config($gateway, 'client_id');
            echo '<div class="form-group row">';
            echo '<label class="col-sm-3 col-form-label">Client ID</label>';
            echo '<div class="col-sm-9">';
            echo '<input type="text" name="' . $gateway . '_client_id" value="' . $client_id . '" class="form-control" placeholder="Enter client ID">';
            echo '</div></div>';
            
            $client_secret = get_payment_config($gateway, 'client_secret');
            echo '<div class="form-group row">';
            echo '<label class="col-sm-3 col-form-label">Client Secret</label>';
            echo '<div class="col-sm-9">';
            echo '<input type="password" name="' . $gateway . '_client_secret" value="' . $client_secret . '" class="form-control" placeholder="Enter client secret">';
            echo '</div></div>';
        }
        
        // Currency
        $currency = get_payment_config($gateway, 'currency');
        echo '<div class="form-group row">';
        echo '<label class="col-sm-3 col-form-label">Currency</label>';
        echo '<div class="col-sm-9">';
        echo '<select name="' . $gateway . '_currency" class="form-control">';
        $currencies = [
            'USD' => 'USD ($)',
            'EUR' => 'EUR (€)',
            'GBP' => 'GBP (£)',
            'NGN' => 'NGN (?)',
            'KES' => 'KES (KSh)',
            'GHS' => 'GHS (GH?)'
        ];
        foreach ($currencies as $code => $text) {
            echo '<option value="' . $code . '" ' . ($currency == $code ? 'selected' : '') . '>' . $text . '</option>';
        }
        echo '</select></div></div>';
        
        echo '</div></div>';
    }
    
    echo '<div class="form-group text-center mt-4">';
    echo '<input type="submit" value="Save Changes" class="btn btn-success btn-lg px-5">';
    echo '</div>';
    
    echo '</form>';
    echo '</div>';
}


function get_payment_config($gateway, $key) {
    global $DB;
    
    try {
        $tables = $DB->get_tables();
        if (!in_array('local_sis_payment_config', $tables)) {
            return '';
        }
        
        $record = $DB->get_record('local_sis_payment_config', [
            'gateway' => $gateway,
            'config_key' => $key
        ]);
        return $record ? $record->config_value : '';
    } catch (Exception $e) {
        return '';
    }
}