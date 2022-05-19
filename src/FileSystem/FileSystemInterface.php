<?php
namespace Webeak\Bundle\FileBundle\FileSystem;

use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\File;

/**
 * Interface between the other services and the hard drive.
 */
interface FileSystemInterface
{
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
    public function copy($source, $dest);

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
    public function move($source, $dest);

    /**
     * Get the content of the file.
     *
     * @param string|File $source
     *
     * @return mixed
     */
    public function read($source);

    /**
     * Set the content of the file.
     *
     * @param string|File $source
     * @param mixed       $content
     *
     * @throws
     */
    public function write($source, $content);

    /**
     * Write the input content in a new temporary file.
     *
     * @param mixed $content content of the file
     *
     * @return File the temporary file object
     */
    public function writeTemporarily($content);
    /**
     * Remove a file.
     *
     * @param File|string $file
     *
     * @return boolean
     */
    public function remove($file);

    /**
     * Remove a file or a directory and N parent directories if empty.
     *
     * @param File|string $file
     * @param integer     $limit (optional, default: -1) how many upper level to remove at max? < 0 means no limit.
     *
     * @return boolean
     */
    public function removeWithParentIfEmpty($file, $limit = 0);

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
    public function persist(File $file);

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
    public function generateTemporaryPath();

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
    public function generateTemporaryIdentifier();

    /**
     * Ensure a path exists or throws an exception.
     *
     * @param $path
     *
     * @return string|void
     *
     * @throws
     */
    public function ensurePathExists($path);

    /**
     * Release the lock associated with a file.
     *
     * @param File file
     *
     * @return boolean|null true if the lock has been found and successfully removed
     *                      false if the lock has been found BUT failed to remove
     *                      null if the lock has NOT been found
     */
    public function release(File $file);

    /**
     * Remove empty directories managed by the filesystem.
     */
    public function clearEmptyDirectories();

    /**
     * Test if a File object or an absolute path is in the temp directory or not.
     *
     * @param string|File $file
     *
     * @return boolean
     */
    public function isTemporary($file);

    /**
     * Search for temporary files older than 10 minutes.
     * If a file is found, the process responsible of it may have crashed before clearing it.
     * The role of this method is to clean up these ghosts files.
     *
     * Should only be called by a command.
     *
     * @param OutputInterface $output
     */
    public function clearOldTemporaryFiles(OutputInterface $output = null);


    public function createFile($path): File;
}
