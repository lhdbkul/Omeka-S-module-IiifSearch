<?php declare(strict_types=1);

// /admin/module/configure?id=IiifSearch

namespace IiifSearch\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    protected $elementGroups = [
        'search' => 'Search', // @translate
        'ocr' => 'OCR', // @translate
    ];

    public function init(): void
    {
        $this
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'iiifsearch_versions',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'element_group' => 'search',
                    'label' => 'IIIF Content Search API versions', // @translate
                    'info' => 'Versions of the Search API exposed in IIIF manifests. Endpoints remain available regardless: /search and /search/1 for v1, /search/2 for v2.', // @translate
                    'value_options' => [
                        '1' => 'Search API 1.0', // @translate
                        '2' => 'Search API 2.0', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_versions',
                    'value' => ['1', '2'],
                ],
            ])

            ->add([
                'name' => 'iiifsearch_minimum_query_length',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'search',
                    'label' => 'Minimum query length', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_minimum_query_length',
                    'min' => 1,
                    'value' => 3,
                ],
            ])

            // Motivation describing
            // @see https://iiif.io/api/search/1.0/#query-parameters.
            // Currently, motivations are not managed in common viewers.
            ->add([
                'name' => 'iiifsearch_disable_search_media_values',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'search',
                    'label' => 'Disable search in media values', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_disable_search_media_values',
                ],
            ])

            // The option is the same in module IIIF Server.
            // TODO Make option to match image and xml an option to set in a property of the item.
            ->add([
                'name' => 'iiifsearch_xml_image_match',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'ocr',
                    'label' => 'Match images and xmls when they are multiple', // @translate
                    'value_options' => [
                        'order' => 'Media order (page_001.jpg, alto_001.xml, page_002.jpg, alto_002.xml, …)', // @translate
                        'basename' => 'Media source base filename (page_001.jpg, page_002.jpg, page_002.xml, page_001.xml…)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_xml_image_match',
                    'value' => 'order',
                ],
            ])

            // The option is the same in module IIIF Server.
            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'ocr',
                    'label' => 'Fix bad xml and invalid utf-8 characters', // @translate
                    'value_options' => [
                        'no' => 'No', // @translate
                        'dom' => 'Via DOM (quick)', // @translate
                        'regex' => 'Via regex (slow)', // @translate
                        'all' => 'All', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_xml_fix_mode',
                    'value' => 'no',
                ],
            ])

            ->add([
                'name' => 'iiifsearch_alto_canvas_inject',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'ocr',
                    'label' => 'Inject ALTO seeAlso and annotations on each canvas when a multipage ALTO XML media is attached', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_alto_canvas_inject',
                    'value' => true,
                ],
            ])

            ->add([
                'name' => 'iiifsearch_alto_page_match',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'ocr',
                    'label' => 'Match multipage ALTO Page to canvas', // @translate
                    'value_options' => [
                        'order' => 'Media order (Page n matches the n-th non-ALTO media)', // @translate
                        'physical_img_nr' => 'Page/@PHYSICAL_IMG_NR (1-based)', // @translate
                        'page_id_to_media_name' => 'Page/@ID matches media file basename', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_alto_page_match',
                    'value' => 'order',
                ],
            ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'iiifsearch_versions',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'required' => false,
            ]);
    }
}
