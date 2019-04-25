<?php
namespace Webeak\Bundle\FileBundle\Storage;

use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Webeak\Bundle\FileBundle\ManagedFile;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base interface all storages must implement.
 */
interface StorageInterface
{
    /**
     * Clear files for which the expiration date has been reached.
     *
     * @param OutputInterface $output
     */
    public function clearExpiredFiles(OutputInterface $output = null);

    /**
     * Load a file from the storage
     *
     * @param string $identifier
     *
     * @return ManagedFile
     *
     * @throws FileNotFoundException
     */
    public function load($identifier);

    /**
     * Load a file from the storage using its unique hash.
     *
     * @param string $hash
     *
     * @return ManagedFile
     *
     * @throws FileNotFoundException
     */
    public function loadByHash($hash);

    /**
     * Persist a file in the storage to be saved.
     *
     * @param ManagedFile $file
     *
     * @return ManagedFile
     *
     * @throws
     */
    public function persist(ManagedFile $file);

    /**
     * Process all scheduled operations.
     *
     * @param ManagedFile[] $files (optional, default: null) array of files to flush, if not defined the whole pool will be flushed
     *
     * @return mixed
     *
     * @throws
     */
    public function flush($files = null);

    /**
     * Remove a file.
     *
     * @param ManagedFile|PublicFile|string $file file instance or identifier
     */
    public function remove($file);

    /**
     * Remove a version of a file.
     *
     * @param ManagedFile|PublicFile|string $file
     * @param string|array       $version
     */
    public function removeVersion($file, $version);

    /**
     * Remove the expiration date of a file.
     *
     * @param ManagedFile|PublicFile|string $file
     */
    public function removeExpirationDate($file);
}
