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
    /** cc_locks rows (name => [token, locked_at]): a tiny in-memory table for CCLock. */
    public $locks = array();

    public function __construct() {
        $self = $this;
        $this->on("/^INSERT IGNORE INTO `\w*cc_locks` SET `name` = '([^']*)', `token` = '([^']*)', `locked_at` = '(\d+)'/", function ($sql, $m) use ($self) {
            if (!isset($self->locks[$m[1]])) {
                $self->locks[$m[1]] = array('token' => $m[2], 'locked_at' => $m[3]);
            }
            return array();
        });
        $this->on("/^SELECT `token`, `locked_at` FROM `\w*cc_locks` WHERE `name` = '([^']*)'/", function ($sql, $m) use ($self) {
            return isset($self->locks[$m[1]]) ? array($self->locks[$m[1]]) : array();
        });
        $this->on("/^DELETE FROM `\w*cc_locks` WHERE `name` = '([^']*)' AND `token` = '([^']*)'/", function ($sql, $m) use ($self) {
            if (isset($self->locks[$m[1]]) && $self->locks[$m[1]]['token'] === $m[2]) {
                unset($self->locks[$m[1]]);
            }
            return array();
        });
    }

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
