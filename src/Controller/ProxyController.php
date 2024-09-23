<?php
namespace Webeak\Bundle\FileBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Webeak\Bundle\EssentialBundle\Annotation\Route;
use Webeak\Bundle\EssentialBundle\Controller\Controller;

class ProxyController extends Controller
{
    /**
     * @throws
     */
    #[Route('/_wb/file/proxy/read', name: 'wb_file_proxy_read', public: true)]
    public function read(Request $request): JsonResponse
    {

    }
}
