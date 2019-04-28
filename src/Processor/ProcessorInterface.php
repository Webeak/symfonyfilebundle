<?php
namespace Webeak\Bundle\FileBundle\Processor;

use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\ManagedFile;

/**
 * Base interface all processors must implement.
 */
interface ProcessorInterface
{
    /**
     * Test if the processor supports the input.
     *
     * @param File        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return boolean
     */
    public function supports(File $file, ManagedFile $parent);

    /**
     * Do the processing.
     *
     * @param File        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return File
     */
    public function process(File $file, ManagedFile $parent);

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
    public function getOptions();

    /**
     * Get the id of the service in the container.
     * Processors are always services and MUST be public.
     *
     * @return string
     */
    public function getServiceId();
}
