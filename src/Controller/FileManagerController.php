<?php
namespace Webeak\Bundle\FileBundle\Controller;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Webeak\Bundle\ErrorTrackerBundle\ErrorTrackerInterface;
use Webeak\Bundle\EssentialBundle\Controller\JsonController;
use Webeak\Bundle\FileBundle\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webeak\Bundle\FileBundle\FileManager;

class FileManagerController extends JsonController
{
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
            $manager = $this->get(FileManager::class);
            $preset = $request->get('preset');
            $resultFiles = $manager->registerTemporarily(array_values($files), $preset);
            $manager->flush();
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
     */
    public function proxy($identifier, $version = 'default', $type = null)
    {
        $manager = $this->get(FileManager::class);
        try {
            $file = $manager->get($identifier);
            if ($file->hasVersion($version)) {
                $versionFile = $file->getVersion($version);
                return new BinaryFileResponse($versionFile);
            } else if ($file->hasDefaultVersion() && $file->getVersion('default')->isImage()) {
                $type = 'image';
            }
        } catch (FileNotFoundException $e) {
            // Nothing to do here.
        } catch (\Exception $e) {
            // Should not happen, something went wrong.
            $tracker = $this->get(ErrorTrackerInterface::class);
            $tracker->track($e, ['identifier' => $identifier, 'version' => $version]);
        }
        // Generic case: 404
        if ($type === 'i' || $type === 'image') {
            $path = null;
            try {
                $kernel = $this->get('kernel');
                $path = $this->getParameter('wb_file.not_found_image_path');
            } catch (\Exception $e) { }
            if (!$path) {
                $path = $kernel->locateResource('@WebeakFileBundle/Resources/assets/404.jpg');
            }
            return new BinaryFileResponse($path);
        }
        $translator = $this->get('translator');
        throw $this->createNotFoundException($translator->trans('exception.file-manager.file-not-found', [
            '%identifier%' => $identifier
        ], 'exceptions'));
    }
}
