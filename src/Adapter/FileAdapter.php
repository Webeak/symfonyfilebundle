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
     *
     * @param mixed $input
     *
     * @return boolean
     */
    public function supports($input)
    {
        return $input instanceof File;
    }

    /**
     * Normalize the input value into a File instance.
     *
     * @param File $input the input to convert into a File instance
     *
     * @return File
     */
    public function normalize($input)
    {
        return $input;
    }
}
