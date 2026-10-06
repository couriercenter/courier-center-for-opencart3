<?php
/** OpenCart Config double. */
class FakeConfig {
    private $data;

    public function __construct(array $data = array()) {
        $this->data = $data;
    }
    public function get($key) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }
    public function set($key, $value) {
        $this->data[$key] = $value;
    }
    public function has($key) {
        return array_key_exists($key, $this->data);
    }
}
