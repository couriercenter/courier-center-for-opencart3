<?php
class AssertionFailed extends Exception {}

function cc_export($v) {
    return str_replace("\n", "\n    ", var_export($v, true));
}
function assert_same($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . 'Expected: ' . cc_export($expected) . "\nActual:   " . cc_export($actual));
    }
}
function assert_true($condition, $message = '') {
    if ($condition !== true) {
        throw new AssertionFailed($message !== '' ? $message : 'Expected true, got ' . cc_export($condition));
    }
}
function assert_false($condition, $message = '') {
    if ($condition !== false) {
        throw new AssertionFailed($message !== '' ? $message : 'Expected false, got ' . cc_export($condition));
    }
}
function assert_contains($needle, $haystack, $message = '') {
    if (strpos((string)$haystack, (string)$needle) === false) {
        throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . "Expected to find: $needle\nIn: $haystack");
    }
}
function assert_not_contains($needle, $haystack, $message = '') {
    if (strpos((string)$haystack, (string)$needle) !== false) {
        throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . "Did not expect: $needle\nIn: $haystack");
    }
}
function assert_count($n, array $items, $message = '') {
    if (count($items) !== $n) {
        throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . "Expected $n item(s), got " . count($items) . ': ' . cc_export($items));
    }
}
function assert_throws($class, $fn, $message = '') {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . "Expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . "Expected $class to be thrown");
}
