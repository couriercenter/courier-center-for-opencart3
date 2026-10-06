<?php
/** Results + shared data of one smoke run. */
class SmokeContext {
    public $shop;
    public $failures = array();
    public $passes = 0;
    public $awbs = array();
    public $data = array();

    public function __construct(SmokeShop $shop) {
        $this->shop = $shop;
    }
    public function ok($condition, $what) {
        if ($condition) {
            $this->passes++;
            echo "  ok   $what\n";
            return true;
        }
        $this->fail($what);
        return false;
    }
    public function fail($what) {
        $this->failures[] = $what;
        echo "  FAIL $what\n";
    }
    public function track($awb) {
        if ((string)$awb !== '') {
            $this->awbs[] = (string)$awb;
        }
    }
    public function summary() {
        $s = sprintf("\n[%s] %d ok, %d failed\n", $this->shop->id, $this->passes, count($this->failures));
        foreach ($this->failures as $f) {
            $s .= "  - $f\n";
        }
        return $s;
    }
}
