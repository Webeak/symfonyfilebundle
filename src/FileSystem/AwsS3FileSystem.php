<?php
namespace Webeak\Bundle\FileBundle\FileSystem;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
use Webeak\Bundle\FileBundle\Aws\AwsS3Bucket;
use Webeak\Bundle\FileBundle\Aws\BucketFile;
use Webeak\Bundle\FileBundle\Aws\S3Bucket;
use Webeak\Bundle\FileBundle\File;
use Webeak\Component\Utils\RandomGenerator;

/**
 * Interface between the other services and Amazon S3.
 */
class AwsS3FileSystem extends LocalFileSystem
{
    public static $Instance = null;

    /** @var S3Bucket */
    private $s3Bucket;

    public function __construct(ErrorTrackerInterface $errorTracker,
                                SymfonyFilesystem $symfonyFilesystem,
                                S3Bucket $s3Bucket,
                                $rootDir,
                                $publicRootDir)
    {
        parent::__construct($errorTracker, $symfonyFilesystem, $rootDir, $publicRootDir);
        $this->symfonyFilesystem = $symfonyFilesystem;
        $this->errorTracker = $errorTracker;
        $this->s3Bucket = $s3Bucket;
        self::$Instance = $this;
    }

    /**
     * Copy a file.
     *
     * @param string|File $source source file or path
     * @param string      $dest   destination absolute path
     *
     * @return File
     *
     * @throws
     */
    public function copy($source, $dest)
    {
        $source = $this->normalizePath($source);
        $dest = $this->normalizePath($dest);
        $sourceInstance = null;
        if ($source instanceof File) {
            $sourceInstance = $source;
            $source = $source->getRealPath();
        }
        $this->symfonyFilesystem->copy($source, $dest);
        return new File($dest, false, $sourceInstance);
    }

    /**
     * Move a file.
     *
     * @param string|File $source source file or path
     * @param string      $dest   destination absolute path
     *
     * @return File
     *
     * @throws
     */
    public function move($source, $dest)
    {
        $sourceInstance = null;
        if ($source instanceof File) {
            $sourceInstance = $source;
            $source = $source->getRealPath();
        }
        $source = $this->normalizePath($source);
        $dest = $this->normalizePath($dest);

        if (!$dest['s3Name']) {
            throw new \Exception('Failed to move file, target path is not valid.');
        }
        $this->s3Bucket->write($dest['s3Name'], @file_get_contents($source['originalPath']));
        return $this->s3Bucket->ensureBucketFile(new File($dest['originalPath'], false, $sourceInstance));
    }

    /**
     * Get the content of the file.
     *
     * @param string|File $source
     *
     * @return mixed
     */
    public function read($source)
    {
        return $this->s3Bucket->read($source);
    }

    /**
     * Set the content of the file.
     *
     * @param string|File $source
     * @param mixed       $content
     *
     * @throws
     */
    public function write($source, $content)
    {
        if ($source instanceof File) {
            $source = $source->getRealPath();
        }
        $normalized = $this->normalizePath($source);
        if (!$normalized['s3Name']) {
            $this->errorTracker->trackAndThrow(new \Exception(sprintf('Failed to write file "%s"', $source)), ['source' => $source]);
        }
        $this->s3Bucket->write($normalized['s3Name'], $content);
    }

    /**
     * Write the input content in a new temporary file.
     *
     * @param mixed $content content of the file
     *
     * @return File the temporary file object
     */
    public function writeTemporarily($content)
    {
        $path = $this->generateTemporaryPath();
        $this->write($path, $content);
        return new BucketFile($path);
    }

    /**
     * Remove a file.
     *
     * @param File|string $file
     *
     * @return boolean
     */
    public function remove($file)
    {
        if ($file instanceof File) {
            $file = $file->getRealPath();
        }
        $normalized = $this->normalizePath($file);
        if (!$normalized['s3Name']) {
            $this->errorTracker->trackAndThrow(new \Exception(sprintf('Failed to remove file "%s"', $file)), ['file' => $file]);
        }
        $this->s3Bucket->remove($normalized['s3Name']);
    }

    /**
     * Remove a file or a directory and N parent directories if empty.
     *
     * @param File|string $file
     * @param integer     $limit (optional, default: -1) how many upper level to remove at max? < 0 means no limit.
     *
     * @return boolean
     */
    public function removeWithParentIfEmpty($file, $limit = 0)
    {
        return $this->remove($file);
    }

    /**
     * Persist a file to the main storage.
     *
     * If the file is already if the main storage, nothing happen.
     * If the file is in the temporary folder, then it will be moved to the main storage
     * and a new File instance will be created.
     *
     * @param File $file
     *
     * @return File
     */
    public function persist(File $file)
    {
        if (!$this->isTemporary($file)) {
            return $file;
        }
        $time = (string)time();
        $targetBasePath = $file->isPublic() ? $this->publicStorageDir : $this->storageDir;
        $targetPath = $targetBasePath.'/'.substr($time, -3).'/'.substr($time, -6, 3);
        $newName = null;
        if ($file->isPublic()) {
            $newName = $file->getIdentifier();
            if ($file->getVersionName() !== 'default') {
                $newName .= '.'.$file->getVersionName();
            }
            $newName .= '.'.$file->getExtension();
        }
        $symfonyFile = $file->move($targetPath, $newName);
        $this->release($file);
        return new BucketFile($symfonyFile->getRealPath(), false, $file);
    }

    /**
     * @param string $path
     *
     * @return array{type: string, s3Name: string, originalPath: string}
     */
    public function normalizePath(string $path): array
    {
        if (substr($path, 0, strlen($this->rootDir)) === $this->rootDir) {
            $type = substr($path, 0, strlen($this->tempDir)) === $this->tempDir ? 'temp' : 'storage';
            $s3Name = str_replace('/', '_', substr($path, strlen($this->rootDir) + 1));
        } else {
            $type = 'external';
            $s3Name = null;
        }
        return [
            'type' => $type,
            's3Name' => $s3Name,
            'originalPath' => $path
        ];
    }

    public function createFile($path): File
    {
        return new BucketFile($path);
    }
}
