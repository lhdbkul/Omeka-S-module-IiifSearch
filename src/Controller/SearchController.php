<?php declare(strict_types=1);

namespace IiifSearch\Controller;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class SearchController extends AbstractActionController
{
    public function indexAction()
    {
        // TODO The output must be a valid json iiif response.

        $id = $this->params('id');
        if (empty($id)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Missing or empty query.'), // @translate
            ]);
        }

        $q = (string) $this->params()->fromQuery('q');
        if (!strlen($q)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Missing or empty query.'), // @translate
            ]);
        }

        // Exception is automatically thrown by api.
        $item = null;
        if (is_numeric($id)) {
            try {
                $item = $this->api()->read('items', $id)->getContent();
            } catch (\Throwable $e) {
                // See below.
            }
        } elseif (class_exists('CleanUrl\Module', false)) {
            $item = $this->viewHelpers()->get('getResourceFromIdentifier')($id);
        }

        if (empty($item)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Resource not found or unavailable.'),  // @translate
            ]);
        }

        $iiifSearch = $this->viewHelpers()->get('iiifSearch');
        $searchResponse = $iiifSearch($item);

        if (!$searchResponse) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Search is not available for this resource.'), // @translate
            ]);
        }

        return $this->jsonLd($searchResponse);
    }

    public function annotationListAction()
    {
        // TODO Implement annotation-list action.
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
        return new JsonModel([
            'status' => 'error',
            'message' => $this->translate('Direct request to annotation-list is not implemented.'), // @translate
        ]);
    }

    /**
     * Serve a single page extracted from a multipage ALTO XML document.
     */
    public function altoPageAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        $pageIndex = (int) $this->params('page');
        $splitter = $this->viewHelpers()->get('xmlAltoSplitter');
        $path = $splitter($item, $pageIndex);
        if (!$path || !is_readable($path)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/xml+alto; charset=utf-8');
        $headers->addHeaderLine('Content-Length', (string) filesize($path));
        $headers->addHeaderLine('Cache-Control', 'public, max-age=3600');
        $response->setContent((string) file_get_contents($path));
        return $response;
    }

    /**
     * Serve a IIIF AnnotationPage built from a single ALTO page (supplementing
     * motivation, suitable for Mirador text overlay).
     */
    public function annotationPageAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        $pageIndex = (int) $this->params('page');
        $splitter = $this->viewHelpers()->get('xmlAltoSplitter');
        $path = $splitter($item, $pageIndex);
        if (!$path || !is_readable($path)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }

        $annotations = $this->viewHelpers()->get('iiifAltoAnnotations');
        $page = $annotations($item, $pageIndex, $path);
        if (!$page) {
            return $this->errorResponse(Response::STATUS_CODE_500, 'Unable to build annotation page.'); // @translate
        }

        return $this->jsonLd($page);
    }

    protected function resolveItem(): ?\Omeka\Api\Representation\ItemRepresentation
    {
        $id = $this->params('id');
        if (empty($id)) {
            return null;
        }
        if (is_numeric($id)) {
            try {
                return $this->api()->read('items', $id)->getContent();
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (class_exists('CleanUrl\Module', false)) {
            $resource = $this->viewHelpers()->get('getResourceFromIdentifier')($id);
            if ($resource && $resource->resourceName() === 'items') {
                return $resource;
            }
        }
        return null;
    }

    protected function errorResponse(int $code, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($code);
        return new JsonModel([
            'status' => 'error',
            'message' => $this->translate($message),
        ]);
    }
}
