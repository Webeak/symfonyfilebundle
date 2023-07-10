<?php
namespace Webeak\Bundle\FileBundle\Exception;

use Webeak\Bundle\EssentialBundle\Exception\SystemException;

/**
 * Triggered when trying to access a protected file with insufficient permissions.
 */
class FileProtectedException extends SystemException
{

}
