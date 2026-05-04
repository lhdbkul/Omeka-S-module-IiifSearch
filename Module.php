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
use Laminas\View\Renderer\PhpRenderer;
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
     * Re-encode decoded slashes in iiif url identifiers.
     *
     * When apache or a reverse proxy does not preserve encoded slashes (%2F),
     * they get decoded to "/" in the path, breaking segment routes that expect
     * the identifier as a single path segment. This listener detects where the
     * identifier ends by looking for known iiif keywords (manifest, canvas,
     * info.json, etc.) from the right of the path, then re-encodes all "/"
     * within the identifier portion.
     *
     * This is a no-op when identifiers contain no decoded slashes (e.g. simple
     * numeric ids or already-encoded identifiers).
     *
     * @see https://iiif.io/api/presentation/3.0/
     * @see https://iiif.io/api/presentation/2.1/
     * @see https://iiif.io/api/image/3.0/
     *
     * Adapted and copied id:
     * @see \IiifServer\Module::reencodeIdentifierSlashes()
     * @see \IiifSearch\Module::reencodeIdentifierSlashes()
     */
    public function reencodeIdentifierSlashes(MvcEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof \Laminas\Http\PhpEnvironment\Request) {
            return;
        }

        $path = $request->getUri()->getPath();

        // Quick check: only process iiif search paths.
        if (strpos($path, '/iiif-search/') === false) {
            return;
        }

        // Parse: /iiif-search/{identifier}[/search|/list/{name}]
        if (!preg_match('#^(/iiif-search)(/[^?]*)$#', $path, $matches)) {
            return;
        }

        // Remove leading slash.
        $remainder = substr($matches[2], 1);
        $segments = explode('/', $remainder);
        $count = count($segments);

        // A single segment means no slashes in the identifier.
        if ($count <= 1) {
            return;
        }

        // Known iiif Search keywords that appear after the identifier.
        static $searchKeywords = [
            'search' => true,
            'autocomplete' => true,
            'list' => true,
        ];

        // Scan from the right to find the first iiif keyword.
        // Start at index 1 (the identifier needs at least one segment).
        $suffixCount = 0;
        for ($i = $count - 1; $i >= 1; $i--) {
            if (isset($searchKeywords[$segments[$i]])) {
                $suffixCount = $count - $i;
                break;
            }
        }

        $identifierCount = $count - $suffixCount;
        if ($identifierCount <= 1) {
            return;
        }

        // Re-encode slashes within the identifier portion.
        $identifierParts = array_slice($segments, 0, $identifierCount);
        $encodedIdentifier = implode('%2F', $identifierParts);

        $suffixParts = array_slice($segments, $identifierCount);
        if ($suffixParts) {
            $encodedIdentifier .= '/' . implode('/', $suffixParts);
        }

        $iiifBase = $matches[1];
        $newPath = $iiifBase . '/' . $encodedIdentifier;
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
            || !$this->checkModuleActiveVersion('Common', '3.4.85')
        ) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.85'
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

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $formManager = $services->get('FormElementManager');

        $this->initDataToPopulate($settings, 'config');
        $data = $this->prepareDataToPopulate($settings, 'config');
        if ($data === null) {
            return null;
        }

        $form = $formManager->get(\IiifSearch\Form\ConfigForm::class);
        $form->init();
        $form->setData($data);
        $form->prepare();

        $view = $renderer;
        $translate = $view->plugin('translate');
        $escape = $view->plugin('escapeHtml');

        // Dispatch elements to tabs using element_groups.
        $elementGroups = $form->getOption('element_groups') ?: [];
        $tabs = array_fill_keys(array_keys($elementGroups), '');
        $ungrouped = '';

        foreach ($form as $element) {
            if ($element instanceof \Laminas\Form\FieldsetInterface) {
                $group = $element->getOption('element_group');
                if ($group && isset($tabs[$group])) {
                    $tabs[$group] .= $view->formCollection($element);
                }
                continue;
            }
            $group = $element->getOption('element_group');
            if ($group && isset($tabs[$group])) {
                $tabs[$group] .= $view->formRow($element);
            } else {
                $ungrouped .= $view->formRow($element);
            }
        }

        $firstGroup = array_key_first($elementGroups);
        if ($firstGroup && $ungrouped !== '') {
            $tabs[$firstGroup] = $ungrouped . $tabs[$firstGroup];
        }

        // Module navigation bar shared with IiifServer and ImageServer.
        $iiifModules = ['IiifServer', 'IiifSearch', 'ImageServer'];
        $moduleNav = $view->moduleConfigNav($iiifModules, 'IiifSearch');

        // Build tabs.
        $tabNav = '';
        $tabContent = '';
        $isFirst = true;
        foreach ($elementGroups as $groupName => $groupLabel) {
            if (empty($tabs[$groupName])) {
                continue;
            }
            $activeClass = $isFirst ? ' class="active"' : '';
            $sectionClass = $isFirst ? 'section active' : 'section';
            $tabNav .= '<li' . $activeClass . '><a href="#iiifsearch-' . $groupName . '">'
                . $escape($translate($groupLabel)) . '</a></li>';
            $tabContent .= '<div id="iiifsearch-' . $groupName . '" class="' . $sectionClass . '">'
                . $tabs[$groupName] . '</div>';
            $isFirst = false;
        }

        return $moduleNav
            . '<ul class="section-nav" style="list-style:none;padding:0;">'
            . $tabNav
            . '</ul>'
            . $tabContent;
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

        $versions = $settings->get('iiifsearch_versions', ['1', '2']);
        $versions = array_values(array_intersect(['1', '2'], (array) $versions));
        if (empty($versions)) {
            return;
        }

        $searchUrlV1 = $fixSlash($urlHelper('iiifsearch/search', ['id' => $identifier], ['force_canonical' => true]));
        $searchUrlV2 = $fixSlash($urlHelper('iiifsearch/search-2', ['id' => $identifier], ['force_canonical' => true]));

        $isVersion2 = !is_object($manifest);
        if ($isVersion2) {
            // Manifest IIIF Presentation 2 only carries Search v1 services.
            if (in_array('1', $versions, true)) {
                $manifest['service'][] = [
                    '@context' => 'http://iiif.io/api/search/1/context.json',
                    '@id' => $searchUrlV1,
                    'profile' => 'http://iiif.io/api/search/1/search',
                    'label' => 'Search within this manifest', // @translate
                ];
            }
        } else {
            $services = [];
            if (in_array('1', $versions, true)) {
                $services[] = [
                    '@context' => 'http://iiif.io/api/search/1/context.json',
                    'id' => $searchUrlV1,
                    'type' => 'SearchService1',
                    'profile' => 'http://iiif.io/api/search/1/search',
                    'label' => 'Search within this manifest', // @translate
                ];
            }
            if (in_array('2', $versions, true)) {
                $services[] = [
                    '@context' => 'http://iiif.io/api/search/2/context.json',
                    'id' => $searchUrlV2,
                    'type' => 'SearchService2',
                    'profile' => 'http://iiif.io/api/search/2/search',
                    'label' => 'Search within this manifest', // @translate
                ];
            }
            // Check version of module IiifServer.
            if (method_exists($manifest, 'getPropertyRequirements')) {
                foreach ($services as $service) {
                    $manifest['service'][] = new \IiifServer\Iiif\Service($service);
                }
            } else {
                foreach ($services as $service) {
                    $manifest->appendService(new \IiifServer\Iiif\Service($resource, $service));
                }
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
