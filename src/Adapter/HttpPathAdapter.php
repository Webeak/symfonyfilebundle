<?php
namespace Webeak\Bundle\FileBundle\Adapter;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webeak\Bundle\EssentialBundle\Exception\IOException;
use Webeak\Bundle\FileBundle\ManagedFile;
use Webeak\Bundle\FileBundle\VirtualFile;

/**
 * Handle http url input.
 */
class HttpPathAdapter implements AdapterInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {

    }

    /**
     * @inheritDoc
     */
    public function supports(mixed $input): bool
    {
        return is_string($input) && preg_match('#^https?:\/\/#', $input);
    }

    /**
     * @inheritDoc
     *
     * @throws IOException
     */
    public function normalize(mixed $input, ManagedFile $managedFile): VirtualFile
    {
        try {
            $response = $this->httpClient->request('GET', $input, [
                'timeout' => 50,
                'verify_peer' => false,
                'verify_host' => false,
                'max_redirects' => 10,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new IOException(sprintf('Failed to download file from "%s". Status code: %d', $input, $response->getStatusCode()));
            }
            $virtualFile = $managedFile->createVersion();
            $virtualFile->setVirtualName(basename($input));
            $virtualFile->setFileSystem($managedFile->getFileSystem());
            $virtualFile->setContent($response->getContent());
            return $virtualFile;

        } catch (\Exception $e) {
            throw new IOException(sprintf('An error occurred while downloading the file: %s', $e->getMessage()), 0, 500, $e);
        }
    }
}
