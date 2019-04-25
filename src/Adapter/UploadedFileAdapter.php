<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\FileBundle\Exception\UploadException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handle UploadedFile object input.
 */
class UploadedFileAdapter implements AdapterInterface
{
    /** @var ErrorTrackerInterface */
    private $errorTracker;

    /** @var FileSystem */
    private $filesystem;

    public function __construct(ErrorTrackerInterface $errorTracker, FileSystem $filesystem)
    {
        $this->errorTracker = $errorTracker;
        $this->filesystem = $filesystem;
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
        return $input instanceof UploadedFile;
    }

    /**
     * Normalize the input value into a (symfony) File instance.
     *
     * @param UploadedFile $input the input to convert into a File instance
     *
     * @return File
     *
     * @throws
     */
    public function normalize($input)
    {
        if (!$input->isValid()) {
            $this->errorTracker->trackAndThrow(new UploadException(sprintf('The upload failed. Reason: %s', $input->getErrorMessage())));
        }
        $file = $this->filesystem->move($input->getRealPath(), $this->filesystem->generateTemporaryPath());
        $file->setVirtualName($input->getClientOriginalName());
        return $file;
    }
}
