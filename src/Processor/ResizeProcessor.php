<?php
namespace Webeak\Bundle\FileBundle\Processor;

use Webeak\Bundle\FileBundle\VirtualFile;
use Webeak\Bundle\FileBundle\ManagedFile;
use Gregwar\ImageBundle\ImageHandler;
use Gregwar\ImageBundle\Services\ImageHandling;

/**
 * Processor handling image resizing.
 */
class ResizeProcessor extends AbstractProcessor
{
    /**
     * Target width to resize to.
     */
    public int $width = 0;

    /**
     * Target height to resize to.
     */
    public int $height = 0;

    /**
     * Background color if the image is too small
     * to fit the entire area after resize.
     */
    public string $background = '';

    /**
     * Mode of resizing.
     * Can be: 'default', 'scale', 'stretch', 'crop', 'zoomCrop'
     *
     * @see https://github.com/Gregwar/Image#basic-handling
     */
    public string $mode = 'default';

    public function __construct(private readonly ImageHandling $handler)
    {
        $this->background = 'transparent';
        $this->mode = 'crop';
    }

    /**
     * Test if the processor supports the input.
     *
     * @param VirtualFile        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return boolean
     */
    public function supports(VirtualFile $file, ManagedFile $parent): bool
    {
        return substr((string)$file->getMimeType(), 0, 6) === 'image/';
    }

    /**
     * Do the processing.
     *
     * @param VirtualFile        $file   file to do the processing for
     * @param ManagedFile $parent object containing the file
     *
     * @return VirtualFile
     */
    public function process(VirtualFile $file, ManagedFile $parent): VirtualFile
    {
        $content = $file->getContent();
        $tempPath = tempnam(sys_get_temp_dir(), 'image');
        file_put_contents($tempPath, $content);
        $imageHandler = $this->handler->open($tempPath);

        if (!$this->needsResizing($imageHandler)) {
            unlink($tempPath);
            return $file;
        }
        switch ($this->mode) {
            case 'scale': $imageHandler->scaleResize($this->width, $this->height, $this->background); break;
            case 'stretch': $imageHandler->forceResize($this->width, $this->height, $this->background); break;
            case 'crop': $imageHandler->cropResize($this->width, $this->height, $this->background); break;
            case 'zoomCrop': $imageHandler->zoomCrop($this->width, $this->height, $this->background, 'center', 'center'); break;
            default: $imageHandler->resize($this->width, $this->height, $this->background);
        }
        $imageHandler->save($tempPath);
        $processedContent = file_get_contents($tempPath);
        $file->setContent($processedContent);
        unlink($tempPath);
        return $file;
    }

    /**
     * Test if the input file actually needs a resize.
     * Do not apply unnecessary transformation that could alter the quality of the file.
     *
     * @param ImageHandler $handler
     *
     * @return boolean
     */
    private function needsResizing(ImageHandler $handler): bool
    {
        // Makes no sense to set the processor with these values, but whatever.
        if (!$this->width && !$this->height) {
            return false;
        }
        $imageWidth = $handler->width();
        $imageHeight = $handler->height();

        //
        // Scale
        // Resizes the image, will preserve scale, can enlarge it.
        //
        if ($this->mode === 'scale') {
            return
                (!$this->width && $this->height && $this->height !== $imageHeight) ||
                (!$this->height && $this->width && $this->width !== $imageWidth) ||
                ($this->width && $imageWidth >= $imageHeight && $imageWidth !== $this->width) ||
                ($this->height && $imageHeight >= $imageWidth && $imageHeight !== $this->height);
        }

        //
        // Stretch
        // Resizes the image forcing it to be exactly $width by $height.
        //
        if ($this->mode === 'stretch') {
            return ($this->width && $this->width !== $imageWidth) || ($this->height && $this->height !== $imageHeight);
        }

        //
        // Zoom crop
        // Resize and crop the image to fit to given dimensions.
        //
        if ($this->mode === 'zoomCrop') {
            return true;
        }

        //
        // Default behavior, resize only if at least one axis is greater than the target.
        //
        return ($this->width && $imageWidth > $this->width) || ($this->height && $imageHeight > $this->height);
    }
}
