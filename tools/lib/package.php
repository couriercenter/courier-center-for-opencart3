<?php
/** Paths the OpenCart 3 Extension Installer accepts (admin/controller/marketplace/install.php, spec 4.1). */
function cc_allowed_prefixes() {
    return array(
        'admin/controller/extension/', 'admin/language/', 'admin/model/extension/', 'admin/view/image/',
        'admin/view/javascript/', 'admin/view/stylesheet/', 'admin/view/template/extension/',
        'catalog/controller/extension/', 'catalog/language/', 'catalog/model/extension/',
        'catalog/view/javascript/', 'catalog/view/theme/', 'system/config/', 'system/library/', 'image/catalog/',
    );
}

/** @param string $rel path inside upload/ */
function cc_path_allowed($rel) {
    foreach (cc_allowed_prefixes() as $prefix) {
        if (strpos($rel, $prefix) === 0) {
            return true;
        }
    }
    return false;
}
