<?php
defined('MOODLE_INTERNAL') || die();

class local_sis_payment_manager {
    
    public static function get_available_gateways() {
        global $DB;
        
        $gateways = ['flutterwave', 'paystack', 'paypal'];
        $available = [];
        
        foreach ($gateways as $gateway) {
            $enabled = $DB->get_record('local_sis_payment_config', [
                'gateway' => $gateway,
                'config_key' => 'enabled'
            ]);
            
            if ($enabled && $enabled->config_value) {
                $available[] = $gateway;
            }
        }
        
        return $available;
    }
    

public static function get_payment_by_id($paymentid) {
    global $DB;
    return $DB->get_record('local_sis_payments', ['id' => $paymentid]);
}
    public static function create_payment($userid, $amount, $currency, $gateway, $courseid = null, $metadata = []) {
        global $DB, $USER;
        
        $payment = new stdClass();
        $payment->userid = $userid;
        $payment->courseid = $courseid;
        $payment->amount = $amount;
        $payment->currency = $currency;
        $payment->gateway = $gateway;
        $payment->reference = self::generate_reference();
        $payment->status = 'pending';
        $payment->created_at = time();
        $payment->updated_at = time();
        $payment->metadata = json_encode($metadata);
        
        $paymentid = $DB->insert_record('local_sis_payments', $payment);
        return $paymentid;
    }
    
    public static function generate_reference() {
        return 'SIS_' . time() . '_' . rand(1000, 9999);
    }
    
    public static function get_payment_by_reference($reference) {
        global $DB;
        return $DB->get_record('local_sis_payments', ['reference' => $reference]);
    }
    
    public static function update_payment_status($reference, $status, $transaction_id = null, $payment_data = null) {
        global $DB;
        
        $payment = self::get_payment_by_reference($reference);
        if ($payment) {
            $payment->status = $status;
            $payment->updated_at = time();
            
            if ($transaction_id) {
                $payment->transaction_id = $transaction_id;
            }
            
            if ($payment_data) {
                $payment->payment_data = json_encode($payment_data);
            }
            
            $DB->update_record('local_sis_payments', $payment);
            
            // Trigger payment completion events
            if ($status === 'completed') {
                self::handle_successful_payment($payment);
            }
            
            return true;
        }
        
        return false;
    }
    
    public static function handle_successful_payment($payment) {
        // Handle post-payment actions (enroll in course, etc.)
        if ($payment->courseid) {
            self::enroll_user_in_course($payment->userid, $payment->courseid);
        }
        
        // Send confirmation email
        self::send_payment_confirmation($payment);
    }
    
    public static function enroll_user_in_course($userid, $courseid) {
        global $DB;
        
        // Use your existing enrollment function
        require_once(__DIR__ . '/../lib.php');
        return enroll_student_in_course($userid, $courseid);
    }
    
    public static function send_payment_confirmation($payment) {
        global $DB, $CFG;
        
        $user = $DB->get_record('user', ['id' => $payment->userid]);
        $course = $payment->courseid ? $DB->get_record('course', ['id' => $payment->courseid]) : null;
        
        $subject = get_string('paymentconfirmationsubject', 'local_sis');
        $message = get_string('paymentconfirmationmessage', 'local_sis', [
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'coursename' => $course ? $course->fullname : get_string('generalfee', 'local_sis')
        ]);
        
        email_to_user($user, $CFG->noreplyaddress, $subject, $message);
    }
}