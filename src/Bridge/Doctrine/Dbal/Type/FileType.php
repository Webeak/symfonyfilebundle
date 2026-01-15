<?php
namespace Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Webeak\Bundle\FileBundle\PublicFile;

class FileType extends Type
{
    const TYPE_NAME = 'file';

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getVarcharTypeDeclarationSQL([
            'length' => 1024,
            'nullable' => $fieldDeclaration['nullable'] ?? false,
        ]);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        if (!$value) {
            return null;
        }
        if (is_array($value)) {
            $value = PublicFile::createFromGenericRepresentation($value);
        }
        return serialize($value);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        if (!$value) {
            return null;
        }
        return unserialize($value);
    }

    public function getName()
    {
        return self::TYPE_NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform)
    {
        return true;
    }
}
