<?php
namespace Webeak\Bundle\FileBundle\Serializer;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\PublicFile;
use Webeak\Component\Utils\ArrayUtils;
use Webeak\Component\Utils\StringUtils;

class PublicFileNormalizer implements NormalizerInterface, DenormalizerInterface
{
    const FORMAT = 'json';

    public function __construct(private readonly FileManager $fileManager,
                                private readonly string $environment)
    {

    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        return $object->exportGenericRepresentation();
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, $type, $format = null, array $context = [])
    {
        if ($data instanceof PublicFile) {
            return $data;
        }
        if (is_array($data) && count(array_diff(['identifier', 'name', 'type', 'versions', 'extra'], array_keys($data))) === 0) {
            return PublicFile::createFromGenericRepresentation($data);
        }
        $extra = (array)(array_key_exists('property', $context) ? $context['property']->getExtra() : []);
        if ((array_key_exists('allow_url_upload', $extra) && $extra['allow_url_upload'] && StringUtils::isUrl($data)) || StringUtils::isLocalPath($data)) {
            $preset = ArrayUtils::getValue($extra, 'url_upload_preset');
            $managedFiles = $this->fileManager->register($data, $preset);
            if (isset($managedFiles[0])) {
                $this->fileManager->flush();
                return $managedFiles[0]->getPublicFile();
            }
        } else if ($this->environment === 'dev' && StringUtils::isUrl($data)) {
            throw new UsageException(
                'You tried to add a file by Url without allowing it in the configuration. '.
                'You must set the "allow_url_upload" extra option to "true" in order to add files by Url. '.
                'You can also use the "url_upload_preset" to define a configuration preset to use when adding the file.'
            );
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null): bool
    {
        return $type === PublicFile::class;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null): bool
    {
        return self::FORMAT === $format && $data instanceof PublicFile;
    }
}
