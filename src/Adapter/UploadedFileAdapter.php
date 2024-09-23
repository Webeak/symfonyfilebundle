<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Webeak\Bundle\FileBundle\Exception\UploadException;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\VirtualFile;

/**
 * Handle UploadedFile object input.
 */
class UploadedFileAdapter implements AdapterInterface
{
    /**
     * @inheritDoc
     */
    public function supports(mixed $input): bool
    {
        return $input instanceof UploadedFile;
    }

    /**
     * @inheritDoc
     *
     * @throws
     */
    public function normalize($input, ManagedFile $managedFile): VirtualFile
    {
        /** @var UploadedFile $input */
        if (!$input->isValid()) {
            throw new UploadException(sprintf('The upload failed. Reason: %s', $input->getErrorMessage()));
        }
        $virtualFile = $managedFile->createVersion();
        $virtualFile->setVirtualName($input->getClientOriginalName());
        $virtualFile->setFileSystem($managedFile->getFileSystem());
        $virtualFile->setContentStream(fopen($input->getRealPath(), 'r'));
        return $virtualFile;
    }
}
