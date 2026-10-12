<?php
require_once CC_LIB . '/CCCityScope.php';
require_once CC_LIB . '/CCOrderAdapter.php';
require_once CC_LIB . '/CCShipmentBuilder.php';
require_once CC_REPO . '/tests/fixtures/orders.php';

/** Same payloads/validations as the original OC4 code (spec 7.3), except the voucher comments: only the customer's note (no "Order #id", it is in Reference1) - parity with WooCommerce 1.4.5. */
function test_lib_golden_payloads_match_the_original_oc4_library() {
    $golden = json_decode(file_get_contents(CC_REPO . '/tests/fixtures/golden-payloads.json'), true);
    assert_true(is_array($golden) && count($golden) === 10, 'golden fixtures missing');
    foreach (cc_fixture_cases() as $name => $case) {
        $adapter = new \Opencart\Extension\Couriercenter\Library\CCOrderAdapter($case['order'], $case['products']);
        $builder = new \Opencart\Extension\Couriercenter\Library\CCShipmentBuilder($adapter, $case['settings'], $case['parcels']);
        $payload = $builder->build_payload($case['service'], $case['boxnow'], $case['return']);
        $payload['shipmentDate'] = 'DATE';
        $actual = json_decode(json_encode(array(
            'validate_settings' => $builder->validate_settings(),
            'validate_order' => $builder->validate_order(),
            'payload' => $payload,
        ), JSON_PRESERVE_ZERO_FRACTION), true);
        assert_same($golden[$name], $actual, "case $name");
    }
}
