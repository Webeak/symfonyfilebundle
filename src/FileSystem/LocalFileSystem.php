<?php
namespace Webeak\Bundle\FileBundle\FileSystem;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Component\Utils\RandomGenerator;

/**
 * Interface between the other services and the hard drive.
 */
class LocalFileSystem implements FileSystemInterface
{
    /** @var SymfonyFilesystem */
    protected $symfonyFilesystem;

    /** @var ErrorTrackerInterface */
    protected $errorTracker;

    /** @var string */
    protected $rootDir;

    /** @var string */
    protected $locksDir;

    /** @var string */
    protected $tempDir;

    /** @var string */
    protected $storageDir;

    /** @var string */
    protected $publicStorageDir;

    public function __construct(ErrorTrackerInterface $errorTracker,
                                SymfonyFilesystem $symfonyFilesystem,
                                $rootDir,
                                $publicRootDir)
    {
        $this->rootDir = $this->ensurePathExists($rootDir);
        $this->locksDir = $this->ensurePathExists($this->rootDir.'/locks');
        $this->tempDir = $this->ensurePathExists($this->rootDir.'/temp');
        $this->storageDir = $this->ensurePathExists($this->rootDir.'/storage');
        $this->publicStorageDir = $this->ensurePathExists($publicRootDir);
        $this->symfonyFilesystem = $symfonyFilesystem;
        $this->errorTracker = $errorTracker;
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
        $this->symfonyFilesystem->rename($source, $dest);
        return new File($dest, false, $sourceInstance);
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
        if ($source instanceof File) {
            $source = $source->getRealPath();
        }
        return @file_get_contents($source);
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
        if (@file_put_contents($source, $content, LOCK_EX | FILE_BINARY) === false) {
            $this->errorTracker->trackAndThrow(new IOException('Failed to write to "%s".', $source));
        }
    }

    /**
     * Write the input content in a new temporary file.
     *
     * @param mixed $content content of the file
     *
     * @return string the absolute path to the file
     */
    public function writeTemporarily($content)
    {
        $path = $this->generateTemporaryPath();
        $this->write($path, $content);
        return $path;
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
        return @unlink($file);
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
        $path = ($file instanceof File) ? $file->getRealPath() : $file;
        if (is_dir($path)) {
            // rmdir will fail if the directory is not empty, so let PHP do the job of checking that. Just silence the warning.
            $success = @rmdir($path);
        } else {
            $success = @unlink($path);
        }
        if ($success && $limit !== 0) {
            $dir = dirname($path);
            $this->removeWithParentIfEmpty($dir, $limit > 0 ? ($limit - 1) : -1);
        }
        return $success;
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
        return new File($symfonyFile->getRealPath(), false, $file);
    }

    /**
     * This method will generate a unique temporary path you can write into.
     * The path is guaranteed to be dedicated for the next 10 minutes.
     * After that, another task could potentially (extremely not likely..) write a file at this path.
     *
     * This path is only meant to be able to write temporary files while processing
     * and input. It should not be used more than few milliseconds.
     *
     * @return string
     *
     * @throws
     */
    public function generateTemporaryPath()
    {
        return $this->tempDir.'/'.$this->generateTemporaryIdentifier();
    }

    /**
     * This method will generate a unique temporary identifier.
     * The identifier is guaranteed to be unique for the next 10 minutes.
     * After that, the lock file may be destroyed at any time by the CRON task.
     *
     * This identifier is only meant to be able to write temporary files while processing
     * an input. It should not be used more than few milliseconds.
     *
     * @return string
     *
     * @throws
     */
    public function generateTemporaryIdentifier()
    {
        $identifier = RandomGenerator::randomString(5);
        $maxTries = 5;

        for ($tries = 0; $tries < $maxTries; ++$tries) {
            $path = $this->locksDir.'/'.$identifier;
            if (($fd = @fopen($path, 'x')) !== false) {
                @fclose($fd);
                return $identifier;
            }
            $identifier .= RandomGenerator::randomString(1);
        }
        throw new RuntimeException('Failed to create a unique path for the file.');
    }

    /**
     * Ensure a path exists or throws an exception.
     *
     * @param $path
     *
     * @return string|void
     *
     * @throws
     */
    public function ensurePathExists($path)
    {
        if (!file_exists($path)) {
            if (!mkdir($path, 0777, true)) {
                $path = null;
            }
        }
        if ($path && ($output = realpath($path)) !== false) {
            return $output;
        }
        $this->errorTracker->trackAndThrow(new IOException(sprintf('Unable to create path "%s".', $path)));
    }

    /**
     * Release the lock associated with a file.
     *
     * @param File file
     *
     * @return boolean|null true if the lock has been found and successfully removed
     *                      false if the lock has been found BUT failed to remove
     *                      null if the lock has NOT been found
     */
    public function release(File $file)
    {
        $identifier = $file->getFilename();
        if (substr($identifier, 0, strlen($this->tempDir)) !== $this->tempDir) {
            $identifier = $this->locksDir.'/'.$identifier;
        }
        if (file_exists($identifier)) {
            return @unlink($identifier);
        }
        return null;
    }

    /**
     * Remove empty directories managed by the filesystem.
     */
    public function clearEmptyDirectories()
    {
        $finder = new Finder();
        $finder->directories();
        $finder->depth('< 1');
        try {
            foreach ($finder->in([$this->storageDir, $this->publicStorageDir]) as $dir) {
                /** @var File $dir */
                $path = $dir->getRealpath();
                if ($path && !count(Finder::create()->files()->in($path))) {
                    $this->symfonyFilesystem->remove($path);
                }
            }
        } catch (\Exception $e) {
            // ..
        }
    }

    /**
     * Test if a File object or an absolute path is in the temp directory or not.
     *
     * @param string|File $file
     *
     * @return boolean
     */
    public function isTemporary($file)
    {
        if ($file instanceof File) {
            $file = $file->getRealPath();
        }
        return substr($file, 0, strlen($this->tempDir)) === $this->tempDir;
    }

    /**
     * Search for temporary files older than 10 minutes.
     * If a file is found, the process responsible of it may have crashed before clearing it.
     * The role of this method is to clean up these ghosts files.
     *
     * Should only be called by a command.
     *
     * @param OutputInterface $output
     */
    public function clearOldTemporaryFiles(OutputInterface $output = null)
    {
        if ($output) { $output->writeLn(sprintf('Searching for temporary files to remove..')); }
        $finder = new Finder();
        $finder->files()->date('< 10 minutes ago');
        foreach ($finder->in([$this->locksDir, $this->tempDir]) as $file) {
            /** @var File $file */
            if ($output) {
                $output->write(sprintf('Removing file <info>%s</info>..', $file->getRealPath()));
            }
            if (@unlink($file->getRealPath()) === false && $output) {
                $output->writeLn('<error>Failed.</error>');
            } else if ($output) {
                $output->writeLn('<info>Success.</info>');
            }
        }
        if ($output) { $output->writeLn(sprintf('End of search.')); }
    }

    public function createFile($path): File
    {
        return new File($path);
    }
}
