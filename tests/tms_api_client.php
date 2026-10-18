<?php

declare(strict_types=1);

// Offline only. No kernel, .env, Composer bootstrap or native HTTP transport.
require dirname(__DIR__) . '/src/Exception/TmsApiException.php';
require dirname(__DIR__) . '/src/Service/TmsApiClient.php';

use App\Service\TmsApiClient;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function rejects(Closure $call, string $messagePart): void
{
    try {
        $call();
    } catch (LogicException|RuntimeException $error) {
        check(str_contains($error->getMessage(), $messagePart), 'Unexpected error: ' . $error->getMessage());
        check(!str_contains($error->getMessage(), 'SECRET_DO_NOT_INCLUDE'), 'Raw response details leaked into the exception.');
        return;
    }
    throw new RuntimeException('Expected request to be rejected.');
}

$manifest = ['schema_version' => 2, 'datasets' => ['loans' => ['enabled' => true]]];
$page = $manifest + ['dataset' => 'loans', 'offset' => 0, 'limit' => 2, 'count' => 2,
    'next_offset' => 2, 'data' => [['LoanID' => '1'], ['LoanID' => '2']]];
$response = ['status' => 200, 'body' => json_encode($manifest, JSON_THROW_ON_ERROR)];
$requests = [];
$transport = static function (string $url, array $headers) use (&$requests, &$response): array {
    $requests[] = ['url' => $url, 'headers' => $headers];
    return $response;
};
$make = static fn(array $args = []): TmsApiClient => new TmsApiClient(...array_replace([
    'enabled' => true, 'url' => 'https://example.invalid/index.php', 'username' => 'test-client',
    'password' => 'FAKE:password', 'transport' => $transport,
], $args));

$disabled = $make(['enabled' => false, 'url' => '', 'username' => '', 'password' => '']);
check(!$disabled->isEnabled(), 'Switch must report disabled.');
rejects(static fn() => $disabled->getManifest(), 'disabled');
rejects(static fn() => $disabled->getPage('loans'), 'disabled');
check($requests === [], 'Disabled client made a request.');

$client = $make();
check($client->isEnabled(), 'Enabled switch was not applied.');
check($client->getManifest() === $manifest, 'Manifest changed.');
check($requests[0]['url'] === 'https://example.invalid/index.php', 'Unexpected manifest URL.');
check(in_array('Authorization: Basic ' . base64_encode('test-client:FAKE:password'), $requests[0]['headers'], true), 'Basic credentials missing.');
check($requests[0]['headers'] === ['Accept: application/json', 'Authorization: Basic ' . base64_encode('test-client:FAKE:password')], 'Only Basic credentials should be sent.');
check(!str_contains($requests[0]['url'], 'FAKE'), 'Credentials leaked into URL.');

$response['body'] = json_encode($page, JSON_THROW_ON_ERROR);
check($client->getPage('loans', 0, 2) === $page, 'Page changed.');
parse_str(parse_url($requests[1]['url'], PHP_URL_QUERY), $query);
check($query === ['dataset' => 'loans', 'offset' => '0', 'limit' => '2'], 'Dataset or pagination not forwarded.');

$before = count($requests);
foreach (['http://example.invalid/index.php', 'https://user:pass@example.invalid/index.php',
    'https://example.invalid/index.php?query=bad', 'https://example.invalid/#fragment', "https://example.invalid/\r\ninjected"] as $url) {
    rejects(static fn() => $make(['url' => $url])->getManifest(), 'HTTPS endpoint');
}
foreach ([['username' => ''], ['username' => 'bad:name'], ['password' => ''], ['password' => "bad\npassword"]] as $args) {
    rejects(static fn() => $make($args)->getManifest(), 'credentials');
}
rejects(static fn() => $client->getPage('arbitrary-table'), 'Invalid');
rejects(static fn() => $client->getPage('loans', -1), 'Invalid');
rejects(static fn() => $client->getPage('loans', 0, 1001), 'Invalid');
check(count($requests) === $before, 'Rejected request reached transport.');

foreach ([301, 302, 401, 409, 500, 503] as $status) {
    $response = ['status' => $status, 'body' => 'SECRET_DO_NOT_INCLUDE'];
    rejects(static fn() => $client->getManifest(), "HTTP {$status}");
    rejects(static fn() => $client->getManifest(), 'manifest');
    rejects(static fn() => $client->getPage('loans', 500), 'dataset loans, offset 500');
}
$response = ['status' => 200, 'body' => '{broken'];
rejects(static fn() => $client->getManifest(), 'invalid JSON');
rejects(static fn() => $client->getPage('loans', 500), 'dataset loans, offset 500');
$response['body'] = json_encode(array_replace($manifest, ['schema_version' => 999]));
rejects(static fn() => $client->getManifest(), 'unsupported');
foreach ([['dataset' => 'objects'],
    ['offset' => 1], ['count' => 99], ['next_offset' => 0], ['data' => [['LoanID' => '1']]]] as $change) {
    $response['body'] = json_encode(array_replace($page, $change), JSON_THROW_ON_ERROR);
    rejects(static fn() => $client->getPage('loans', 0, 2), 'inconsistent');
    rejects(static fn() => $client->getPage('loans', 0, 2), 'dataset loans, offset 0');
}

if (!function_exists('curl_init')) {
    rejects(static fn() => $make(['transport' => null])->getManifest(), 'PHP cURL extension');
}

echo "OK: {$checks} checks; fake HTTP transport only, no environment or network.\n";
