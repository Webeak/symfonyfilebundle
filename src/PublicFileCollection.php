<?php
namespace Webeak\Bundle\FileBundle;

/**
 * Array of public files.
 */
class PublicFileCollection extends \ArrayObject
{
    /**
     * Creates a PublicFileCollection instance from an array.
     */
    public static function createFromArray(array $input): PublicFileCollection
    {
        $output = new PublicFileCollection();
        $input = array_values($input);
        for ($i = 0, $ii = count($input); $i < $ii; ++$i) {
            $output[] = $input[$i];
        }
        return $output;
    }
}
