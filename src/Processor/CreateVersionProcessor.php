<?php
namespace Webeak\Bundle\FileBundle\Processor;

use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;
use Webeak\Bundle\FileBundle\ManagedFile;

/**
 * Creates a new version of a file.
 */
class CreateVersionProcessor extends AbstractProcessor
{
    /**
     * Name of the version to create.
     */
    public string $name;

    public function __construct(private readonly FileSystemInterface $filesystem)
    {

    }

    /**
     * Test if the processor supports the input.
     *
     * @param File        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return boolean
     */
    public function supports(File $file, ManagedFile $parent): bool
    {
        return true;
    }

    /**
     * Do the processing.
     *
     * @param File        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return File
     */
    public function process(File $file, ManagedFile $parent): File
    {
        $copy = $this->filesystem->copy($file, $file->getRealPath().'.v'.$this->name);
        $parent->addVersion($copy, $this->name);
        return $copy;
    }
}
