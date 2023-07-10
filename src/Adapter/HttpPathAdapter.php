<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;

/**
 * Handle http url input.
 */
class HttpPathAdapter implements AdapterInterface
{
    public function __construct(private readonly FileSystemInterface $fileSystem)
    {

    }

    /**
     * Test if the adapter supports the input.
     */
    public function supports(mixed $input): bool
    {
        return is_string($input) && !!preg_match('#^https?:\/\/#', $input);
    }

    /**
     * Normalize the input value into a (symfony) File instance.
     *
     * @throws
     */
    public function normalize(mixed $input): File
    {
        $tempPath = $this->fileSystem->generateTemporaryPath();
        if (!is_resource(($fp = @fopen($tempPath, 'w+')))) {
            throw new IOException(sprintf('Failed to open temporary path at "%s".', $tempPath), 0, 500, null, ['path' => $tempPath]);
        }
        if (($ch = curl_init(str_replace(" ", "%20", $input))) === false) {
            throw new IOException('curl_init() failed.', 0, 500, null, ['input' => $input, 'curl_error_code' => curl_errno($ch)]);
        }
        try {
            curl_setopt($ch, CURLOPT_TIMEOUT, 50);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            if (curl_exec($ch) !== false) {
                throw new IOException('curl request failed', 0, 500, null, ['input' => $input, 'curl_error_code' => curl_errno($ch)]);
            }
            $file = new File($tempPath);
            $file->setVirtualName(substr($input, intval(strrpos($input, '/')) + 1));
            return $file;
        } finally {
            curl_close($ch);
            fclose($fp);
        }
    }
}
