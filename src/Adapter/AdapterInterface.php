<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\FileBundle\File;

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
     * Normalize the input value into a (symfony) File instance.
     */
    public function normalize(mixed $input): File;
}
