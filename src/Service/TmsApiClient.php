<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\TmsApiException;
use Closure;
use JsonException;
use LogicException;

final class TmsApiClient
{
    private const DATASETS = ['exhibitions', 'loans', 'exhibition_objects', 'exhibition_loans',
        'loan_objects', 'objects', 'object_titles', 'parties', 'documents', 'shipments'];

    /** @param null|Closure(string, list<string>): array{status: int, body: string} $transport */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $url,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly ?Closure $transport = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getManifest(): array
    {
        $data = $this->request([]);
        if (!is_array($data['datasets'] ?? null)) {
            throw new TmsApiException('TMS API returned an invalid manifest.');
        }
        return $data;
    }

    public function getPage(string $dataset, int $offset = 0, int $limit = 500): array
    {
        if (!in_array($dataset, self::DATASETS, true)
            || $offset < 0 || $offset > PHP_INT_MAX - 1001 || $limit < 1 || $limit > 1000) {
            throw new LogicException('Invalid TMS API page request.');
        }
        $data = $this->request(['dataset' => $dataset, 'offset' => $offset, 'limit' => $limit]);
        if (($data['dataset'] ?? null) !== $dataset
            || ($data['offset'] ?? null) !== $offset || ($data['limit'] ?? null) !== $limit
            || !is_array($data['data'] ?? null) || !array_is_list($data['data'])
            || ($data['count'] ?? null) !== count($data['data']) || count($data['data']) > $limit
            || !array_key_exists('next_offset', $data)
            || ($data['next_offset'] !== null && ($data['next_offset'] !== $offset + $limit || count($data['data']) !== $limit))) {
            throw new TmsApiException(sprintf('TMS API returned an inconsistent page (dataset %s, offset %d); discard the incomplete import.', $dataset, $offset));
        }
        return $data;
    }

    private function request(array $query): array
    {
        // Check before configuration validation or creating a transport: disabled means no outbound calls.
        if (!$this->enabled) {
            throw new LogicException('External loan retrieval is disabled.');
        }
        $parts = parse_url($this->url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $this->url)) {
            throw new TmsApiException('TMS_API_URL must be an HTTPS endpoint without credentials, query or fragment.');
        }
        if ($this->username === '' || $this->password === ''
            || preg_match('/[:\x00-\x1f\x7f]/', $this->username)
            || preg_match('/[\x00-\x1f\x7f]/', $this->password)
            || str_contains($this->username, 'CHANGE_ME') || str_contains($this->password, 'CHANGE_ME')) {
            throw new TmsApiException('TMS API credentials are incomplete or invalid.');
        }
        $url = $this->url . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $headers = ['Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password)];
        $context = $query === [] ? 'manifest' : sprintf('dataset %s, offset %d', $query['dataset'], $query['offset']);
        $response = $this->transport === null ? $this->send($url, $headers, $context) : ($this->transport)($url, $headers);
        if ($response['status'] !== 200) {
            // Do not attach the raw response body: proxies may include request details or credentials.
            throw new TmsApiException(sprintf('TMS API returned HTTP %d (%s).', $response['status'], $context), $response['status']);
        }
        try {
            $data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TmsApiException(sprintf('TMS API returned invalid JSON (%s).', $context));
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 2) {
            throw new TmsApiException(sprintf('TMS API returned an unsupported response (%s); expected schema_version 2.', $context));
        }
        return $data;
    }

    private function send(string $url, #[\SensitiveParameter] array $headers, string $context): array
    {
        if (!function_exists('curl_init')) {
            throw new TmsApiException('The PHP cURL extension is required for the TMS API connection.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TmsApiException(sprintf('Cannot initialize the TMS API connection (%s).', $context));
        }
        try {
            if (!curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false, // Never forward credentials to a redirect destination.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'Condition Reports TMS client',
            ])) {
                throw new TmsApiException(sprintf('Cannot configure the TMS API connection (%s).', $context));
            }
            $body = curl_exec($handle);
            if ($body === false) {
                $code = curl_errno($handle);
                // The fixed cURL description identifies DNS/TLS/timeout errors without request details.
                throw new TmsApiException(sprintf('The TMS API connection failed (%s): cURL %d (%s).',
                    $context, $code, curl_strerror($code) ?: 'Unknown transport error'));
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }
}
