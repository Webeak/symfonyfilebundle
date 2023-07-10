<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;

/**
 * Handle local path to a file.
 */
class LocalPathAdapter implements AdapterInterface
{
    public function __construct(private readonly FileSystemInterface $fileSystem)
    {

    }

    /**
     * Test if the adapter supports the input.
     */
    public function supports(mixed $input): bool
    {
        return is_string($input) && parse_url($input, PHP_URL_HOST) === null && file_exists($input);
    }

    /**
     * Normalize the input value into a (symfony) File instance.
     */
    public function normalize(mixed $input): File
    {
        $file = $this->fileSystem->copy($input, $this->fileSystem->generateTemporaryPath());
        $file->setVirtualName(substr($input, intval(strrpos(str_replace('\\', '/', $input), '/')) + 1));
        return $file;
    }
}
