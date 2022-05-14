<?php
namespace Webeak\Bundle\FileBundle\Aws;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\AwsS3FileSystem;

class S3Bucket
{
    /** @var S3Client|null */
    private $client = null;

    /** @var ErrorTrackerInterface */
    private $errorTracker;

    /** @var array */
    private $configuration;

    /** @var array */
    private $knownFiles;

    public function __construct(ErrorTrackerInterface $errorTracker, array $configuration)
    {
        $this->errorTracker = $errorTracker;
        $this->configuration = $configuration;
        $this->knownFiles = [];
    }

    public function read($input)
    {
        try {
            $key = $input;
            if ($input instanceof File) {
                $normalized = AwsS3FileSystem::$Instance->normalizePath($input->getRealPath());
                if (!$normalized['s3Name']) {
                    $this->errorTracker->trackAndThrow(new \Exception(sprintf('Failed to read file, no valid s3 name.')), ['input' => $input]);
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
        } catch (S3Exception $e) {
            $this->errorTracker->track($e);
        }
    }

    public function write($keyOrFile, $value)
    {
        try {
            $key = $keyOrFile;
            if ($keyOrFile instanceof File) {
                $normalized = AwsS3FileSystem::$Instance->normalizePath($keyOrFile->getRealPath());
                if (!$normalized['s3Name']) {
                    $this->errorTracker->trackAndThrow(new \Exception(sprintf('Failed to write file, no valid s3 name.')), ['input' => $keyOrFile]);
                }
                $key = $normalized['s3Name'];
            }
            $this->getClient()->putObject([
                'Bucket' => $this->configuration['bucket'],
                'Key'    => $key,
                'Body'   => $value
            ]);
        } catch (S3Exception $e) {
            $this->errorTracker->track($e);
        }
    }

    public function remove($keyOrFile)
    {
        try {
            $key = $keyOrFile;
            if ($keyOrFile instanceof File) {
                $normalized = AwsS3FileSystem::$Instance->normalizePath($keyOrFile->getRealPath());
                if (!$normalized['s3Name']) {
                    $this->errorTracker->trackAndThrow(new \Exception(sprintf('Failed to write file, no valid s3 name.')), ['input' => $keyOrFile]);
                }
                $key = $normalized['s3Name'];
            }
            $this->getClient()->deleteObject([
                'Bucket' => $this->configuration['bucket'],
                'Key'    => $key
            ]);
        } catch (S3Exception $e) {
            $this->errorTracker->track($e);
        }
    }

    public function ensureBucketFile($file)
    {
        if ($file instanceof BucketFile) {
            return $file;
        }
        if ($file instanceof File) {
            return new BucketFile($file->getPath() . '/'. $file->getFilename(), false, $file);
        }
        $this->errorTracker->trackAndThrow(new \Exception('Failed to create BucketFile.'), ['input' => $file]);
    }

    /**
     * Get or create a S3 client.
     *
     * @return S3Client
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
