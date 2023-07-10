<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\FileBundle\Exception\UploadException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handle UploadedFile object input.
 */
class UploadedFileAdapter implements AdapterInterface
{
    public function __construct(private readonly FileSystemInterface $filesystem)
    {

    }

    /**
     * Test if the adapter supports the input.
     */
    public function supports(mixed $input): bool
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
    public function normalize($input): File
    {
        if (!$input->isValid()) {
            throw new UploadException(sprintf('The upload failed. Reason: %s', $input->getErrorMessage()));
        }
        $file = $this->filesystem->move($input->getRealPath(), $this->filesystem->generateTemporaryPath());
        $file->setVirtualName($input->getClientOriginalName());
        return $file;
    }
}
