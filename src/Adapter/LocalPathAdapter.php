<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\VirtualFile;

/**
 * Handle local path to a file.
 */
class LocalPathAdapter implements AdapterInterface
{
    /**
     * @inheritDoc
     */
    public function supports(mixed $input): bool
    {
        return is_string($input) && parse_url($input, PHP_URL_HOST) === null && file_exists($input);
    }

    /**
     * @inheritDoc
     */
    public function normalize(mixed $input, ManagedFile $managedFile): VirtualFile
    {
        $virtualFile = $managedFile->createVersion();
        $virtualFile->setVirtualName(substr($input, intval(strrpos(str_replace('\\', '/', $input), '/')) + 1));
        $virtualFile->setFileSystem($managedFile->getFileSystem());
        $virtualFile->setContentStream(fopen($input, 'r'));
        return $virtualFile;
    }
}
