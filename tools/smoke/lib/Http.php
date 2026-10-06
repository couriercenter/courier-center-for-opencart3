<?php
/** Small cookie-keeping HTTP client for the smoke tests. */
class SmokeHttp {
    private $jar;

    public function __construct($jar_file) {
        $this->jar = $jar_file;
        @unlink($jar_file);
    }

    /** @return array ['status' => int, 'headers' => string, 'body' => string, 'location' => string] */
    public function request($method, $url, array $fields = null, array $files = null, $follow = false) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 240,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_USERAGENT => 'cc-smoke',
        );
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($files) {
                $post = $fields ? $fields : array();
                foreach ($files as $name => $path) {
                    $post[$name] = new CURLFile($path, 'application/zip', basename($path));
                }
                $opts[CURLOPT_POSTFIELDS] = $post;
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($fields ? $fields : array());
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch) . " ($url)");
        }
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headers = substr($raw, 0, $size);
        $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
        return array('status' => $status, 'headers' => $headers, 'body' => (string)substr($raw, $size), 'location' => $location);
    }

    public function get($url, $follow = false) {
        return $this->request('GET', $url, null, null, $follow);
    }

    public function post($url, array $fields, array $files = null) {
        return $this->request('POST', $url, $fields, $files);
    }
}
