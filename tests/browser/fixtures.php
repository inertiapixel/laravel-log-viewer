<?php

/**
 * Writes deterministic log files for the browser tests, with timestamps
 * relative to today so the default "Last 7 days" range has data.
 *
 * Usage: php tests/browser/fixtures.php <directory>
 */

$dir = $argv[1] ?? sys_get_temp_dir().'/ipx-log-viewer-browser';
@mkdir($dir, 0777, true);
array_map('unlink', glob($dir.'/*.log') ?: []);

$day = fn (int $daysAgo, string $time) => gmdate('Y-m-d', strtotime("-{$daysAgo} days")).' '.$time;
$line = fn (string $datetime, string $level, string $message, string $channel = 'production') => "[{$datetime}] {$channel}.".strtoupper($level).": {$message}\n";
$trace = "\n[stacktrace]\n#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php(779): Illuminate\\\\Database\\\\Connection->runQueryCallback()\n#1 /var/www/html/app/Http/Controllers/OrderController.php(42): Illuminate\\\\Database\\\\Connection->select()\n#2 {main}\n\"} \n";

$main = '';

for ($i = 0; $i < 70; $i++) {
    $daysAgo = $i % 7;
    $time = sprintf('%02d:%02d:%02d', 8 + intdiv($i, 7), ($i * 7) % 60, $i % 60);
    $main .= match ($i % 7) {
        0 => $line($day($daysAgo, $time), 'info', "User {$i} logged in {\"user_id\":{$i},\"ip\":\"203.0.113.9\"}"),
        1 => $line($day($daysAgo, $time), 'debug', "Cache miss for key users:{$i}"),
        2 => $line($day($daysAgo, $time), 'warning', 'Slow query detected {"sql":"select * from orders where status = ?","time_ms":1532}'),
        3 => rtrim($line($day($daysAgo, $time), 'error', 'SQLSTATE[42S02]: Base table or view not found {"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 42S02): SQLSTATE[42S02]: Base table or view not found at /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825)')).$trace,
        4 => $line($day($daysAgo, $time), 'notice', 'Password reset requested {"email":"jane@example.com","password":"hunter2","token":"abc123def456"}'),
        5 => $line($day($daysAgo, $time), 'info', 'Payment received {"order":"A-'.(1000 + $i).'","amount":42.5}', 'worker'),
        default => $line($day($daysAgo, $time), $i === 6 ? 'critical' : 'alert', 'Queue worker stopped unexpectedly'),
    };
}

$main .= $line($day(0, '23:00:00'), 'error', '<script>window.__ipxXss = true</script><img src=x onerror="window.__ipxXss=true"> & <b>bold</b>');
$main .= $line($day(0, '23:30:00'), 'info', "Multi-line message\nsecond line\nthird line");

file_put_contents($dir.'/laravel.log', $main);
touch($dir.'/laravel.log', time());

// A small second file, written in JSON lines.
$json = json_encode(['message' => 'Scheduled job finished', 'context' => ['job' => 'reports'], 'level' => 200, 'level_name' => 'INFO', 'channel' => 'scheduler', 'datetime' => gmdate('Y-m-d\TH:i:s.000000+00:00', strtotime('-1 day')), 'extra' => []])."\n";
file_put_contents($dir.'/scheduler.log', $json);
touch($dir.'/scheduler.log', time() - 86400);

echo $dir.PHP_EOL;
