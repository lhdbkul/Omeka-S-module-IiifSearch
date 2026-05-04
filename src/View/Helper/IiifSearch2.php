<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Iiif\AnnotationList;
use IiifSearch\Iiif\AnnotationSearchResult;
use IiifSearch\Iiif\Search2\Annotation;
use IiifSearch\Iiif\Search2\AnnotationCollection;
use IiifSearch\Iiif\Search2\AnnotationPage;
use IiifSearch\Iiif\Search2\HitAnnotation;
use IiifSearch\Iiif\SearchHit;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Build a IIIF Content Search 2.0 response (W3C AnnotationPage) from the v1
 * search engine output.
 *
 * The v1 helper holds the parsing logic (ALTO, hOCR, PDF2XML, TSV, media
 * values). This helper reformats its result into the v2 JSON shape.
 */
class IiifSearch2 extends AbstractHelper
{
    public function __invoke(ItemRepresentation $item): ?AnnotationPage
    {
        $view = $this->getView();
        $iiifSearch = $view->plugin('iiifSearch');

        /** @var AnnotationList|null $listV1 */
        $listV1 = $iiifSearch($item);
        if ($listV1 === null) {
            return null;
        }

        $requestUri = $view->serverUrl(true);
        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => $requestUri]);

        $resources = $listV1['resources'] ?? [];
        $hitsV1 = $listV1['hits'] ?? [];

        $items = [];
        $resultIdMap = [];

        $index = 0;
        foreach ($resources as $resource) {
            $annotation = $this->buildAnnotation($resource, $requestUri, $index);
            if ($annotation === null) {
                continue;
            }
            $items[] = $annotation;
            $oldId = $resource->id();
            if ($oldId !== null) {
                $resultIdMap[(string) $oldId] = $annotation['id'];
            }
            ++$index;
        }

        $hitIndex = 0;
        foreach ($hitsV1 as $hit) {
            if (!$hit instanceof SearchHit) {
                continue;
            }
            $hitAnno = $this->buildHit($hit, $resultIdMap, $requestUri, $hitIndex);
            if ($hitAnno !== null) {
                $items[] = $hitAnno;
                ++$hitIndex;
            }
        }

        $page['items'] = $items;
        $page['partOf'] = $this->buildPartOf($requestUri, count($items));
        $page->isValid(true);
        return $page;
    }

    protected function buildAnnotation(
        $resource,
        string $requestUri,
        int $index
    ): ?Annotation {
        if (!$resource instanceof AnnotationSearchResult) {
            return null;
        }

        $box = $resource->getBox();
        $result = $resource->getResult();
        $options = $resource->getOptions();

        if (empty($result['chars'])) {
            return null;
        }

        $canvasUrl = isset($result['page']['number'])
            ? ($options['baseCanvasUrl'] ?? '') . $result['page']['number']
            : null;

        $target = $canvasUrl
            ? [
                'type' => 'SpecificResource',
                'source' => $canvasUrl,
                'selector' => empty($box) ? null : [
                    'type' => 'FragmentSelector',
                    'conformsTo' => 'http://www.w3.org/TR/media-frags/',
                    'value' => sprintf(
                        'xywh=%d,%d,%d,%d',
                        $box['x'] ?? 0,
                        $box['y'] ?? 0,
                        $box['w'] ?? 0,
                        $box['h'] ?? 0
                    ),
                ],
            ]
            : null;
        if (is_array($target) && $target['selector'] === null) {
            unset($target['selector']);
        }

        $annotation = new Annotation([
            'id' => $this->buildAnnotationId($requestUri, $index),
            'body' => [
                'type' => 'TextualBody',
                'value' => (string) $result['chars'],
                'format' => 'text/plain',
            ],
            'target' => $target,
        ]);
        return $annotation;
    }

    protected function buildHit(
        SearchHit $hit,
        array $resultIdMap,
        string $requestUri,
        int $index
    ): ?HitAnnotation {
        $targets = [];
        foreach ((array) ($hit['annotations'] ?? []) as $oldId) {
            if (isset($resultIdMap[(string) $oldId])) {
                $targets[] = $resultIdMap[(string) $oldId];
            }
        }
        if (empty($targets)) {
            return null;
        }

        $match = (string) ($hit['match'] ?? '');
        $before = $hit['before'] ?? null;
        $after = $hit['after'] ?? null;

        $body = [];
        if ($before !== null && $before !== '') {
            $body[] = [
                'type' => 'TextualBody',
                'purpose' => 'describing',
                'value' => (string) $before,
                'format' => 'text/plain',
            ];
        }
        if ($match !== '') {
            $body[] = [
                'type' => 'TextualBody',
                'purpose' => 'tagging',
                'value' => $match,
                'format' => 'text/plain',
            ];
        }
        if ($after !== null && $after !== '') {
            $body[] = [
                'type' => 'TextualBody',
                'purpose' => 'describing',
                'value' => (string) $after,
                'format' => 'text/plain',
            ];
        }
        if (empty($body)) {
            return null;
        }

        return new HitAnnotation([
            'id' => $this->buildHitId($requestUri, $index),
            'body' => count($body) === 1 ? $body[0] : $body,
            'target' => count($targets) === 1 ? $targets[0] : $targets,
        ]);
    }

    protected function buildPartOf(string $requestUri, int $total): AnnotationCollection
    {
        $base = strtok($requestUri, '?');
        return new AnnotationCollection([
            'id' => $base,
            'label' => ['en' => ['Search results']],
            'total' => $total,
        ]);
    }

    protected function buildAnnotationId(string $requestUri, int $index): string
    {
        return $this->stripQuery($requestUri) . '/annotation/' . $index;
    }

    protected function buildHitId(string $requestUri, int $index): string
    {
        return $this->stripQuery($requestUri) . '/hit/' . $index;
    }

    protected function stripQuery(string $url): string
    {
        $base = strtok($url, '?');
        return is_string($base) ? $base : $url;
    }
}
