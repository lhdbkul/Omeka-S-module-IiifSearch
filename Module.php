<?php declare(strict_types=1);

namespace IiifSearch;

if (!class_exists('Common\TraitModule', false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, 'IiifSearch\Controller\Search');

        // Re-encode decoded slashes in identifiers for search routes.
        $event->getApplication()->getEventManager()
            ->attach(MvcEvent::EVENT_ROUTE, [$this, 'reencodeIdentifierSlashes'], 1000);
    }

    /**
     * Re-encode decoded slashes in IIIF Search URL identifiers.
     *
     * @see \IiifServer\Module::reencodeIdentifierSlashes()
     */
    public function reencodeIdentifierSlashes(MvcEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof \Laminas\Http\PhpEnvironment\Request) {
            return;
        }

        $path = $request->getUri()->getPath();

        // Quick check: only process IIIF Search paths.
        if (strpos($path, '/iiif-search/') === false) {
            return;
        }

        // Parse: /iiif-search/{identifier}[/search|/list/{name}]
        if (!preg_match('#^(/iiif-search)(/[^?]*)$#', $path, $matches)) {
            return;
        }

        $remainder = substr($matches[2], 1);
        $segments = explode('/', $remainder);
        $count = count($segments);

        if ($count <= 1) {
            return;
        }

        // Known IIIF Search keywords that appear after the identifier.
        // @see https://iiif.io/api/search/1.0/
        // @see https://iiif.io/api/search/2.0/
        static $searchKeywords = [
            'search' => true,
            'autocomplete' => true,
            'list' => true,
        ];

        $suffixCount = 0;
        for ($i = $count - 1; $i >= 1; $i--) {
            if (isset($searchKeywords[$segments[$i]])) {
                $suffixCount = $count - $i;
                break;
            }
        }

        $idCount = $count - $suffixCount;
        if ($idCount <= 1) {
            return;
        }

        $idParts = array_slice($segments, 0, $idCount);
        $suffixParts = array_slice($segments, $idCount);
        $newRemainder = implode('%2F', $idParts);
        if ($suffixParts) {
            $newRemainder .= '/' . implode('/', $suffixParts);
        }

        $newPath = $matches[1] . '/' . $newRemainder;
        if ($newPath !== $path) {
            $request->getUri()->setPath($newPath);
        }
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        $errors = [];

        if (!method_exists($this, 'checkModuleActiveVersion')
            || !$this->checkModuleActiveVersion('Common', '3.4.84')
        ) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.84'
            );
            $errors[] = (string) $message;
        }

        $this->checkExtractOcr($errors);

        if ($errors) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                implode("\n", $errors)
            );
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            '*',
            'iiifserver.manifest',
            [$this, 'handleIiifServerManifest']
        );
    }

    public function handleIiifServerManifest(Event $event): void
    {
        // Target is the view. Available keys: "format", the manifest, info etc
        // according to format, "resource", "type".

        // This is the iiif type, not omeka one.
        $type = $event->getParam('type');

        if ($type === 'media' && $event->getParam('format') === 'canvas') {
            $this->handleIiifServerCanvas($event);
            return;
        }

        if ($type !== 'item') {
            return;
        }

        $resource = $event->getParam('resource');

        // Check first if there is a simple file with data (see module ExtractOcr).
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $resourceId = $resource->id();
        $simpleFilepath = null;
        $localPaths = [
            $basePath . '/iiif-search/' . $resourceId . '.tsv',
            $basePath . '/alto/' . $resourceId . '.alto.xml',
            $basePath . '/hocr/' . $resourceId . '.hocr.html',
            $basePath . '/pdf2xml/' . $resourceId . '.xml',
            // Old path before ExtractOcr 3.4.7.
            $basePath . '/iiif-search/' . $resourceId . '.xml',
        ];
        foreach ($localPaths as $path) {
            if (file_exists($path)) {
                $simpleFilepath = $path;
                break;
            }
        }

        // Else check if resource has at least one XML file for search.
        if (!$simpleFilepath) {
            $searchServiceAvailable = false;
            $searchMediaTypes = [
                'application/alto+xml',
                'text/vnd.hocr+html',
                'application/vnd.pdf2xml+xml',
                'text/tab-separated-values',
            ];
            foreach ($resource->media() as $media) {
                $mediaType = $media->mediaType();
                if (in_array($mediaType, $searchMediaTypes)) {
                    $searchServiceAvailable = true;
                    break;
                }
            }
            if (!$searchServiceAvailable) {
                return;
            }
        }

        $plugins = $this->getServiceLocator()->get('ViewHelperManager');
        $urlHelper = $plugins->get('url');
        $identifier = $plugins->has('iiifCleanIdentifiers')
            ? $plugins->get('iiifCleanIdentifiers')->__invoke($resource->id())
            : $resource->id();

        // When the server does not support encoded slashes, restore literal
        // slashes in generated URLs so they remain functional.
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $encodeSlash = (bool) $settings->get('iiifserver_identifier_encode_slash', false);
        $fixSlash = function (string $url) use ($encodeSlash): string {
            return $encodeSlash ? $url : strtr($url, ['%252F' => '/', '%2F' => '/']);
        };

        /** @var \IiifServer\Iiif\Manifest $manifest */
        $manifest = $event->getParam('manifest');

        $searchUrl = $fixSlash($urlHelper('iiifsearch/search', ['id' => $identifier], ['force_canonical' => true]));
        $isVersion2 = !is_object($manifest);
        if ($isVersion2) {
            $manifest['service'][] = [
                '@context' => 'http://iiif.io/api/search/1/context.json',
                '@id' => $searchUrl,
                'profile' => 'http://iiif.io/api/search/1/search',
                'label' => 'Search within this manifest', // @translate
            ];
        } else {
            $service1 = [
                '@context' => 'http://iiif.io/api/search/1/context.json',
                'id' => $searchUrl,
                'type' => 'SearchService1',
                'profile' => 'http://iiif.io/api/search/1/search',
                'label' => 'Search within this manifest', // @translate
            ];
            // Check version of module IiifServer.
            if (method_exists($manifest, 'getPropertyRequirements')) {
                $manifest['service'][] = new \IiifServer\Iiif\Service($service1);
            } else {
                $manifest->appendService(new \IiifServer\Iiif\Service($resource, $service1));
            }
        }

        $event->setParam('manifest', $manifest);
    }

    /**
     * Inject seeAlso (ALTO) and supplementing annotations on each canvas of an
     * item that owns a multipage ALTO XML media. Per-page resources are served
     * by IiifSearch endpoints (/iiif-search/:id/alto/:n.xml and
     * /iiif-search/:id/annotations/:n.json).
     */
    protected function handleIiifServerCanvas(Event $event): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('iiifsearch_alto_canvas_inject', true)) {
            return;
        }

        $media = $event->getParam('resource');
        if (!$media instanceof \Omeka\Api\Representation\MediaRepresentation) {
            return;
        }
        $item = $media->item();

        $plugins = $services->get('ViewHelperManager');
        if (!$plugins->has('xmlAltoSplitter')) {
            return;
        }
        $splitter = $plugins->get('xmlAltoSplitter');
        $altoMedia = $splitter->findMultipageAltoMedia($item);
        if (!$altoMedia) {
            return;
        }
        // The ALTO media itself is not a canvas-bearing media.
        if ($altoMedia->id() === $media->id()) {
            return;
        }

        $pageIndex = $this->canvasPageIndex($item, $media, $altoMedia);
        if ($pageIndex === null) {
            return;
        }

        // Generate (and cache) the per-page ALTO; skip injection if missing.
        $altoPath = $splitter($item, $pageIndex);
        if (!$altoPath) {
            return;
        }

        $urlHelper = $plugins->get('url');
        $identifier = $plugins->has('iiifCleanIdentifiers')
            ? $plugins->get('iiifCleanIdentifiers')->__invoke($item->id())
            : $item->id();
        $encodeSlash = (bool) $settings->get('iiifserver_identifier_encode_slash', false);
        $fixSlash = function (string $url) use ($encodeSlash): string {
            return $encodeSlash ? $url : strtr($url, ['%252F' => '/', '%2F' => '/']);
        };

        $altoUrl = $fixSlash($urlHelper(
            'iiifsearch/alto-page',
            ['id' => $identifier, 'page' => $pageIndex],
            ['force_canonical' => true]
        ));
        $annotationsUrl = $fixSlash($urlHelper(
            'iiifsearch/annotation-page',
            ['id' => $identifier, 'page' => $pageIndex],
            ['force_canonical' => true]
        ));

        $canvas = $event->getParam('canvas');
        $isVersion2 = !is_object($canvas);

        if ($isVersion2) {
            $canvas['seeAlso'][] = [
                '@id' => $altoUrl,
                'format' => 'application/xml+alto',
                'profile' => 'http://www.loc.gov/standards/alto/v4/alto.xsd',
                'label' => 'ALTO XML', // @translate
            ];
            $event->setParam('canvas', $canvas);
            return;
        }

        $seeAlso = [
            'id' => $altoUrl,
            'type' => 'Dataset',
            'format' => 'application/xml+alto',
            'profile' => 'http://www.loc.gov/standards/alto/v4/alto.xsd',
            'label' => ['none' => ['ALTO XML']],
        ];
        $annotationPage = [
            'id' => $annotationsUrl,
            'type' => 'AnnotationPage',
        ];

        if (method_exists($canvas, 'append')) {
            $canvas->append('seeAlso', $seeAlso);
            $canvas->append('annotations', $annotationPage);
        } else {
            $canvas['seeAlso'][] = $seeAlso;
            $canvas['annotations'][] = $annotationPage;
        }

        $event->setParam('canvas', $canvas);
    }

    /**
     * Map a canvas-bearing media to its zero-based ALTO page index.
     *
     * Default strategy: position among non-ALTO media of the item, matching the
     * splitter "order" mapping. Other strategies are honored when the splitter
     * is configured for them.
     */
    protected function canvasPageIndex(
        \Omeka\Api\Representation\ItemRepresentation $item,
        \Omeka\Api\Representation\MediaRepresentation $media,
        \Omeka\Api\Representation\MediaRepresentation $altoMedia
    ): ?int {
        $i = 0;
        foreach ($item->media() as $m) {
            if ($m->id() === $altoMedia->id()) {
                continue;
            }
            if ($m->id() === $media->id()) {
                return $i;
            }
            $i++;
        }
        return null;
    }

    protected function checkExtractOcr(array &$errors = []): void
    {
        if (class_exists('ExtractOcr\Module', false)) {
            $services = $this->getServiceLocator();
            $translator = $services->get('MvcTranslator');
            $connection = $services->get('Omeka\Connection');
            $qb = $connection->createQueryBuilder();
            $qb
                ->select('module.version')
                ->from('module', 'module')
                ->where($qb->expr()->eq('module.id', ':module'));
            $moduleVersion = $connection->executeQuery($qb, ['module' => 'ExtractOcr'])->fetchOne();
            if (version_compare($moduleVersion, '3.4.8', '<')) {
                $message = new \Omeka\Stdlib\Message(
                    $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                    'ExtractOcr', '3.4.8'
                );
                $errors[] = (string) $message;
            }
        }
    }
}
