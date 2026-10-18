<?php

declare(strict_types=1);

// Intercept every handle operation before loading the client. No real cURL handle or network.
namespace App\Service {
    function curl_init(string $url): object { return new \stdClass(); }
    function curl_setopt_array(object $handle, array $options): bool { return true; }
    function curl_exec(object $handle): false { return false; }
    function curl_errno(object $handle): int { return $GLOBALS['fakeCurlCode']; }
    function curl_close(object $handle): void { ++$GLOBALS['closedHandles']; }
    function curl_getinfo(object $handle, int $option): never { throw new \RuntimeException('Unexpected successful request.'); }
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
    echo "OK: {$checks} connection failures; intercepted cURL only, no environment or network.\n";
}
