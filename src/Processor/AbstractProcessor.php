<?php
namespace Webeak\Bundle\FileBundle\Processor;

use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Component\Utils\ArrayUtils;

/**
 * Base class that can be used by processors.
 */
abstract class AbstractProcessor implements ProcessorInterface
{
    /** @var array */
    private array $inputOptions;

    /**
     * Test if the processor supports the input.
     *
     * @param File        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return boolean
     */
    abstract public function supports(File $file, ManagedFile $parent): bool;

    /**
     * Do the processing
     *
     * @param File        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return File
     */
    abstract public function process(File $file, ManagedFile $parent): File;

    /**
     * Get the id of the service in the container
     *
     * @return string
     */
    public function getServiceId(): string
    {
        return get_class($this);
    }

    /**
     * Get the full array of options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->inputOptions;
    }

    /**
     * Set an array of options
     *
     * @param array $options
     *
     * @throws
     */
    public function setOptions(array $options)
    {
        $invalidOptions = [];
        $missingOptions = array_flip(ArrayUtils::ensureArray($this->getRequiredOptions()));
        $knownOptions = get_object_vars($this);
        $this->inputOptions = [];
        foreach ($options as $option => $value) {
            if (array_key_exists($option, $knownOptions)) {
                $this->$option = $value;
                $this->inputOptions[$option] = $value;
                unset($missingOptions[$option]);
            } else {
                $invalidOptions[] = $option;
            }
        }
        if (count($invalidOptions) > 0) {
            throw new UsageException(sprintf(
                'The options "%s" do not exist in constraint "%s".',
                implode('", "', $invalidOptions),
                get_class($this)
            ));
        }
        if (count($missingOptions) > 0) {
            throw new UsageException(sprintf(
                'The options "%s" must be set for constraint "%s".',
                implode('", "', array_keys($missingOptions)),
                get_class($this)
            ));
        }
    }

    /**
     * Get the list of options that must be given to the processor
     *
     * @return string[]
     */
    public function getRequiredOptions(): array
    {
        return [];
    }
}
