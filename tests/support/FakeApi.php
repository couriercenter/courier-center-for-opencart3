<?php
require_once CC_LIB . '/CCApi.php';

/** CCApi double: canned response (or Closure) per method; records every call. */
class FakeApi extends \Opencart\Extension\Couriercenter\Library\CCApi {
    public $calls = array();
    public $responses = array();

    public function __construct(array $responses = array()) {
        parent::__construct('alias', 'cred', 'key', 'billing');
        $this->responses = $responses;
    }
    private function answer($method, array $args) {
        $this->calls[] = array($method, $args);
        $r = isset($this->responses[$method]) ? $this->responses[$method] : null;
        return $r instanceof Closure ? call_user_func_array($r, $args) : $r;
    }
    public function test_connection(): array {
        return (array)$this->answer('test_connection', array());
    }
    public function create_shipment(array $payload): array {
        return (array)$this->answer('create_shipment', array($payload));
    }
    public function get_voucher_pdf(string $awb_numbers, string $template = 'cleanpdf') {
        return $this->answer('get_voucher_pdf', array($awb_numbers, $template));
    }
    public function void_shipment(string $awb) {
        return $this->answer('void_shipment', array($awb));
    }
    public function get_shipment_details(string $awb): array {
        return (array)$this->answer('get_shipment_details', array($awb));
    }
    public function get_manifest(string $date) {
        return $this->answer('get_manifest', array($date));
    }
}
