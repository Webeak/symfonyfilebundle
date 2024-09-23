<?php
namespace Webeak\Bundle\FileBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webeak\Bundle\EssentialBundle\Annotation\Route;
use Webeak\Bundle\EssentialBundle\Controller\Controller;
use Webeak\Bundle\EssentialBundle\HttpFoundation\XssiSafeJsonResponse;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Component\Utils\ArrayUtils;

class FileController extends Controller
{
    /**
     * @throws
     */
    #[Route('/_wb/file/list', name: 'wb_file_api_list', public: true)]
    public function list(Request $request, FileManager $fileManager): XssiSafeJsonResponse
    {
        $offset = intval($request->query->get('offset', 0));
        $maxResults = max(1, intval($request->query->get('maxResults', 20)));
        $storageType = $request->query->get('storageType');
        if (!$storageType) {
            $this->stopForBadInput();
        }
        $results = $fileManager->find($storageType, $offset, $request->query->all(), $maxResults);
        return new XssiSafeJsonResponse($results);
    }

    /**
     * @throws
     */
    #[Route('/_wb/file/save-file', name: 'wb_file_api_save_file', methods: ['POST'], public: true)]
    public function saveFile(Request $request, FileManager $fileManager): XssiSafeJsonResponse
    {
        $files = ArrayUtils::ensureArray(ArrayUtils::getValue($request->getPayload()->all(), 'files'));
        foreach ($files as $file) {
            $managedFile = $fileManager->get($file[0]['identifier']);
            $fileManager->confirmRegistration($managedFile);
        }
        $fileManager->flush();
        return new XssiSafeJsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws
     */
    #[Route('/_wb/file/remove-file/{ref}', name: 'wb_file_api_remove_file', methods: ['DELETE'], public: true)]
    public function removeFile(string $ref, FileManager $fileManager): XssiSafeJsonResponse
    {
        $fileManager->remove($ref);
        return new XssiSafeJsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws
     */
    #[Route('/_wb/file/hard-remove-file/{ref}', name: 'wb_file_api_hard_remove_file', methods: ['DELETE'], public: true)]
    public function hardRemoveFile(string $ref, FileManager $fileManager): XssiSafeJsonResponse
    {
        $fileManager->remove($ref, null, true);
        return new XssiSafeJsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws
     */
    #[Route('/_wb/file/confirm-file/{ref}', name: 'wb_file_api_confirm_file', methods: ['POST'], public: true)]
    public function confirmFile(string $ref, FileManager $fileManager): XssiSafeJsonResponse
    {
        $fileManager->confirmRegistration($ref);
        return new XssiSafeJsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
