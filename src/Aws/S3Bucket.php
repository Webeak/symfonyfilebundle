<?php
namespace Webeak\Bundle\FileBundle\Aws;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\AwsS3FileSystem;

class S3Bucket
{
    /** @var S3Client|null */
    private ?S3Client $client = null;

    /** @var array */
    private array $knownFiles;

    public function __construct(private readonly array $configuration)
    {
        $this->knownFiles = [];
    }

    public function read($input)
    {
        $key = $input;
        if ($input instanceof File) {
            $normalized = AwsS3FileSystem::$Instance->normalizePath($input->getRealPath());
            if (!$normalized['s3Name']) {
                throw new UsageException('Failed to read file, no valid s3 name.', 0, null, ['input' => $input]);
            }
            $key = $normalized['s3Name'];
        }
        if (!array_key_exists($key, $this->knownFiles)) {
            $this->knownFiles[$key] = $this->getClient()->getObject([
                'Bucket' => $this->configuration['bucket'],
                'Key' => $key
            ])['Body']->__toString();
        }
        return $this->knownFiles[$key];
    }

    public function write($keyOrFile, $value): void
    {
        $key = $keyOrFile;
        if ($keyOrFile instanceof File) {
            $normalized = AwsS3FileSystem::$Instance->normalizePath($keyOrFile->getRealPath());
            if (!$normalized['s3Name']) {
                throw new UsageException('Failed to write file, no valid s3 name.', 0, null, ['input' => $keyOrFile]);
            }
            $key = $normalized['s3Name'];
        }
        $this->getClient()->putObject([
            'Bucket' => $this->configuration['bucket'],
            'Key'    => $key,
            'Body'   => $value
        ]);
    }

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

    public function ensureBucketFile($file): BucketFile
    {
        if ($file instanceof BucketFile) {
            return $file;
        }
        if ($file instanceof File) {
            return new BucketFile($file->getPath() . '/'. $file->getFilename(), false, $file);
        }
        throw new IOException('Failed to create BucketFile.', 0, 500, null, ['input' => $file]);
    }

    /**
     * Get or create a S3 client.
     */
    private function getClient(): S3Client
    {
        if ($this->client === null) {
            $this->client = new S3Client([
                'version' => 'latest',
                'region' => $this->configuration['region'],
                'credentials' => [
                    'key' => $this->configuration['credentials_key'],
                    'secret' => $this->configuration['credentials_secret'],
                ],
            ]);
        }
        return $this->client;
    }
}
