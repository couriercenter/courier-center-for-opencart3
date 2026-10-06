<?php
require_once CC_REPO . '/tools/lib/lint_rules.php';

function lint54($code) {
    return cc_lint_php_source($code, '5.4');
}

function test_lint_php54_rejects_null_coalesce() {
    assert_count(1, lint54('<?php $a = $b ?? 1;'));
}
function test_lint_php54_rejects_return_types() {
    assert_true(count(lint54('<?php function f() : array { return array(); }')) >= 1);
}
function test_lint_php54_rejects_scalar_parameter_types() {
    assert_true(count(lint54('<?php function f(string $a) { return $a; }')) >= 1);
}
function test_lint_php54_rejects_closure_return_type_after_use() {
    assert_true(count(lint54('<?php $f = function ($a) use ($b): int { return 1; };')) >= 1);
}
function test_lint_php54_rejects_arrow_functions() {
    assert_true(count(lint54('<?php $f = fn($x) => $x;')) >= 1);
}
function test_lint_php54_accepts_plain_code() {
    assert_same(array(), lint54('<?php class A { public function f($a, array $b = array(), $c = null) { return isset($a["x"]) ? $a["x"] : (int)$c; } }'));
}
function test_lint_rejects_functions_missing_in_php72_on_every_tier() {
    foreach (array('5.4', '7.0', '7.2') as $tier) {
        assert_count(1, cc_lint_php_source('<?php if (str_contains($a, "x")) {}', $tier), $tier);
    }
}
function test_lint_ignores_methods_named_like_new_functions() {
    assert_same(array(), cc_lint_php_source('<?php $o->str_contains("x"); X::array_key_first(); function str_starts_with2() {}', '7.2'));
}
function test_lint_requires_guard_for_curl_close() {
    assert_count(1, cc_lint_php_source('<?php curl_close($ch);', '7.2'));
    assert_same(array(), cc_lint_php_source('<?php if (PHP_VERSION_ID < 80000) { curl_close($ch); }', '7.2'));
}
function test_twig_lint_requires_escaping() {
    assert_count(1, cc_lint_twig_source('<p>{{ name }}</p>'));
    assert_same(array(), cc_lint_twig_source('<p>{{ name|e }}</p><script>var u = {{ url|json_encode }};</script>{{ header }}{{ tab.content|raw }}{{ 5 }}{{ on ? \'true\' : \'false\' }}'));
}
function test_twig_lint_rejects_syntax_missing_in_some_versions() {
    assert_true(count(cc_lint_twig_source('{% for a in b if a %}{{ a|e }}{% endfor %}')) >= 1);
    assert_true(count(cc_lint_twig_source('{% apply upper %}x{% endapply %}')) >= 1);
    assert_true(count(cc_lint_twig_source('{{ items|filter(i => i > 1)|json_encode }}')) >= 1);
}
function test_twig_lint_ignores_verbatim_comments_and_js_arrows() {
    assert_same(array(), cc_lint_twig_source('{% verbatim %}{{tracking}}{% endverbatim %}{# {{ x }} #}<script>var f = (a) => a;</script>'));
}
function test_twig_lint_reports_line_numbers() {
    $p = cc_lint_twig_source("<p>\n\n{{ name }}</p>");
    assert_contains('line 3', $p[0]);
}
function test_lint_curl_close_guard_survives_greek_text_on_earlier_lines() {
    // "υ" is CF 85 in UTF-8; a non-unicode \R treats 0x85 as a line break and shifts every line number.
    assert_same(array(), cc_lint_php_source("<?php\n\$m = 'Η σύνδεση λειτουργεί';\nif (PHP_VERSION_ID < 80000) { curl_close(\$ch); }\n", '7.2'));
}
