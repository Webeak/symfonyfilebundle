<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Symfony\Component\HttpFoundation\File\File;

/**
 * Base interface all adapters must implement.
 */
interface AdapterInterface
{
    /**
     * Test if the adapter supports the input.
     *
     * @param mixed $input
     *
     * @return boolean
     */
    public function supports($input);

    /**
     * Normalize the input value into a (symfony) File instance.
     *
     * @param mixed $input
     *
     * @return File
     */
    public function normalize($input);
}
