<?php

declare(strict_types=1);

namespace App\Datahub;

use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\HttpAdapter\HttpAdapterInterface;

final class CurlHttpAdapter implements HttpAdapterInterface
{
    public function __construct(
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $caFile = null,
    ) {
    }

    public function request($url): string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $handle = curl_init((string) $url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'Condition Reports OAI-PMH Harvester',
            ];

            if ($this->username !== null && $this->password !== null) {
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_USERPWD] = $this->username . ':' . $this->password;
            }

            if ($this->caFile !== null) {
                $options[CURLOPT_CAINFO] = $this->caFile;
            }

            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);
            $error = curl_error($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            if ($body !== false && $statusCode >= 200 && $statusCode < 300 && trim($body) !== '') {
                return $body;
            }

            $retryable = $body === false || $statusCode === 429 || $statusCode >= 500;

            if ($attempt < 3 && $retryable) {
                usleep($attempt * 500_000);
                continue;
            }

            if ($body === false) {
                throw new HttpException('', 'HTTP request failed: ' . $error);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new HttpException($body, sprintf('HTTP request failed with code %d.', $statusCode), (string) $statusCode);
            }

            throw new HttpException($body, 'HTTP response empty.');
        }

        throw new HttpException('', 'HTTP request failed.');
    }
}
