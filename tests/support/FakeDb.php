<?php
/** Result object shaped like OpenCart's DB query() result. */
class FakeDbResult {
    public $row;
    public $rows;
    public $num_rows;

    public function __construct(array $rows) {
        $this->rows = array_values($rows);
        $this->row = $rows ? $this->rows[0] : array();
        $this->num_rows = count($rows);
    }
}

/** OpenCart DB double: canned rows per SQL regex (latest registration wins), records every query. */
class FakeDb {
    public $queries = array();
    private $handlers = array();

    /** @param array|Closure $rows rows, or function (string $sql, array $matches): array */
    public function on($regex, $rows) {
        array_unshift($this->handlers, array($regex, $rows));
        return $this;
    }

    public function query($sql) {
        $sql = (string)$sql;
        $this->queries[] = $sql;
        foreach ($this->handlers as $h) {
            if (preg_match($h[0], $sql, $m)) {
                return new FakeDbResult($h[1] instanceof Closure ? call_user_func($h[1], $sql, $m) : $h[1]);
            }
        }
        return new FakeDbResult(array());
    }

    public function escape($value) {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string)$value);
    }

    public function getLastId() {
        return 1;
    }

    public function countAffected() {
        return 1;
    }

    /** @return string[] queries matching $regex, in order */
    public function matching($regex) {
        return array_values(array_filter($this->queries, function ($q) use ($regex) {
            return preg_match($regex, $q) === 1;
        }));
    }
}
