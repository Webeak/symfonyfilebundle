<?php
namespace Webeak\Bundle\FileBundle\Aws;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Mime\MimeTypes;
use Webeak\Bundle\FileBundle\File;
use Webeak\Bundle\FileBundle\FileSystem\AwsS3FileSystem;

class BucketFile extends File
{
    public function __construct($path, $checkPath = false, File $file = null)
    {
        parent::__construct($path, $checkPath, $file);
    }

    public function getMimeType()
    {
        $content = $this->getContent();
        $fh = fopen('php://memory', 'w+b');
        @fwrite($fh, $content);
        $contentType = @mime_content_type($fh);
        @fclose($fh);
        return $contentType;
    }

    public function guessMimeType(string $path): ?string
    {
        return $this->getMimeType();
    }

    public function getHash()
    {
        $normalized = AwsS3FileSystem::$Instance->normalizePath($this->getRealPath());
        $content = AwsS3FileSystem::$Instance->read($this);
        return md5($content);
    }

    public function getRealPath()
    {
        return $this->getPath() . '/' . $this->getFilename();
    }

    public function move(string $directory, string $name = null)
    {
        $target = $this->getTargetFile($directory, $name);
        $content = AwsS3FileSystem::$Instance->read($this);
        AwsS3FileSystem::$Instance->write($target, $content);
        AwsS3FileSystem::$Instance->remove($this);
        return $target;
    }

    public function getContent(): string
    {
        $content = AwsS3FileSystem::$Instance->read($this);

        if (!$content) {
            throw new FileException(sprintf('Could not get the content of the file "%s".', $this->getPathname()));
        }

        return $content;
    }

    public function getSize()
    {
        return 0;
    }

    public function isFile()
    {
        return true;
    }

    public function isReadable()
    {
        return true;
    }

    /**
     * @return self
     */
    protected function getTargetFile(string $directory, string $name = null)
    {
        $target = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR.(null === $name ? $this->getBasename() : $this->getName($name));
        return new self($target, false);
    }

    /**
     * Returns locale independent base name of the given path.
     *
     * @return string
     */
    protected function getName(string $name)
    {
        $originalName = str_replace('\\', '/', $name);
        $pos = strrpos($originalName, '/');
        $originalName = false === $pos ? $originalName : substr($originalName, $pos + 1);

        return $originalName;
    }

}
