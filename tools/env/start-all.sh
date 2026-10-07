#!/usr/bin/env bash
# Starts the PHP built-in servers of every "builtin" shop of tools/env/envs.php (3020, 3038, 3041, 3051p85),
# each with ITS php version, plus the mail sink (SMTP 1025 + API 8025) and the telemetry/updater sink (8799).
# Usage (Git Bash, NOT the WSL "bash" of cmd):  "C:\Program Files\Git\bin\bash.exe" tools/env/start-all.sh
# Apache (shop 3051) and MySQL come from the XAMPP Control Panel.
cd "$(dirname "$0")/../.."
PHP82=/c/xampp/php/php.exe
"$PHP82" -r '$c = require "tools/config.php"; foreach (require "tools/env/envs.php" as $e) { if ($e["serve"] === "builtin") { echo $c["php"][$e["php"]], "|", $e["port"], "|", $e["dir"], PHP_EOL; } }' > /tmp/cc-builtin.txt
while IFS='|' read -r bin port dir; do
    bin=$(printf '%s' "$bin" | tr -d '\r'); dir=$(printf '%s' "$dir" | tr -d '\r')
    [ -n "$bin" ] && "$bin" -S 127.0.0.1:"$port" -t "$dir" &
done < /tmp/cc-builtin.txt
# Mailpit's .exe is blocked by the endpoint security of this PC: a PHP SMTP sink with a Mailpit-shaped API stands in.
"$PHP82" tools/env/mailsink.php &
"$PHP82" -S 127.0.0.1:8025 tools/env/mailapi.php &
"$PHP82" -S 127.0.0.1:8799 tools/env/sink.php &
wait
