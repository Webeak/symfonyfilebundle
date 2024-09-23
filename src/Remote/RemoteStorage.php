<?php
namespace Webeak\Bundle\FileBundle\Remote;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\AwsS3FileSystem;
use Webeak\Bundle\FileBundle\FileSystem\RemoteFileSystem;

class RemoteStorage
{
    private readonly string $endpoint;
    private readonly ?string $key;
    private readonly ?string $secret;
    private readonly HttpClientInterface $httpClient;

    /** @var array */
    private array $knownFiles;

    public function __construct(
        HttpClientInterface $httpClient,
        array $remoteConfiguration
    ) {
        $this->httpClient = $httpClient;
        $this->endpoint = rtrim($remoteConfiguration['endpoint'], '/');
        $this->key = $remoteConfiguration['credentials_key'];
        $this->secret = $remoteConfiguration['credentials_secret'];
        $this->knownFiles = [];
    }

//    public function read($input)
//    {
//        $key = $input;
//        if ($input instanceof File) {
//            $normalized = RemoteFileSystem::$Instance->normalizePath($input->getRealPath());
//            if (!$normalized['s3Name']) {
//                throw new UsageException('Failed to read file, no valid s3 name.', 0, null, ['input' => $input]);
//            }
//            $key = $normalized['s3Name'];
//        }
//        if (!array_key_exists($key, $this->knownFiles)) {
//            $this->knownFiles[$key] = $this->getClient()->getObject([
//                'Bucket' => $this->configuration['bucket'],
//                'Key' => $key
//            ])['Body']->__toString();
//        }
//        return $this->knownFiles[$key];
//    }
//
//    public function write($keyOrFile, $value): void
//    {
//        $key = $keyOrFile;
//        if ($keyOrFile instanceof File) {
//            $normalized = AwsS3FileSystem::$Instance->normalizePath($keyOrFile->getRealPath());
//            if (!$normalized['s3Name']) {
//                throw new UsageException('Failed to write file, no valid s3 name.', 0, null, ['input' => $keyOrFile]);
//            }
//            $key = $normalized['s3Name'];
//        }
//        $this->getClient()->putObject([
//            'Bucket' => $this->configuration['bucket'],
//            'Key'    => $key,
//            'Body'   => $value
//        ]);
//    }

    public function remove($keyOrFile): void
    {
        $key = $keyOrFile;
        if ($keyOrFile instanceof File) {
            $normalized = AwsS3FileSystem::$Instance->normalizePath($keyOrFile->getRealPath());
            if (!$normalized['s3Name']) {
                throw new UsageException('Failed to write file, no valid s3 name.', 0, null, ['input' => $keyOrFile]);
            }
            $key = $normalized['s3Name'];
        }
        $this->getClient()->deleteObject([
            'Bucket' => $this->configuration['bucket'],
            'Key'    => $key
        ]);
    }

    public function ensureRemoteFile($file): RemoteFile
    {
        if ($file instanceof RemoteFile) {
            return $file;
        }
        if ($file instanceof File) {
            return new RemoteFile($file->getPath() . '/'. $file->getFilename(), false, $file);
        }
        throw new IOException('Failed to create RemoteFile.', 0, 500, null, ['input' => $file]);
    }

    private function getHeaders(): array
    {
        return [
            'x-wb-file-key' => $this->key,
            'x-wb-file-secret' => $this->secret,
            'Content-Type' => 'multipart/form-data',
        ];
    }
}
