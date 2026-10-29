<?php

declare(strict_types=1);

/*
 * Minimal HTTP server for transport tests, run as a child process by FakeHttpServer.
 *
 * Usage: php fake-http-server.php <log file> <scenario JSON>
 *
 * The scenario is a list of actions, one per accepted connection:
 *   {"status": 200}           read the request, answer with this status
 *   {"raw": "..."}            read the request, write these bytes as the response
 *   {"stallMs": 2000}         read the request, answer nothing for this long, then close
 *   {"close": true}           close the connection without reading or answering
 *
 * Every request read is appended to the log file as one base64-encoded line. The server prints
 * its port on the first line of standard output and exits after the last action.
 */

$logFile = $argv[1] ?? '';
$scenario = json_decode($argv[2] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
if ('' === $logFile || !is_array($scenario)) {
    fwrite(STDERR, "Usage: php fake-http-server.php <log file> <scenario JSON>\n");
    exit(1);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (false === $server) {
    fwrite(STDERR, $errorMessage . "\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

foreach ($scenario as $action) {
    if (!is_array($action)) {
        exit(1);
    }

    $connection = stream_socket_accept($server, 30);
    if (false === $connection) {
        exit(1);
    }

    if (true === ($action['close'] ?? false)) {
        fclose($connection);

        continue;
    }

    stream_set_timeout($connection, 10);
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) {
        $request .= (string) fread($connection, 65_536);
    }

    $length = 1 === preg_match('/\r\nContent-Length: (\d+)\r\n/i', $request, $matches) ? (int) $matches[1] : 0;
    $bodyStart = (int) strpos($request, "\r\n\r\n") + 4;
    while (strlen($request) - $bodyStart < $length && !feof($connection)) {
        $request .= (string) fread($connection, 65_536);
    }

    file_put_contents($logFile, base64_encode($request) . "\n", FILE_APPEND);

    $stallMs = $action['stallMs'] ?? null;
    $raw = $action['raw'] ?? null;
    $status = $action['status'] ?? 200;
    if (is_int($stallMs)) {
        usleep($stallMs * 1_000);
    } elseif (is_string($raw)) {
        fwrite($connection, $raw);
    } elseif (is_int($status)) {
        fwrite($connection, sprintf("HTTP/1.1 %d Test\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $status));
    }

    fclose($connection);
}
