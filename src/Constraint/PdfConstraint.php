<?php
namespace Webeak\Bundle\FileBundle\Constraint;

use Symfony\Component\Validator\Constraints\File;

/**
 * Ensure the validated value is a PDF.
 */
class PdfConstraint extends File
{
    public function __construct($options = null)
    {
        parent::__construct($options);
        $this->mimeTypes = array_unique(array_merge((array)$this->mimeTypes, ['application/pdf', 'application/x-pdf']));
    }
}
