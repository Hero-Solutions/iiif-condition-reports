<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ObjectImageStorage
{
    private const MAX_PIXELS = 60_000_000;
    private const THUMBNAIL_SIZE = 480;

    public function __construct(
        private readonly string $uploadDirectory,
        private readonly string $projectDirectory,
        private readonly ?string $caFile = null,
        private readonly string $publicPrefix = '/uploads/objects/',
        private readonly int $maxFileSize = 52_428_800,
    ) {
    }

    /**
     * @return array{image_url: string, thumbnail_url: string}
     */
    public function store(UploadedFile $image): array
    {
        if (($image->getSize() ?? 0) > $this->maxFileSize) {
            throw new FileException(sprintf('The image is larger than %d MB.', (int) floor($this->maxFileSize / 1_048_576)));
        }

        $mimeType = $this->supportedMimeType($this->mimeTypeForPath($image->getPathname()));
        $this->ensureUploadDirectory();

        $basename = bin2hex(random_bytes(16));
        $filename = $basename . '.' . $this->extensionForMimeType($mimeType);
        $image->move($this->uploadDirectory, $filename);

        return $this->finishStoredImage($filename, $basename, $mimeType);
    }

    /**
     * @return array{image_url: string, thumbnail_url: string}
     */
    public function storeExternal(string $url): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'condition-image-');

        if ($temporaryPath === false) {
            throw new FileException('A temporary file for the external image could not be created.');
        }

        try {
            $this->download($url, $temporaryPath);
            $mimeType = $this->supportedMimeType($this->mimeTypeForPath($temporaryPath));
            $this->ensureUploadDirectory();

            $basename = bin2hex(random_bytes(16));
            $filename = $basename . '.' . $this->extensionForMimeType($mimeType);
            $destination = $this->uploadDirectory . DIRECTORY_SEPARATOR . $filename;

            if (!rename($temporaryPath, $destination)) {
                if (!copy($temporaryPath, $destination)) {
                    throw new FileException('The external image could not be stored.');
                }

                unlink($temporaryPath);
            }

            return $this->finishStoredImage($filename, $basename, $mimeType);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @return array{image_url: string, thumbnail_url: string}
     */
    public function storeIiifManifest(string $manifestUrl): array
    {
        return $this->storeExternal($this->resolveIiifImageUrl($manifestUrl));
    }

    public function resolveIiifImageUrl(string $manifestUrl): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'condition-manifest-');

        if ($temporaryPath === false) {
            throw new FileException('A temporary file for the IIIF manifest could not be created.');
        }

        try {
            $this->download($manifestUrl, $temporaryPath, 'application/ld+json, application/json');
            $contents = file_get_contents($temporaryPath);

            if ($contents === false) {
                throw new FileException('The IIIF manifest could not be read.');
            }

            try {
                $manifest = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new FileException('The IIIF manifest is not valid JSON.', 0, $exception);
            }

            $imageUrl = is_array($manifest) ? $this->iiifImageUrl($manifest) : null;

            if ($imageUrl === null) {
                throw new FileException('No usable image was found in the IIIF manifest.');
            }

            return $imageUrl;
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function externalDataUri(string $url): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'condition-image-output-');

        if ($temporaryPath === false) {
            throw new FileException('A temporary file for the external image could not be created.');
        }

        try {
            $this->download($url, $temporaryPath);
            $mimeType = $this->supportedMimeType($this->mimeTypeForPath($temporaryPath));
            $contents = file_get_contents($temporaryPath);

            if ($contents === false) {
                throw new FileException('The external image could not be read.');
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function remove(?string $imageUrl): void
    {
        if (!$this->isStoredImage($imageUrl)) {
            return;
        }

        $path = $this->pathForUrl($imageUrl);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function isStoredImage(?string $imageUrl): bool
    {
        return $imageUrl !== null && str_starts_with($imageUrl, $this->publicPrefix);
    }

    public function rotate(string $imageUrl, int $degrees = 90): void
    {
        if (!$this->exists($imageUrl)) {
            throw new FileException('The image does not exist.');
        }

        $degrees = (($degrees % 360) + 360) % 360;

        if (!in_array($degrees, [90, 180, 270], true)) {
            throw new FileException('The rotation is not supported.');
        }

        $path = $this->pathForUrl($imageUrl);
        $mimeType = $this->supportedMimeType($this->mimeTypeForPath($path));
        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (!$source instanceof \GdImage) {
            throw new FileException('The image could not be decoded.');
        }

        try {
            $rotated = imagerotate($source, -$degrees, 0);

            if (!$rotated instanceof \GdImage) {
                throw new FileException('The image could not be rotated.');
            }

            try {
                $written = match ($mimeType) {
                    'image/jpeg' => imagejpeg($rotated, $path, 92),
                    'image/png' => imagepng($rotated, $path, 6),
                    'image/webp' => function_exists('imagewebp') && imagewebp($rotated, $path, 90),
                };

                if (!$written) {
                    throw new FileException('The rotated image could not be written.');
                }
            } finally {
                imagedestroy($rotated);
            }
        } finally {
            imagedestroy($source);
        }

        $thumbnailPath = preg_replace('~\.[^.]+$~', '-thumb.jpg', $path);

        if (is_string($thumbnailPath)) {
            $this->createThumbnail($path, $thumbnailPath, $mimeType);
        }
    }

    public function exists(?string $imageUrl): bool
    {
        return $this->isStoredImage($imageUrl) && is_file($this->pathForUrl($imageUrl));
    }

    public function dataUri(?string $imageUrl): ?string
    {
        if (!$this->exists($imageUrl)) {
            return null;
        }

        $path = $this->pathForUrl((string) $imageUrl);
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return 'data:' . $this->mimeTypeForPath($path) . ';base64,' . base64_encode($contents);
    }

    /**
     * @return array{image_url: string, thumbnail_url: string}
     */
    private function finishStoredImage(string $filename, string $basename, string $mimeType): array
    {
        $imagePath = $this->uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        $thumbnailFilename = $basename . '-thumb.jpg';
        $thumbnailPath = $this->uploadDirectory . DIRECTORY_SEPARATOR . $thumbnailFilename;

        try {
            $this->createThumbnail($imagePath, $thumbnailPath, $mimeType);
        } catch (\Throwable $exception) {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }

            if (is_file($thumbnailPath)) {
                unlink($thumbnailPath);
            }

            throw new FileException('The thumbnail could not be created.', 0, $exception);
        }

        return [
            'image_url' => $this->publicPrefix . $filename,
            'thumbnail_url' => $this->publicPrefix . $thumbnailFilename,
        ];
    }

    private function createThumbnail(string $sourcePath, string $targetPath, string $mimeType): void
    {
        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if (!$source instanceof \GdImage) {
            throw new FileException('The image could not be decoded.');
        }

        try {
            if ($mimeType === 'image/jpeg') {
                $source = $this->orientJpeg($source, $sourcePath);
            }

            $width = imagesx($source);
            $height = imagesy($source);

            if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
                throw new FileException('The image dimensions are not supported.');
            }

            $scale = min(self::THUMBNAIL_SIZE / $width, self::THUMBNAIL_SIZE / $height, 1);
            $thumbnailWidth = max(1, (int) round($width * $scale));
            $thumbnailHeight = max(1, (int) round($height * $scale));
            $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

            if (!$thumbnail instanceof \GdImage) {
                throw new FileException('The thumbnail canvas could not be created.');
            }

            try {
                $background = imagecolorallocate($thumbnail, 255, 255, 255);
                imagefill($thumbnail, 0, 0, $background);
                imagecopyresampled(
                    $thumbnail,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $thumbnailWidth,
                    $thumbnailHeight,
                    $width,
                    $height,
                );

                if (!imagejpeg($thumbnail, $targetPath, 82)) {
                    throw new FileException('The thumbnail could not be written.');
                }
            } finally {
                imagedestroy($thumbnail);
            }
        } finally {
            imagedestroy($source);
        }
    }

    private function orientJpeg(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) ((@exif_read_data($path)['Orientation'] ?? 1));
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if (!$rotated instanceof \GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function download(string $url, string $targetPath, string $accept = 'image/jpeg, image/png, image/webp'): void
    {
        $currentUrl = trim($url);

        for ($redirects = 0; $redirects <= 3; ++$redirects) {
            [$host, $port, $ipAddress] = $this->safeRemoteAddress($currentUrl);
            $headers = [];
            $bytesWritten = 0;
            $tooLarge = false;
            $maxFileSize = $this->maxFileSize;
            $stream = fopen($targetPath, 'wb');

            if ($stream === false) {
                throw new FileException('The temporary image file could not be opened.');
            }

            $curl = curl_init();

            if ($curl === false) {
                fclose($stream);
                throw new FileException('The external image request could not be started.');
            }

            $resolveHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
            $resolveAddress = str_contains($ipAddress, ':') ? '[' . $ipAddress . ']' : $ipAddress;

            $curlOptions = [
                CURLOPT_URL => $currentUrl,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'IIIF Condition Reports image importer',
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $resolveHost, $port, $resolveAddress)],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
                    $length = strlen($header);
                    $separator = strpos($header, ':');

                    if ($separator !== false) {
                        $name = strtolower(trim(substr($header, 0, $separator)));
                        $headers[$name] = trim(substr($header, $separator + 1));
                    }

                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($stream, &$bytesWritten, &$tooLarge, $maxFileSize): int {
                    $length = strlen($data);
                    $bytesWritten += $length;

                    if ($bytesWritten > $maxFileSize) {
                        $tooLarge = true;

                        return 0;
                    }

                    $written = fwrite($stream, $data);

                    return $written === false ? 0 : $written;
                },
            ];

            $caFile = $this->caFilePath();

            if ($caFile !== null) {
                $curlOptions[CURLOPT_CAINFO] = $caFile;
            }

            curl_setopt_array($curl, $curlOptions);

            $success = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($stream);

            if ($tooLarge) {
                throw new FileException(sprintf('The external image is larger than %d MB.', (int) floor($this->maxFileSize / 1_048_576)));
            }

            if ($success === false) {
                throw new FileException('The external image could not be downloaded: ' . $error);
            }

            if ($statusCode >= 300 && $statusCode < 400 && isset($headers['location'])) {
                if ($redirects === 3) {
                    throw new FileException('The external image redirected too many times.');
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $headers['location']);

                continue;
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FileException(sprintf('The external image returned HTTP status %d.', $statusCode));
            }

            if ($bytesWritten === 0) {
                throw new FileException('The external image was empty.');
            }

            return;
        }
    }

    /** @param array<string, mixed> $manifest */
    private function iiifImageUrl(array $manifest): ?string
    {
        $canvas = $this->first($manifest['items'] ?? null);
        $page = is_array($canvas) ? $this->first($canvas['items'] ?? null) : null;
        $annotation = is_array($page) ? $this->first($page['items'] ?? null) : null;
        $body = is_array($annotation) ? $this->first($annotation['body'] ?? null) : null;
        $presentation3Image = $this->bodyImageUrl($body);

        if ($presentation3Image !== null) {
            return $presentation3Image;
        }

        $sequence = $this->first($manifest['sequences'] ?? null);
        $presentation2Canvas = is_array($sequence) ? $this->first($sequence['canvases'] ?? null) : null;
        $presentation2Annotation = is_array($presentation2Canvas) ? $this->first($presentation2Canvas['images'] ?? null) : null;
        $presentation2Image = is_array($presentation2Annotation)
            ? $this->bodyImageUrl($presentation2Annotation['resource'] ?? null)
            : null;

        if ($presentation2Image !== null) {
            return $presentation2Image;
        }

        $canvasThumbnail = is_array($canvas) ? $this->imageId($this->first($canvas['thumbnail'] ?? null)) : null;

        if ($canvasThumbnail !== null) {
            return $canvasThumbnail;
        }

        $presentation2Thumbnail = is_array($presentation2Canvas)
            ? $this->imageId($this->first($presentation2Canvas['thumbnail'] ?? null))
            : null;

        return $presentation2Thumbnail ?? $this->imageId($this->first($manifest['thumbnail'] ?? null));
    }

    private function bodyImageUrl(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $service = $this->first($body['service'] ?? $body['services'] ?? null);
        $serviceId = $this->imageId($service);

        if ($serviceId !== null) {
            return rtrim((string) preg_replace('~/info\.json$~i', '', $serviceId), '/') . '/full/2000,/0/default.jpg';
        }

        return $this->imageId($body) ?? $this->imageId($this->first($body['thumbnail'] ?? null));
    }

    private function imageId(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? $value['@id'] ?? null;

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    private function first(mixed $value): mixed
    {
        return is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
    }

    /**
     * @return array{string, int, string}
     */
    private function safeRemoteAddress(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new FileException('The external image URL is not supported.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new FileException('The external image URL has an invalid port.');
        }

        $addresses = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }

            if ($addresses === []) {
                $addresses = gethostbynamel($host) ?: [];
            }
        }

        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [$host, $port, $address];
            }
        }

        throw new FileException('The external image host could not be resolved to a public address.');
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);

        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($currentUrl);
        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return sprintf('%s://%s%s%s', $scheme, $host, $port, $location);
        }

        $path = (string) ($parts['path'] ?? '/');
        $directory = preg_replace('~/[^/]*$~', '/', $path) ?? '/';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $directory, $location);
    }

    private function ensureUploadDirectory(): void
    {
        if (!is_dir($this->uploadDirectory) && !mkdir($this->uploadDirectory, 0775, true) && !is_dir($this->uploadDirectory)) {
            throw new FileException('The object image directory could not be created.');
        }
    }

    private function caFilePath(): ?string
    {
        $caFile = $this->caFile === null ? '' : trim($this->caFile);

        if ($caFile === '') {
            return null;
        }

        if (preg_match('~^(?:[a-zA-Z]:[\\\\/]|/|\\\\\\\\)~', $caFile) === 1) {
            return $caFile;
        }

        return rtrim($this->projectDirectory, '/\\\\')
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $caFile);
    }

    private function mimeTypeForPath(string $path): string
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($path);

        return is_string($mimeType) ? $mimeType : '';
    }

    private function supportedMimeType(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/png', 'image/webp' => $mimeType,
            default => throw new FileException('Only JPG, PNG and WebP images are supported.'),
        };
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };
    }

    private function pathForUrl(string $imageUrl): string
    {
        return $this->uploadDirectory . DIRECTORY_SEPARATOR . basename($imageUrl);
    }
}
