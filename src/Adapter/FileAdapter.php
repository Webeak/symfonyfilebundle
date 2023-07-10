<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\FileBundle\File;

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
        return $input instanceof File;
    }

    /**
     * Normalize the input value into a File instance.
     */
    public function normalize($input): File
    {
        return $input;
    }
}
