<?php
namespace Webeak\Bundle\FileBundle;

use Webeak\Component\Utils\ArrayUtils;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;

/**
 * Set of utility methods useful to work with files.
 *
 * @property FileManager $fileManager
 */
trait FilesTrait
{
    /**
     * Handle changes on a file or on a collection of files.
     *
     * @param mixed   $before   value of the last snapshot
     * @param mixed   $now      current value
     * @param boolean $multiple does the field can contain multiple files?
     *                          (optional, default: false)
     *
     * @return mixed
     */
    protected function handleFileChanges($before, $now, $multiple = false) {
        if ($multiple) {
            return $this->handleMultipleFileChange($before, $now);
        }
        return $this->handleSingleFileChange($before, $now);
    }

    /**
     * Handle a single file change.
     * Removes the old file if defined and different from the new one.
     * This method also confirm the new file.
     *
     * @param mixed $before
     * @param mixed $now
     *
     * @return PublicFile|null
     */
    protected function handleSingleFileChange($before, $now) {
        $beforeIdentifier = $this->normalizeFileToIdentifier($before);
        $now = $this->normalizeFileToPublicFile($now);
        $identifierToRemove = null;
        if ($beforeIdentifier && (($now && $beforeIdentifier !== $now->identifier) || $now === null)) {
            $identifierToRemove = $beforeIdentifier;
        }
        if ($now instanceof PublicFile && !!$now->identifier) {
            $now = $this->fileManager->confirmRegistration($now);
        }
        //
        // If a previous file was set it must be removed, but IT MUST be done AFTER
        // the confirmRegistration() because a duplicate may have been found and the new value could be equal to
        // the old value (the one we wanted to removed). In that case the "usageCount" has been incremented and so
        // removing the file will only decrease the usage count.
        //
        // Bottom line is: do the remove LAST or both files will be lost.
        //
        if ($identifierToRemove !== null) {
            try {
                $this->fileManager->remove($beforeIdentifier);
            } catch (FileNotFoundException $e) {
                // File not found? It's ok we wanted to remove it anyway..
            }
        }
        return $now;
    }

    /**
     * Handle multiple files changes.
     * Removes old files that are not present anymore and confirm new ones.
     *
     * @param mixed $before
     * @param mixed $now
     *
     * @return PublicFile[]|null
     */
    protected function handleMultipleFileChange($before, $now) {
        $before = ArrayUtils::ensureArray($before);
        $now = ArrayUtils::ensureArray($now);
        for ($i = 0, $bc = count($before); $i < $bc; ++$i) {
            if (($normalized = $this->normalizeFileToIdentifier($before[$i])) !== null) {
                $before[$i] = $normalized;
            } else {
                array_splice($before, $i--, 1);
                --$bc;
            }
        }
        for ($i = 0, $nc = count($now); $i < $nc; ++$i) {
            if (($normalized = $this->normalizeFileToPublicFile($now[$i])) !== null) {
                $now[$i] = $normalized;
            } else {
                array_splice($now, $i--, 1);
                --$nc;
            }
        }
        //
        // Remove old files missing in the new array
        //
        // Removes must always be done at last, so we only populate an array here.
        // The real remove will be done after confirmations (see "handleSingleFileChange" for more details).
        //
        $toRemove = [];
        for ($i = 0; $i < $bc; ++$i) {
            for ($j = 0; $j < $nc; ++$j) {
                if ($now[$j]->identifier === $before[$i]) {
                    break ;
                }
            }
            if ($j >= $nc) {
                $toRemove[] = $before[$i];
            }
        }
        // Confirm new files
        for ($i = 0; $i < $nc; ++$i) {
            for ($j = 0; $j < $bc; ++$j) {
                if ($now[$i]->identifier === $before[$j]) {
                    break ;
                }
            }
            if ($j >= $bc) {
                $now[$i] = $this->fileManager->confirmRegistration($now[$i]);
            }
        }
        // Now do the remove
        for ($i = 0, $ii = count($toRemove); $i < $ii; ++$i) {
            try {
                $this->fileManager->remove($toRemove[$i]);
            } catch (FileNotFoundException $e) {
                // File not found? It's ok we wanted to remove it anyway..
            }
        }
        return $now;
    }

    /**
     * Normalize an input to a file identifier.
     * Null is returned if no identifier can be found.
     *
     * @param mixed $input
     *
     * @return string|null
     */
    protected function normalizeFileToIdentifier($input) {
        $inputIdentifier = is_string($input) ? $input : null;
        if (is_array($input) && $input) {
            $input = PublicFile::createFromGenericRepresentation($input);
        }
        if ($input instanceof PublicFile) {
            $inputIdentifier = $input->identifier;
        }
        return $inputIdentifier;
    }

    /**
     * Normalize an input to a PublicFile instance.
     * Null is returned if no valid PublicFile can be created from the input.
     *
     * @param mixed $input
     *
     * @return PublicFile|null
     */
    protected function normalizeFileToPublicFile($input) {
        if (is_array($input) && $input) {
            return PublicFile::createFromGenericRepresentation($input);
        } else if (is_string($input)) {
            return $this->fileManager->get($input)->getPublicFile();
        }
        return $input instanceof PublicFile ? $input : null;
    }
}
