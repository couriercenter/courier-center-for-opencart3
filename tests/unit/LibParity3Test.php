<?php
/** Round 3 (full WooCommerce comparison): street number field, completion email, handled methods, pseudo-cron, email toggles. */
require_once CC_REPO . '/tests/support/FakeConfig.php';


use Opencart\Extension\Couriercenter\Library\CCMailContent;
use Opencart\Extension\Couriercenter\Library\CCOrderAdapter;
use Opencart\Extension\Couriercenter\Library\CCPseudoCron;
use Opencart\Extension\Couriercenter\Library\CCReturnsSettings;
use Opencart\Extension\Couriercenter\Library\CCShipmentBuilder;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;

function cc_p3_builder(array $order, array $settings = array()) {
    list($svc) = cc_service($settings);
    return new CCShipmentBuilder(new CCOrderAdapter(cc_oc3_order($order), array(array('weight' => 1, 'quantity' => 1))), $svc->builderSettings());
}

function test_lib_street_number_comes_from_the_chosen_custom_field() {
    $s = array('shipping_courier_center_street_number_field' => '7');
    $b = cc_p3_builder(array('shipping_custom_field' => array('7' => ' 15 ', '8' => 'x')), $s);
    assert_same('Σόλωνος 5 15', $b->get_consignee_address());
    assert_same('Σόλωνος 5 15, Αθήνα 10671', $b->get_consignee_address_preview());
    assert_same('Σόλωνος 5 15', $b->build_payload()['Consignee']['Address']);
}
function test_lib_street_number_falls_back_to_the_payment_address_and_is_not_repeated() {
    $s = array('shipping_courier_center_street_number_field' => '7');
    $b = cc_p3_builder(array('shipping_custom_field' => array(), 'payment_custom_field' => '{"7":"22"}'), $s);
    assert_same('Σόλωνος 5 22', $b->get_consignee_address(), 'payment custom field, JSON string');
    // Already a whole word of the address: not added again; "15" is not found inside "155".
    $b2 = cc_p3_builder(array('shipping_address_1' => 'Βενιζέλου 15', 'shipping_custom_field' => array('7' => '15')), $s);
    assert_same('Βενιζέλου 15', $b2->get_consignee_address());
    $b3 = cc_p3_builder(array('shipping_address_1' => 'Βενιζέλου 155', 'shipping_custom_field' => array('7' => '15')), $s);
    assert_same('Βενιζέλου 155 15', $b3->get_consignee_address());
}
function test_lib_street_number_off_by_default() {
    $b = cc_p3_builder(array('shipping_custom_field' => array('7' => '15')));
    assert_same('Σόλωνος 5', $b->get_consignee_address());
}

function test_lib_auto_complete_defaults_to_the_first_complete_status() {
    $complete = array('shipping_courier_center_auto_complete_status_id' => '', 'config_complete_status' => array('5', '3'));
    list($svc) = cc_service($complete);
    assert_same(5, $svc->autoCompleteStatusId(), 'unset = first complete status');
    list($svc) = cc_service(array('shipping_courier_center_auto_complete_status_id' => '0', 'config_complete_status' => array('5')));
    assert_same(0, $svc->autoCompleteStatusId(), '0 = switched off');
    list($svc) = cc_service(array('shipping_courier_center_auto_complete_status_id' => '3', 'config_complete_status' => array('5')));
    assert_same(3, $svc->autoCompleteStatusId());
    list($svc) = cc_service(array('config_complete_status' => array()));
    assert_same(0, $svc->autoCompleteStatusId(), 'no complete status in the shop');
    assert_true($svc->autoCompleteNotify());
    list($svc) = cc_service(array('shipping_courier_center_auto_complete_notify' => '0'));
    assert_false($svc->autoCompleteNotify());
}

function test_lib_tracker_tells_the_customer_only_when_it_recorded_the_delivery() {
    $api = new FakeApi(array('get_shipment_details' => cc_details('29', 'Παραδόθηκε')));
    $told = array();
    $notify = function ($order_id) use (&$told) {
        $told[] = $order_id;
    };
    // The delivery is recorded by this refresh (status changed) → email.
    $db = cc_tracker_db(2);
    (new CCStatusTracker($db, 'oc_', $api, 5, array(), $notify))->refresh(array('order_id' => 7, 'voucher_number' => 'A', 'status_code' => '10'));
    assert_same(array(7), $told);
    // An order left behind (delivery already stored): completed, but silently.
    $db = cc_tracker_db(2);
    $r = (new CCStatusTracker($db, 'oc_', $api, 5, array(), $notify))->refresh(array('order_id' => 8, 'voucher_number' => 'B', 'status_code' => '29'));
    assert_true($r['completed']);
    assert_same(array(7), $told, 'no email for the catch-up completion');
    // Not completed → no email.
    $db = cc_tracker_db(2);
    (new CCStatusTracker($db, 'oc_', $api, 0, array(), $notify))->refresh(array('order_id' => 9, 'voucher_number' => 'C', 'status_code' => '10'));
    assert_same(array(7), $told);
    // A failing notifier never breaks the update.
    $db = cc_tracker_db(2);
    $r = (new CCStatusTracker($db, 'oc_', $api, 5, array(), function () {
        throw new \RuntimeException('smtp down');
    }))->refresh(array('order_id' => 10, 'voucher_number' => 'D', 'status_code' => '10'));
    assert_true($r['success'] && $r['completed']);
}

function test_lib_order_completed_email() {
    $m = CCMailContent::orderCompletedEmail(array('order_id' => 12, 'firstname' => 'Μαρία'),
        array(array('name' => 'Μπλούζα &amp; Σκούφος', 'quantity' => 2)),
        array('voucher_number' => 'AWB9', 'tracking_number' => 'AWB9', 'is_voided' => '0'), 'https://t.example/?n={{tracking}}');
    assert_same('Η παραγγελία #12 ολοκληρώθηκε', $m['subject']);
    assert_contains('Γεια σας Μαρία,', $m['text']);
    assert_contains('Μπλούζα & Σκούφος × 2', $m['text']);
    assert_contains('https://t.example/?n=AWB9', $m['html']);
    assert_contains('Στοιχεία Αποστολής Courier Center', $m['text']);
}

function test_lib_handled_methods_need_at_least_one() {
    $r = CCVoucherService::sanitizeHandled(array('courier_center', ' flat ', 'flat', ''), array('old'));
    assert_same(array('courier_center', 'flat'), $r['codes']);
    assert_same('', $r['warning']);
    $r = CCVoucherService::sanitizeHandled(array(), array('old', ''));
    assert_same(array('old'), $r['codes'], 'empty submission keeps the saved list');
    assert_contains('Επιλέξτε τουλάχιστον μία', $r['warning']);
    $r = CCVoucherService::sanitizeHandled(null, array());
    assert_same(array(), $r['codes']);
    assert_contains('τουλάχιστον μία', $r['warning']);
}

function test_lib_pseudo_cron_is_due_only_after_two_hours_without_a_run() {
    $now = 1000000;
    assert_false(CCPseudoCron::due($now, $now - 3600, 0, true), 'ran an hour ago');
    assert_false(CCPseudoCron::due($now, $now - 7200, 0, true), 'exactly 2h: the external cron may be just late');
    assert_true(CCPseudoCron::due($now, $now - 7600, 0, true));
    assert_true(CCPseudoCron::due($now, 0, 0, true), 'never ran');
    assert_false(CCPseudoCron::due($now, $now - 9000, $now - 100, true), 'fired a moment ago');
    assert_true(CCPseudoCron::due($now, $now - 9000, $now - 1000, true));
    assert_false(CCPseudoCron::due($now, 0, 0, false), 'switched off');
    assert_false(CCPseudoCron::fire(''), 'no URL');
    assert_false(CCPseudoCron::fire('ftp://x'), 'only http(s)');
}

function test_lib_returns_customer_emails_can_be_switched_off() {
    $on = new CCReturnsSettings(new FakeConfig(array()));
    foreach (array('received', 'created', 'rejected') as $e) {
        assert_true($on->customerEmailEnabled($e), "$e on by default");
    }
    $off = new CCReturnsSettings(new FakeConfig(array('shipping_courier_center_returns_email_created' => '0')));
    assert_false($off->customerEmailEnabled('created'));
    assert_true($off->customerEmailEnabled('received'));
    $post = array();
    CCReturnsSettings::sanitizePost($post, array());
    assert_same('1', $post['shipping_courier_center_returns_email_received'], 'not posted → stays on');
    $post = array('shipping_courier_center_returns_email_rejected' => '0');
    CCReturnsSettings::sanitizePost($post, array());
    assert_same('0', $post['shipping_courier_center_returns_email_rejected']);
}
