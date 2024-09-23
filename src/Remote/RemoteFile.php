<?php
namespace Webeak\Bundle\FileBundle\Remote;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webeak\Bundle\FileBundle\File;

class RemoteFile extends File
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly string $secret,
        string $path,
        File $file = null)
    {
        parent::__construct($path, false, $file);
    }

    public function getMimeType(): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/' . $this->getFilename(), [
                'headers' => [
                    'X-API-KEY' => $this->key,
                    'X-API-SECRET' => $this->secret
                ]
            ]);
            $contentType = $response->getHeaders()['content-type'][0] ?? null;
            return $contentType;
        } catch (TransportExceptionInterface $e) {
            return null;
        }
    }

    public function guessMimeType(string $path): ?string
    {
        return $this->getMimeType();
    }

    public function getHash(): string
    {
        return md5($this->getContent());
    }

    public function getRealPath(): string
    {
        return $this->baseUrl . '/' . $this->getPath() . '/' . $this->getFilename();
    }

    public function move(string $directory, string $name = null): File
    {
        $newPath = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR.(null === $name ? $this->getBasename() : $name);
        $response = $this->httpClient->request('POST', $this->baseUrl . '/move', [
            'headers' => [
                'X-API-KEY' => $this->key,
                'X-API-SECRET' => $this->secret
            ],
            'json' => [
                'oldPath' => $this->getRealPath(),
                'newPath' => $newPath
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            return new self($this->httpClient, $this->baseUrl, $this->key, $this->secret, $newPath);
        }

        throw new \RuntimeException('Failed to move file');
    }

    public function getContent(): string
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/' . $this->getFilename(), [
                'headers' => [
                    'X-API-KEY' => $this->key,
                    'X-API-SECRET' => $this->secret
                ]
            ]);
            return $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to get file content');
        }
    }

    public function getSize(): int
    {
        try {
            $response = $this->httpClient->request('HEAD', $this->baseUrl . '/' . $this->getFilename(), [
                'headers' => [
                    'X-API-KEY' => $this->key,
                    'X-API-SECRET' => $this->secret
                ]
            ]);
            $contentLength = $response->getHeaders()['content-length'][0] ?? 0;
            return (int) $contentLength;
        } catch (TransportExceptionInterface $e) {
            return 0;
        }
    }

    public function isFile(): bool
    {
        return true; // Simplified check; consider more robust validation based on remote response
    }

    public function isReadable(): bool
    {
        return true; // Simplified check; consider more robust validation based on remote response
    }

    /**
     * @return self
     */
    protected function getTargetFile(string $directory, string $name = null): File
    {
        $target = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR.(null === $name ? $this->getBasename() : $name);
        return new self($this->httpClient, $this->baseUrl, $this->key, $this->secret, $target);
    }
}

