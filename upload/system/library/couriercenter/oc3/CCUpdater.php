<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/** Self-update from a release zip: GitHub releases, or a local release in test shops (spec 5.10). */
class CCUpdater {
    const GITHUB_REPO    = 'couriercenter/courier-center-for-opencart3';
    const ASSET_NAME     = 'courier_center_oc3.ocmod.zip';
    const CHECK_INTERVAL = 43200;

    /** Same allow-list as OpenCart 3's Extension Installer (tools/lib/package.php keeps a copy; a test compares them). */
    public static function allowedPrefixes() {
        return array(
            'admin/controller/extension/', 'admin/language/', 'admin/model/extension/', 'admin/view/image/',
            'admin/view/javascript/', 'admin/view/stylesheet/', 'admin/view/template/extension/',
            'catalog/controller/extension/', 'catalog/language/', 'catalog/model/extension/',
            'catalog/view/javascript/', 'catalog/view/theme/', 'system/config/', 'system/library/', 'image/catalog/',
        );
    }

    /**
     * @param string[] $entries zip entry names
     * @return string[] file paths relative to upload/
     * @throws \RuntimeException anything outside upload/ or the allow-list, "..", or not our package
     */
    public static function payloadFiles(array $entries) {
        $files = array();
        foreach ($entries as $name) {
            $name = str_replace('\\', '/', (string)$name);
            if ($name === '' || substr($name, -1) === '/') {
                continue;
            }
            if (strpos($name, 'upload/') !== 0) {
                throw new \RuntimeException('Μη αναμενόμενο αρχείο στο zip: ' . $name);
            }
            $rel = substr($name, 7);
            if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || preg_match('/^[a-z]:/i', $rel)) {
                throw new \RuntimeException('Μη έγκυρη διαδρομή στο zip: ' . $name);
            }
            $allowed = false;
            foreach (self::allowedPrefixes() as $prefix) {
                if (strpos($rel, $prefix) === 0) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new \RuntimeException('Διαδρομή εκτός των επιτρεπτών φακέλων: ' . $rel);
            }
            $files[] = $rel;
        }
        if (!in_array('system/library/couriercenter/version.json', $files, true)) {
            throw new \RuntimeException('Το zip δεν είναι πακέτο Courier Center για OpenCart 3.');
        }
        return $files;
    }

    /** Destination of a file of upload/ ($dirs: application, catalog, system, image). Renamed admin folders work. */
    public static function target($rel, array $dirs) {
        $map = array('admin/' => 'application', 'catalog/' => 'catalog', 'system/' => 'system', 'image/' => 'image');
        foreach ($map as $top => $key) {
            if (strpos($rel, $top) === 0) {
                return rtrim(str_replace('\\', '/', $dirs[$key]), '/') . '/' . substr($rel, strlen($top));
            }
        }
        throw new \RuntimeException('Άγνωστος φάκελος: ' . $rel);
    }

    /** @throws \RuntimeException naming the first place PHP cannot write to */
    public static function assertWritable(array $files, array $dirs) {
        foreach ($files as $rel) {
            $dest = self::target($rel, $dirs);
            $dir = dirname($dest);
            while (!is_dir($dir) && dirname($dir) !== $dir) {
                $dir = dirname($dir);
            }
            if ((is_file($dest) && !is_writable($dest)) || !is_writable($dir)) {
                throw new \RuntimeException('Ο φάκελος ' . $dir . ' δεν είναι εγγράψιμος από την PHP. Ανέβασε το νέο ' . self::ASSET_NAME . ' από Extensions → Installer.');
            }
        }
    }

    /** Copy the extracted package over the shop. Old copies go to $backup_dir; any failure restores everything written. */
    public static function install($src_root, array $files, array $dirs, $backup_dir) {
        $written = array();
        $backed = array();
        try {
            foreach ($files as $rel) {
                $dest = self::target($rel, $dirs);
                if (is_file($dest)) {
                    $copy = rtrim($backup_dir, '/') . '/' . $rel;
                    self::mkdirFor($copy);
                    if (!@copy($dest, $copy)) {
                        throw new \RuntimeException('Αποτυχία backup: ' . $rel);
                    }
                    $backed[$dest] = $copy;
                }
                self::mkdirFor($dest);
                if (!@copy(rtrim($src_root, '/') . '/upload/' . $rel, $dest)) {
                    throw new \RuntimeException('Δεν ήταν δυνατή η εγγραφή του ' . $dest);
                }
                $written[] = $dest;
            }
        } catch (\Exception $e) {
            foreach ($written as $dest) {
                if (isset($backed[$dest])) {
                    @copy($backed[$dest], $dest);
                } else {
                    @unlink($dest);
                }
            }
            throw $e;
        }
    }

    public static function mkdirFor($file) {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Δεν δημιουργήθηκε ο φάκελος ' . $dir);
        }
    }

    /** Remove compiled Twig templates so changed .twig files are used (3.0.3+: cache/template, 3.0.2.0: 2-hex folders). */
    public static function clearTemplateCache($dir_cache) {
        $dir_cache = rtrim(str_replace('\\', '/', $dir_cache), '/');
        self::rrmdir($dir_cache . '/template');
        $dirs = glob($dir_cache . '/*', GLOB_ONLYDIR);
        foreach (is_array($dirs) ? $dirs : array() as $d) {
            if (preg_match('/^[0-9a-f]{2}$/', basename($d))) {
                self::rrmdir($d);
            }
        }
    }

    public static function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
            $p = $dir . '/' . $f;
            if (is_dir($p) && !is_link($p)) {
                self::rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /** @return array ['version','notes','zip_url'] or ['error' => message] */
    public static function latestRelease(CCState $state, $timeout = 20) {
        $o = $state->devOverrides();
        $url = !empty($o['release_api']) ? (string)$o['release_api'] : 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_HTTPHEADER => array('User-Agent: CourierCenter-OC3-Updater', 'Accept: application/vnd.github+json'),
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        if ($err !== '') {
            return array('error' => 'Σφάλμα σύνδεσης με το GitHub: ' . $err);
        }
        if ($code === 404) {
            return array('error' => 'Δεν βρέθηκε release (404).');
        }
        if ($code !== 200) {
            return array('error' => 'GitHub HTTP ' . $code);
        }
        $rel = json_decode((string)$body, true);
        if (!is_array($rel)) {
            return array('error' => 'Μη έγκυρη απάντηση GitHub.');
        }
        $zip = '';
        $assets = (isset($rel['assets']) && is_array($rel['assets'])) ? $rel['assets'] : array();
        foreach ($assets as $a) {
            if (isset($a['name'], $a['browser_download_url']) && $a['name'] === self::ASSET_NAME) {
                $zip = (string)$a['browser_download_url'];
                break;
            }
        }
        $notes = (string)($rel['body'] ?? '');
        return array(
            'version' => ltrim((string)($rel['tag_name'] ?? ''), 'vV'),
            'notes'   => function_exists('mb_substr') ? mb_substr($notes, 0, 1200, 'UTF-8') : substr($notes, 0, 1200),
            'zip_url' => $zip,
        );
    }

    public static function download($url, $dest, $timeout = 180) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Δεν μπορώ να γράψω στο ' . dirname($dest));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true,
            CURLOPT_TIMEOUT => (int)$timeout, CURLOPT_HTTPHEADER => array('User-Agent: CourierCenter-OC3-Updater')));
        $ok = curl_exec($ch);
        $err = (string)curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        fclose($fp);
        if (!$ok) {
            throw new \RuntimeException('Αποτυχία λήψης: ' . $err);
        }
    }

    public static function isNewer($latest, $current) {
        return (string)$latest !== '' && version_compare((string)$latest, (string)$current, '>');
    }

    /** Version inside an extracted package ('' when missing). */
    public static function packageVersion($src_root) {
        $file = rtrim($src_root, '/') . '/upload/system/library/couriercenter/version.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return (is_array($data) && isset($data['version'])) ? (string)$data['version'] : '';
    }
}
