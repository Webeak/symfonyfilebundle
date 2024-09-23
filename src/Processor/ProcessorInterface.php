<?php
namespace Webeak\Bundle\FileBundle\Processor;

use Webeak\Bundle\FileBundle\VirtualFile;
use Webeak\Bundle\FileBundle\ManagedFile;

/**
 * Base interface all processors must implement.
 */
interface ProcessorInterface
{
    /**
     * Test if the processor supports the input.
     *
     * @param VirtualFile        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return boolean
     */
    public function supports(VirtualFile $file, ManagedFile $parent): bool;

    /**
     * Do the processing.
     *
     * @param VirtualFile        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return VirtualFile
     */
    public function process(VirtualFile $file, ManagedFile $parent): VirtualFile;

    /**
     * Set an array of options.
     *
     * @param array $options
     */
    public function setOptions(array $options);

    /**
     * Get the full array of options.
     *
     * @return array
     */
    public function getOptions(): array;

    /**
     * Get the id of the service in the container.
     * Processors are always services and MUST be public.
     *
     * @return string
     */
    public function getServiceId(): string;
}
