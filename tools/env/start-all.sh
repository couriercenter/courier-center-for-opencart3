#!/usr/bin/env bash
# Starts the PHP built-in servers of shops 3020/3038, the mail sink (SMTP 1025 + API 8025) and the sink.
# Run with the Bash tool and run_in_background: true. Apache + MySQL come from the XAMPP Control Panel.
cd "$(dirname "$0")/../.."
PHP82=/c/xampp/php/php.exe
"$PHP82" -r '$c = require "tools/config.php"; echo $c["php"]["7.2"], "\n", $c["php"]["7.4"], "\n";' > /tmp/cc-php-bins.txt
PHP72=$(sed -n 1p /tmp/cc-php-bins.txt | tr -d '\r')
PHP74=$(sed -n 2p /tmp/cc-php-bins.txt | tr -d '\r')
"$PHP72" -S 127.0.0.1:8720 -t C:/Users/python/oc3-envs/oc3020 &
"$PHP74" -S 127.0.0.1:8738 -t C:/Users/python/oc3-envs/oc3038 &
# Mailpit's .exe is blocked by the endpoint security of this PC: a PHP SMTP sink with a Mailpit-shaped API stands in.
"$PHP82" tools/env/mailsink.php &
"$PHP82" -S 127.0.0.1:8025 tools/env/mailapi.php &
"$PHP82" -S 127.0.0.1:8799 tools/env/sink.php &
wait
