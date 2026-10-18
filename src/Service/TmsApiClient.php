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

    /** @param null|callable(string): void $trace */
    public function getManifest(?callable $trace = null): array
    {
        $data = $this->request([], $trace);
        if (!is_array($data['datasets'] ?? null)) {
            throw new TmsApiException('TMS API returned an invalid manifest.');
        }
        return $data;
    }

    /** @param null|callable(string): void $trace */
    public function getPage(string $dataset, int $offset = 0, int $limit = 500, ?callable $trace = null): array
    {
        if (!in_array($dataset, self::DATASETS, true)
            || $offset < 0 || $offset > PHP_INT_MAX - 1001 || $limit < 1 || $limit > 1000) {
            throw new LogicException('Invalid TMS API page request.');
        }
        $data = $this->request(['dataset' => $dataset, 'offset' => $offset, 'limit' => $limit], $trace);
        if (($data['dataset'] ?? null) !== $dataset
            || ($data['offset'] ?? null) !== $offset || ($data['limit'] ?? null) !== $limit
            || !is_array($data['data'] ?? null) || !array_is_list($data['data'])
            || ($data['count'] ?? null) !== count($data['data']) || count($data['data']) > $limit
            || !array_key_exists('next_offset', $data)
            || ($data['next_offset'] !== null && ($data['next_offset'] !== $offset + $limit || count($data['data']) !== $limit))) {
            throw new TmsApiException(sprintf('TMS API returned an inconsistent page (dataset %s, offset %d); discard the incomplete import.', $dataset, $offset));
        }
        if ($trace !== null) $trace(sprintf('Pagina gecontroleerd: %s, offset %d, %d records, volgende offset %s.',
            $dataset, $offset, $data['count'], $data['next_offset'] === null ? 'geen' : (string) $data['next_offset']));
        return $data;
    }

    private function request(array $query, ?callable $trace): array
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
        if ($trace !== null) $trace('GET ' . $url . ' (verbindingstime-out 10 s, totale time-out 60 s)');
        if ($this->transport === null) {
            $response = $this->send($url, $headers, $context, $trace);
        } else {
            $started = microtime(true);
            $response = ($this->transport)($url, $headers);
            $this->traceResponse($trace, $response['status'], $response['body'], microtime(true) - $started, false);
        }
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

    private function send(string $url, #[\SensitiveParameter] array $headers, string $context, ?callable $trace): array
    {
        if (!function_exists('curl_init')) {
            throw new TmsApiException('The PHP cURL extension is required for the TMS API connection.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TmsApiException(sprintf('Cannot initialize the TMS API connection (%s).', $context));
        }
        $body = '';
        try {
            if (!curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                // Retain bytes already received when curl_exec fails midway through a response.
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    $body .= $chunk;
                    return strlen($chunk);
                },
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
            $completed = curl_exec($handle);
            $code = curl_errno($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($trace !== null) {
                $info = curl_getinfo($handle);
                $trace(sprintf('Netwerk: IP %s; content-type %s; tijden sinds aanvraag: DNS %.3f s, TCP %.3f s, TLS %.3f s, eerste byte %.3f s; cURL %d.',
                    $this->diagnosticText((string) ($info['primary_ip'] ?? '')),
                    $this->diagnosticText((string) ($info['content_type'] ?? '')),
                    $info['namelookup_time'] ?? 0, $info['connect_time'] ?? 0,
                    curl_getinfo($handle, CURLINFO_APPCONNECT_TIME), $info['starttransfer_time'] ?? 0, $code));
                $this->traceResponse($trace, $status, $body, (float) ($info['total_time'] ?? 0), $completed === false);
            }
            if ($completed === false) {
                // The fixed cURL description identifies DNS/TLS/timeout errors without request details.
                throw new TmsApiException(sprintf('The TMS API connection failed (%s): cURL %d (%s).',
                    $context, $code, curl_strerror($code) ?: 'Unknown transport error'));
            }
            return ['status' => $status, 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }

    private function traceResponse(?callable $trace, int $status, string $body, float $seconds, bool $incomplete): void
    {
        if ($trace === null) return;
        $trace(sprintf('Respons: HTTP %s; %.3f s; %d bytes ontvangen%s.',
            $status === 0 ? 'geen status ontvangen' : (string) $status, $seconds, strlen($body),
            $incomplete ? '; overdracht niet voltooid' : ''));
        $trace($body === '' ? 'Responsbody: geen bytes ontvangen.' : 'Responsbody (max. 2048 bytes na afscherming): ' . $this->diagnosticText($body));
    }

    private function diagnosticText(string $text): string
    {
        $replacements = [];
        foreach ([$this->username, $this->password, base64_encode($this->username . ':' . $this->password)] as $secret) {
            if ($secret === '') continue;
            foreach ([$secret, rawurlencode($secret), urlencode($secret),
                substr((string) json_encode($secret), 1, -1),
                substr((string) json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1)] as $variant) {
                if ($variant !== '') $replacements[$variant] = '[redacted]';
            }
        }
        // Redact before truncating; JSON quoting also neutralizes terminal control sequences.
        $text = strtr($text, $replacements);
        return json_encode(substr($text, 0, 2048), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            . (strlen($text) > 2048 ? ' [afgekapt]' : '');
    }
}
