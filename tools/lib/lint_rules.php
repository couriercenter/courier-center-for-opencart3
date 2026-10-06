<?php
/**
 * Static checks for the OpenCart 3 package that `php -l` cannot do.
 * Must run on PHP 7.2+ (the unit tests run it on every supported PHP).
 */

/** Functions added after PHP 7.2: calling one breaks shops that run older PHP. */
function cc_lint_newer_functions() {
    return array(
        'array_key_first', 'array_key_last', 'is_countable', 'hrtime', 'net_get_interfaces',
        'mb_str_split', 'password_algos', 'get_mangled_object_vars',
        'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'fdiv', 'get_resource_id', 'preg_last_error_msg',
        'array_is_list', 'enum_exists', 'fsync', 'fdatasync',
        'json_validate', 'mb_str_pad', 'str_increment', 'str_decrement',
        'array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
        'array_first', 'array_last',
    );
}

/** Neighbouring significant token: array(index, text, token id or null). */
function cc_lint_sibling(array $tokens, $i, $step) {
    $n = count($tokens);
    for ($j = $i + $step; $j >= 0 && $j < $n; $j += $step) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return array($j, is_array($t) ? $t[1] : $t, is_array($t) ? $t[0] : null);
    }
    return array(-1, '', null);
}

/** Index of the ')' that closes the '(' at $i, or -1. */
function cc_lint_close(array $tokens, $i) {
    $depth = 0;
    $n = count($tokens);
    for ($j = $i; $j < $n; $j++) {
        $t = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        if ($t === '(') {
            $depth++;
        } elseif ($t === ')') {
            $depth--;
            if ($depth === 0) {
                return $j;
            }
        }
    }
    return -1;
}

function cc_lint_line($code, $line) {
    // Not \R: without the u flag it also splits on byte 0x85, which is inside UTF-8 Greek ("υ" = CF 85).
    $lines = preg_split('/\r\n|\r|\n/', $code);
    return isset($lines[$line - 1]) ? $lines[$line - 1] : '';
}

/**
 * @param string $code PHP source
 * @param string $tier '5.4' | '7.0' | '7.2' (spec 5.2)
 * @return string[] "line N: problem"
 */
function cc_lint_php_source($code, $tier) {
    $problems = array();
    $tokens = token_get_all($code);
    $n = count($tokens);
    $newer = array_flip(cc_lint_newer_functions());
    $forbidden54 = array();
    foreach (array(
        'T_COALESCE' => '?? needs PHP 7.0', 'T_COALESCE_EQUAL' => '??= needs PHP 7.4', 'T_SPACESHIP' => '<=> needs PHP 7.0',
        'T_ELLIPSIS' => '... needs PHP 5.6', 'T_POW' => '** needs PHP 5.6', 'T_YIELD' => 'yield needs PHP 5.5',
        'T_YIELD_FROM' => 'yield from needs PHP 7.0', 'T_FINALLY' => 'finally needs PHP 5.5', 'T_FN' => 'arrow functions need PHP 7.4',
        'T_MATCH' => 'match needs PHP 8.0', 'T_NULLSAFE_OBJECT_OPERATOR' => '?-> needs PHP 8.0', 'T_ATTRIBUTE' => 'attributes need PHP 8.0',
    ) as $const => $msg) {
        if (defined($const)) {
            $forbidden54[constant($const)] = $msg;
        }
    }
    $scalar = array('string', 'int', 'float', 'bool', 'iterable', 'object', 'mixed', 'void', 'never', 'null', 'false', 'true');
    $ellipsis = defined('T_ELLIPSIS') ? T_ELLIPSIS : -1;

    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        list($id, $text, $line) = $tokens[$i];

        // Every tier: functions that do not exist in PHP 7.2.
        if ($id === T_STRING && isset($newer[strtolower($text)])) {
            list(, $prev) = cc_lint_sibling($tokens, $i, -1);
            list(, $next) = cc_lint_sibling($tokens, $i, 1);
            if ($next === '(' && !in_array(strtolower($prev), array('->', '::', 'function', 'new', '?->'), true)) {
                $problems[] = "line $line: $text() does not exist in PHP 7.2";
            }
        }
        // Every tier: curl_close() is deprecated in PHP 8.5, allowed only behind a version check.
        if ($id === T_STRING && strtolower($text) === 'curl_close' && strpos(cc_lint_line($code, $line), 'PHP_VERSION_ID < 80000') === false) {
            $problems[] = "line $line: curl_close() must be guarded with PHP_VERSION_ID < 80000 (deprecated in PHP 8.5)";
        }
        if ($tier !== '5.4') {
            continue;
        }
        if (isset($forbidden54[$id])) {
            $problems[] = "line $line: " . $forbidden54[$id];
        }
        if ($id === T_STRING && strtolower($text) === 'fn') {
            list(, $next) = cc_lint_sibling($tokens, $i, 1);
            if ($next === '(') {
                $problems[] = "line $line: arrow functions need PHP 7.4";
            }
        }
        if ($id === T_CLASS) {
            list(, $prev) = cc_lint_sibling($tokens, $i, -1);
            if ($prev === '::') {
                $problems[] = "line $line: ::class needs PHP 5.5";
            }
        }
        if ($id === T_FUNCTION) {
            $open = $i + 1;
            while ($open < $n && $tokens[$open] !== '(') {
                $open++;
            }
            $close = cc_lint_close($tokens, $open);
            if ($close < 0) {
                continue;
            }
            for ($k = $open + 1; $k < $close; $k++) {
                $tk = $tokens[$k];
                if ($tk === '?') {
                    $problems[] = "line $line: nullable types need PHP 7.1";
                }
                if (is_array($tk) && $tk[0] === T_STRING && in_array(strtolower($tk[1]), $scalar, true)) {
                    list(, $ntext, $nid) = cc_lint_sibling($tokens, $k, 1);
                    if ($nid === T_VARIABLE || $ntext === '&' || $nid === $ellipsis) {
                        $problems[] = "line $line: scalar type '" . $tk[1] . "' needs PHP 7.0";
                    }
                }
            }
            list($p, $after) = cc_lint_sibling($tokens, $close, 1);
            if (strtolower($after) === 'use') {
                list($useOpen) = cc_lint_sibling($tokens, $p, 1);
                $useClose = cc_lint_close($tokens, $useOpen);
                $after = '';
                if ($useClose > 0) {
                    list(, $after) = cc_lint_sibling($tokens, $useClose, 1);
                }
            }
            if ($after === ':') {
                $problems[] = "line $line: return types need PHP 7.0";
            }
        }
    }
    return array_values(array_unique($problems));
}

/** Is a {{ … }} expression escaped / safe for the given rules (spec 5.6)? */
function cc_lint_twig_safe_output($expr) {
    $expr = trim($expr);
    if (in_array($expr, array('header', 'column_left', 'footer'), true)) {
        return true;
    }
    return (bool)(preg_match('/^-?[0-9]+$/', $expr)
        || preg_match("/^'[^']*'$/", $expr)
        || preg_match("/\\|\\s*(e|escape)\\s*(\\(\\s*'(html|html_attr|js|url|css)'\\s*\\))?\\s*$/", $expr)
        || preg_match('/\|\s*json_encode\s*(\([^)]*\))?\s*$/', $expr)
        || preg_match('/\|\s*raw\s*$/', $expr)
        || preg_match("/^[a-z_][a-z0-9_.]*\\s*\\?\\s*'[^']*'\\s*:\\s*'[^']*'$/i", $expr));
}

/** @return string[] "line N: problem" for a .twig source */
function cc_lint_twig_source($code) {
    $problems = array();
    // Ignore {% verbatim %} blocks and {# comments #}, keeping line numbers.
    $clean = preg_replace_callback('/\{%-?\s*verbatim\s*-?%\}.*?\{%-?\s*endverbatim\s*-?%\}|\{#.*?#\}/s', function ($m) {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $code);
    preg_match_all('/\{\{-?(.*?)-?\}\}|\{%-?(.*?)-?%\}/s', $clean, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    foreach ($matches as $m) {
        $line = substr_count(substr($clean, 0, $m[0][1]), "\n") + 1;
        $is_output = substr($m[0][0], 0, 2) === '{{';
        $inner = trim($is_output ? $m[1][0] : $m[2][0]);
        if (strpos($inner, '=>') !== false) {
            $problems[] = "line $line: arrow functions do not exist in Twig 1.24";
        }
        if (!$is_output) {
            if (preg_match('/^for\b.*\bif\b/s', $inner)) {
                $problems[] = "line $line: for ... if was removed in Twig 3";
            }
            if (preg_match('/^(spaceless|filter|apply|endspaceless|endfilter|endapply)\b/', $inner, $t)) {
                $problems[] = "line $line: {% " . $t[1] . " %} is not available in every Twig version";
            }
            continue;
        }
        if (preg_match('/\|\s*(filter|map|reduce|spaceless)\b/', $inner, $t)) {
            $problems[] = "line $line: |" . $t[1] . " is not available in Twig 1.24";
        }
        if (!cc_lint_twig_safe_output($inner)) {
            $problems[] = "line $line: unescaped output {{ $inner }} (use |e, |json_encode inside <script>, or |raw for ready HTML)";
        }
    }
    return $problems;
}
