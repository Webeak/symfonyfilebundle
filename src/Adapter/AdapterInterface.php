<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\VirtualFile;

/**
 * Base interface all adapters must implement.
 */
interface AdapterInterface
{
    /**
     * Test if the adapter supports the input.
     */
    public function supports(mixed $input): bool;

    /**
     * Normalize the input value into a VirtualFile instance.
     */
    public function normalize(mixed $input, ManagedFile $managedFile): VirtualFile;
}
