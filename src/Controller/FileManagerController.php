<?php
namespace Webeak\Bundle\FileBundle\Controller;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Controller\JsonController;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webeak\Bundle\FileBundle\Exception\FileProtectedException;
use Webeak\Bundle\FileBundle\FileManager;

class FileManagerController extends JsonController
{
    /** @var FileManager */
    private $fileManager;

    /** @var ErrorTrackerInterface */
    private $errorTracker;

    public function __construct(FileManager $fileManager, ErrorTrackerInterface $errorTracker)
    {
        $this->fileManager = $fileManager;
        $this->errorTracker = $errorTracker;
    }

    /**
     * @Route(name="wb_file_upload", path="/file/upload", methods={"POST"})
     *
     * @param Request $request
     *
     * @return array
     *
     * @throws
     */
    public function upload(Request $request)
    {
        $output = [];
        $files = $request->files->all();
        if (is_array($files) && count($files) > 0) {
            $preset = $request->get('preset');
            $resultFiles = $this->fileManager->registerTemporarily(array_values($files), $preset);
            $this->fileManager->flush();
            for ($i = 0, $ii = count($resultFiles); $i < $ii; ++$i) {
                $publicFile = $resultFiles[0]->getPublicFile();
                $output[] = array_merge($publicFile ? $publicFile->exportGenericRepresentation() : [], [
                    'errors' => $resultFiles[0]->getFlattenedErrors()
                ]);
            }
        } else {
            throw new BadRequestHttpException('No file found in the request.', null, 400);
        }
        return $output;
    }

    /**
     * @Route(name="wb_file_proxy", path="/file/proxy/{identifier}/{version}/{type}", methods={"GET"})
     *
     * @param string $identifier
     * @param string $version
     * @param string $type
     *
     * @return Response
     *
     * @throws
     */
    public function proxy($identifier, $version = 'default', $type = null, KernelInterface $kernel)
    {
        try {
            $file = $this->fileManager->get($identifier);
            if (!$file->hasVersion($version)) {
                throw new FileNotFoundException('Not found.');
            }
            $versionFile = $file->getVersion($version);
            return new BinaryFileResponse($versionFile);
        } catch (FileNotFoundException $e) {
            return $this->handleFetchErrorResponse(
                $type,
                'wb.file.not_found_image_path',
                '@FileBundle/Resources/assets/404.png',
                $this->createNotFoundException(),
                $kernel
            );
        } catch (FileProtectedException $e) {
            return $this->handleFetchErrorResponse(
                $type,
                'wb.file.access_denied_image_path',
                '@FileBundle/Resources/assets/403.png',
                $this->createAccessDeniedException(),
                $kernel
            );
        } catch (\Exception $e) {
            // Should not happen, something went wrong.
            $this->errorTracker->track($e, ['identifier' => $identifier, 'version' => $version]);
        }
    }

    /**
     * Creates a binary response in case the type is an image or throw the fallback exception.
     *
     * @param string          $type
     * @param string          $parameterName
     * @param string          $fallbackImage
     * @param \Exception      $fallbackExcption
     * @param KernelInterface $kernel
     *
     * @return BinaryFileResponse
     *
     * @throws
     */
    private function handleFetchErrorResponse(string $type,
                                              string $parameterName,
                                              string $fallbackImage,
                                              \Exception $fallbackExcption,
                                              KernelInterface $kernel)
    {
        if ($type === 'i' || $type === 'image') {
            $path = null;
            try {
                $path = $this->getParameter($parameterName);
            } catch (\Exception $e) { }
            if (!$path || !file_exists($path)) {
                $path = $kernel->locateResource($fallbackImage);
            }
            return new BinaryFileResponse($path);
        }
        throw $fallbackExcption;
    }
}
