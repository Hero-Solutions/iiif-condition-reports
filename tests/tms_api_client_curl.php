<?php

declare(strict_types=1);

// Intercept every handle operation before loading the client. No real cURL handle or network.
namespace App\Service {
    function curl_init(string $url): object { return new \stdClass(); }
    function curl_setopt_array(object $handle, array $options): bool { $GLOBALS['curlOptions'] = $options; return true; }
    function curl_exec(object $handle): bool {
        if ($GLOBALS['beforeTransfer'] !== null) ($GLOBALS['beforeTransfer'])();
        foreach ($GLOBALS['curlChunks'] as $chunk) {
            if (($GLOBALS['curlOptions'][CURLOPT_WRITEFUNCTION])($handle, $chunk) !== strlen($chunk)) {
                throw new \RuntimeException('The body callback did not accept all bytes.');
            }
        }
        return $GLOBALS['fakeCurlCode'] === 0;
    }
    function curl_errno(object $handle): int { return $GLOBALS['fakeCurlCode']; }
    function curl_close(object $handle): void { ++$GLOBALS['closedHandles']; }
    function curl_getinfo(object $handle, int $option = 0): mixed {
        return match ($option) {
            0 => $GLOBALS['curlInfo'],
            CURLINFO_RESPONSE_CODE => $GLOBALS['curlInfo']['http_code'],
            CURLINFO_APPCONNECT_TIME => $GLOBALS['curlInfo']['appconnect_time'],
            default => throw new \RuntimeException('Unexpected cURL info requested.'),
        };
    }
    function curl_error(object $handle): never { throw new \RuntimeException('Raw request-specific errors must not be read.'); }
}

namespace {
    use App\Exception\TmsApiException;
    use App\Service\TmsApiClient;

    // Only constants and fixed error descriptions come from the real extension.
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Run this isolated test with the cURL extension enabled.');
    }
    require dirname(__DIR__) . '/src/Exception/TmsApiException.php';
    require dirname(__DIR__) . '/src/Service/TmsApiClient.php';

    $checks = 0;
    $closedHandles = 0;
    $beforeTransfer = null;
    $curlChunks = [];
    $curlInfo = ['http_code' => 0, 'primary_ip' => '', 'content_type' => null,
        'namelookup_time' => 0, 'connect_time' => 0, 'appconnect_time' => 0, 'starttransfer_time' => 0, 'total_time' => 60];
    $client = new TmsApiClient(true, 'https://fixtures.invalid/loan_projects.php', 'fake-user', 'FAKE-password');
    foreach ([6, 7, 28, 60] as $fakeCurlCode) {
        foreach (['manifest', 'dataset loans, offset 500'] as $context) {
            try {
                if ($context === 'manifest') $client->getManifest();
                else $client->getPage('loans', 500);
                throw new RuntimeException('Expected a connection failure.');
            } catch (TmsApiException $error) {
                if (!str_contains($error->getMessage(), $context)
                    || !str_contains($error->getMessage(), 'cURL ' . $fakeCurlCode)
                    || !str_contains($error->getMessage(), curl_strerror($fakeCurlCode))) {
                    throw new RuntimeException('Lost connection diagnostic: ' . $error->getMessage());
                }
                foreach (['fixtures.invalid', 'fake-user', 'FAKE-password', base64_encode('fake-user:FAKE-password')] as $private) {
                    if (str_contains($error->getMessage(), $private)) throw new RuntimeException('Request details leaked.');
                }
                ++$checks;
            }
        }
    }
    if ($closedHandles !== 8) throw new RuntimeException('Failed request handles were not closed.');

    $messages = [];
    $trace = static function (string $message) use (&$messages): void { $messages[] = $message; };
    $beforeTransfer = static function () use (&$messages): void {
        if (!str_contains(implode("\n", $messages), 'GET https://fixtures.invalid/loan_projects.php?dataset=objects&offset=0&limit=500')) {
            throw new RuntimeException('The request must be visible before waiting for the network.');
        }
    };
    $fakeCurlCode = 28;
    foreach ([false, true] as $partial) {
        $messages = [];
        $curlInfo['http_code'] = $partial ? 200 : 0;
        $curlInfo['primary_ip'] = '192.0.2.12';
        $curlInfo['namelookup_time'] = 0.01;
        $curlInfo['connect_time'] = 0.02;
        $curlInfo['appconnect_time'] = 0.03;
        $curlInfo['starttransfer_time'] = $partial ? 0.04 : 0;
        $curlChunks = $partial ? ['{"schema_version":2,"error":"partial FAKE-', 'password ' . base64_encode('fake-user:FAKE-password') . "\x1b[31m"] : [];
        try {
            $client->getPage('objects', 0, 500, $trace);
            throw new RuntimeException('Expected a timeout.');
        } catch (TmsApiException $error) {
            $text = implode("\n", $messages);
            foreach (['192.0.2.12', 'DNS 0.010 s', 'TCP 0.020 s', 'TLS 0.030 s', '60.000 s', 'cURL 28', 'overdracht niet voltooid'] as $part) {
                if (!str_contains($text, $part)) throw new RuntimeException('Missing transfer diagnostic: ' . $part);
            }
            if ($partial && (!str_contains($text, 'HTTP 200') || !str_contains($text, 'partial') || !str_contains($text, '[redacted]'))) {
                throw new RuntimeException('Partial response was lost on timeout.');
            }
            if (!$partial && (!str_contains($text, 'geen status ontvangen') || !str_contains($text, 'geen bytes ontvangen'))) {
                throw new RuntimeException('No-response timeout is ambiguous.');
            }
            foreach (['FAKE-password', 'fake-user', base64_encode('fake-user:FAKE-password'), "\x1b"] as $secret) {
                if (str_contains($text, $secret)) throw new RuntimeException('Unsafe diagnostic output.');
            }
            ++$checks;
        }
    }
    $messages = [];
    $fakeCurlCode = 0;
    $curlInfo['http_code'] = 200;
    $page = ['schema_version' => 2, 'dataset' => 'objects', 'offset' => 0, 'limit' => 500, 'count' => 0, 'next_offset' => null, 'data' => []];
    $curlChunks = str_split(json_encode($page), 11);
    if ($client->getPage('objects', 0, 500, $trace) !== $page) throw new RuntimeException('Chunked response changed on success.');
    if (!str_contains(implode("\n", $messages), 'Pagina gecontroleerd: objects, offset 0, 0 records')) throw new RuntimeException('Missing page validation progress.');
    if ($closedHandles !== 11) throw new RuntimeException('Verbose transfer leaked a handle.');
    ++$checks;
    echo "OK: {$checks} transfer checks; intercepted cURL only, no environment or network.\n";
}
