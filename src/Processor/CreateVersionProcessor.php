<?php
namespace Webeak\Bundle\FileBundle\Processor;

use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\VirtualFile;

/**
 * Creates a new version of a file.
 */
class CreateVersionProcessor extends AbstractProcessor
{
    /**
     * Name of the version to create.
     */
    public string $name = '';

    /**
     * Test if the processor supports the input.
     *
     * @return boolean
     */
    public function supports(VirtualFile $file, ManagedFile $parent): bool
    {
        return true;
    }

    /**
     * Do the processing.
     */
    public function process(VirtualFile $file, ManagedFile $parent): VirtualFile
    {
        $filesystem = $parent->getFileSystem();
        $clone = $parent->createVersion();
        $clone->setVirtualName($file->getVirtualName() . 'v' . $this->name);;
        $clone->setFileSystem($filesystem);
        $clone->setContent($file->getContent());
        $parent->addVersion($clone, $this->name);
        return $clone;
    }
}
