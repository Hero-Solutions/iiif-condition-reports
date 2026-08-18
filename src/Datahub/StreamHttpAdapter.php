<?php

declare(strict_types=1);

namespace App\Datahub;

use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\HttpAdapter\HttpAdapterInterface;

final class StreamHttpAdapter implements HttpAdapterInterface
{
    public function __construct(
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $caFile = null,
    ) {
    }

    public function request($url): string
    {
        $headers = [
            'User-Agent: Condition Reports OAI-PMH Harvester',
        ];

        if ($this->username !== null && $this->password !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 60,
            ],
            'ssl' => array_filter([
                'cafile' => $this->caFile,
            ]),
        ]);

        $body = @file_get_contents((string) $url, false, $context);
        $statusCode = $this->statusCode($http_response_header ?? []);

        if ($body === false) {
            throw new HttpException('', 'HTTP request failed.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new HttpException($body, sprintf('HTTP request failed with code %d.', $statusCode), (string) $statusCode);
        }

        if (trim($body) === '') {
            throw new HttpException($body, 'HTTP response empty.');
        }

        return $body;
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
