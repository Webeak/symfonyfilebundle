<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use League\Flysystem\Filesystem;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\VirtualFile;

/**
 * Dummy adapter simply forwarding an already normalized File instance.
 */
class FileAdapter implements AdapterInterface
{
    /**
     * Test if the adapter supports the input.
     */
    public function supports(mixed $input): bool
    {
        return $input instanceof VirtualFile;
    }

    /**
     * Normalize the input value into a File instance.
     */
    public function normalize($input, ManagedFile $managedFile): VirtualFile
    {
        return $input;
    }
}
