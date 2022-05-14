<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;

/**
 * Handle local path to a file.
 */
class LocalPathAdapter implements AdapterInterface
{
    /** @var FileSystemInterface */
    private $filesystem;

    /** @var ErrorTrackerInterface */
    private $errorTracker;

    public function __construct(FileSystemInterface $fileSystem, ErrorTrackerInterface $errorTracker)
    {
        $this->filesystem = $fileSystem;
        $this->errorTracker = $errorTracker;
    }

    /**
     * Test if the adapter supports the input.
     *
     * @param mixed $input
     *
     * @return boolean
     */
    public function supports($input)
    {
        return is_string($input) && parse_url($input, PHP_URL_HOST) === null && file_exists($input);
    }

    /**
     * Normalize the input value into a (symfony) File instance.
     *
     * @param mixed $input
     *
     * @return File
     */
    public function normalize($input)
    {
        $file = $this->filesystem->copy($input, $this->filesystem->generateTemporaryPath());
        $file->setVirtualName(substr($input, intval(strrpos(str_replace('\\', '/', $input), '/')) + 1));
        return $file;
    }
}
