<?php
require_once CC_LIB . '/CCOrderAdapter.php';

use Opencart\Extension\Couriercenter\Library\CCOrderAdapter;

function test_lib_adapter_oc3_cod_is_detected_from_payment_code() {
    $a = new CCOrderAdapter(array('payment_code' => 'cod', 'payment_method' => 'Αντικαταβολή'));
    assert_same('cod', $a->get_payment_method());
    assert_true($a->is_cod());
}
function test_lib_adapter_oc3_other_payment_is_not_cod() {
    $a = new CCOrderAdapter(array('payment_code' => 'bank_transfer', 'payment_method' => 'Τραπεζική κατάθεση'));
    assert_false($a->is_cod());
}
function test_lib_adapter_payment_code_wins_over_the_title() {
    $a = new CCOrderAdapter(array('payment_code' => 'bank_transfer', 'payment_method' => 'Cod-like title'));
    assert_false($a->is_cod());
}
function test_lib_adapter_oc4_shapes_still_work() {
    assert_true((new CCOrderAdapter(array('payment_method' => array('code' => 'cod.cod'))))->is_cod());
    assert_true((new CCOrderAdapter(array('payment_method' => '{"code":"cod.cod"}')))->is_cod());
    assert_false((new CCOrderAdapter(array('payment_method' => array('code' => 'bank_transfer.bank_transfer'))))->is_cod());
}
function test_lib_adapter_prefers_shipping_address_and_strips_postcode_spaces() {
    $a = new CCOrderAdapter(array('shipping_postcode' => '114 73', 'payment_postcode' => '99999', 'shipping_city' => '', 'payment_city' => 'Αθήνα'));
    assert_same('11473', $a->get_billing_postcode());
    assert_same('Αθήνα', $a->get_billing_city());
}
