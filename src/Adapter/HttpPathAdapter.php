<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Exception\RuntimeException;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem;

/**
 * Handle http url input.
 */
class HttpPathAdapter implements AdapterInterface
{
    /** @var FileSystem */
    private $filesystem;

    /** @var ErrorTrackerInterface */
    private $errorTracker;

    public function __construct(FileSystem $fileSystem, ErrorTrackerInterface $errorTracker)
    {
        $this->filesystem = $fileSystem;
        $this->errorTracker = $errorTracker;
    }

    /**
     * Test if the adapter supports the input.
     *
     * @param mixed $input
     *
     * @return boolean
     */
    public function supports($input)
    {
        return is_string($input) && !!preg_match('#^https?:\/\/#', $input);
    }

    /**
     * Normalize the input value into a (symfony) File instance.
     *
     * @param mixed $input
     *
     * @return File
     *
     * @throws
     */
    public function normalize($input)
    {
        $tempPath = $this->filesystem->generateTemporaryPath();
        if (!is_resource(($fp = @fopen($tempPath, 'w+')))) {
            $this->errorTracker->trackAndThrow(new RuntimeException('Failed to open temporary path.'), ['path' => $tempPath]);
        }
        if (($ch = curl_init(str_replace(" ", "%20", $input))) === false) {
            $this->errorTracker->trackAndThrow(
                new \RuntimeException('curl_init() failed.'),
                ['input' => $input, 'curl_error_code' => curl_errno($ch)]
            );
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if (curl_exec($ch) !== false) {
            $file = new File($tempPath);
            $file->setVirtualName(substr($input, intval(strrpos($input, '/')) + 1));
            return $file;
        }
        curl_close($ch);
        fclose($fp);
        return null;
    }
}
