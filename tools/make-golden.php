<?php
/**
 * Writes tests/fixtures/golden-payloads.json using the ORIGINAL OC4 library
 * (tests/fixtures/oc4-original, needs PHP 8.2+). Run once and commit the JSON.
 * Usage: /c/xampp/php/php.exe tools/make-golden.php
 */
$root = dirname(__DIR__);
foreach (array('CCCityScope', 'CCOrderAdapter', 'CCShipmentBuilder') as $c) {
    require $root . "/tests/fixtures/oc4-original/$c.php";
}
require $root . '/tests/fixtures/orders.php';

$out = array();
foreach (cc_fixture_cases() as $name => $case) {
    $adapter = new \Opencart\Extension\Couriercenter\Library\CCOrderAdapter($case['order'], $case['products']);
    $builder = new \Opencart\Extension\Couriercenter\Library\CCShipmentBuilder($adapter, $case['settings'], $case['parcels']);
    $payload = $builder->build_payload($case['service'], $case['boxnow'], $case['return']);
    $payload['shipmentDate'] = 'DATE';
    $out[$name] = array('validate_settings' => $builder->validate_settings(), 'validate_order' => $builder->validate_order(), 'payload' => $payload);
}
file_put_contents($root . '/tests/fixtures/golden-payloads.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
echo count($out) . " golden payloads written\n";
