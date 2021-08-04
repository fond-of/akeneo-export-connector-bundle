<?php

namespace FondOfAkeneo\Bundle\ExportConnectorBundle\Processor\Normalization;

use Akeneo\Pim\Enrichment\Component\Product\Connector\Processor\Normalization\ProductProcessor as AkeneoProductProcessor;
use Akeneo\Pim\Enrichment\Component\Product\Value\OptionsValue;
use Akeneo\Pim\Enrichment\Component\Product\Value\OptionValue;
use Akeneo\Pim\Enrichment\Component\Product\Value\ScalarValue;
use Akeneo\Pim\Enrichment\Component\Product\ValuesFiller\FillMissingValuesInterface;
use Akeneo\Pim\Structure\Component\Repository\AttributeOptionRepositoryInterface;
use Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface;
use Akeneo\Tool\Component\Connector\Processor\BulkMediaFetcher;
use Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ProductProcessor extends AkeneoProductProcessor
{
    /**
     * @var \Akeneo\Pim\Structure\Component\Repository\AttributeOptionRepositoryInterface|\Akeneo\Pim\Structure\Bundle\Doctrine\ORM\Repository\AttributeOptionRepository $attributeOptionRepository
     */
    protected $attributeOptionRepository;

    /**
     * @var array
     */
    protected $attributeOptions = [];

    /**
     * ProductProcessor constructor.
     *
     * @param \Symfony\Component\Serializer\Normalizer\NormalizerInterface $normalizer
     * @param \Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface $channelRepository
     * @param \Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface $attributeRepository
     * @param \Akeneo\Pim\Structure\Component\Repository\AttributeOptionRepositoryInterface $attributeOptionRepository
     * @param \Akeneo\Tool\Component\Connector\Processor\BulkMediaFetcher $mediaFetcher
     * @param \Akeneo\Pim\Enrichment\Component\Product\ValuesFiller\FillMissingValuesInterface $fillMissingProductModelValues
     */
    public function __construct(
        NormalizerInterface $normalizer,
        IdentifiableObjectRepositoryInterface $channelRepository,
        AttributeRepositoryInterface $attributeRepository,
        AttributeOptionRepositoryInterface $attributeOptionRepository,
        BulkMediaFetcher $mediaFetcher,
        FillMissingValuesInterface $fillMissingProductModelValues
    ) {
        parent::__construct($normalizer, $channelRepository, $attributeRepository, $mediaFetcher, $fillMissingProductModelValues);
        $this->attributeOptionRepository = $attributeOptionRepository;
    }


    /**
     * {@inheritdoc}
     */
    public function process($product)
    {
        $parameters = $this->stepExecution->getJobParameters();
        $structure = $parameters->get('filters')['structure'];
        $channel = $this->channelRepository->findOneByIdentifier($structure['scope']);
        $localeCodes = array_intersect(
            $channel->getLocaleCodes(),
            $parameters->get('filters')['structure']['locales']
        );

        return $this->changeValuesToOptionLabels($product, parent::process($product), $localeCodes);
    }

    /**
     * @param \Akeneo\Pim\Enrichment\Component\Product\Model\Product $product
     * @param $productStandard
     * @param $localeCodes
     *
     * @return array
     */
    protected function changeValuesToOptionLabels($product, $productStandard, $localeCodes)
    {
        foreach ($product->getValues()->getValues() as $attribute) {
            /** @var \Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface $attribute */

            if (!array_key_exists($attribute->getAttributeCode(), $productStandard['values'])) {
                continue;
            }

            $value = $product->getValue($attribute->getAttributeCode());
            $values = [];

            switch (\get_class($attribute)) {
                case OptionValue::class:
                    $values = $this->changeSelectValueToOptionLabel($value, $localeCodes);
                    break;
                case OptionsValue::class:
                    $values = $this->changeMultiSelectValuesToOptionLabels($value, $localeCodes);
                    break;
                default:
                    $values = [];
            }

            if (count($values) < 1) {
                continue;
            }

            $productStandard['values'][$attribute->getAttributeCode()] = $values;
        }

        return $productStandard;
    }

    /**
     * @param $value
     * @param $localeCodes
     *
     * @return array
     */
    protected function changeSelectValueToOptionLabel($value, $localeCodes)
    {
        $values = [];

        if ($value === null || !$value instanceof OptionValue) {
            return $values;
        }

        $valueData = $value->getData();

        if ($valueData === null) {
            return $values;
        }


        $values[0] = [
            'locale' => null,
            'scope' => null,
            'data' => $valueData
        ];


        $options = $this->getAttributeOptionValues($value->getAttributeCode());

        foreach ($localeCodes as $index => $localeCode) {
            if (!isset($options[$value->getData()][$localeCode])) {
                continue;
            }

            $valueData = (string)$options[$value->getData()][$localeCode];

            $values[$index + 1] = [
                'locale' => $localeCode,
                'scope' => null,
                'data' => $valueData
            ];
        }

        return $values;
    }

    /**
     * @param $value
     * @param $localeCodes
     *
     * @return array
     */
    protected function changeMultiSelectValuesToOptionLabels($value, $localeCodes)
    {
        $values = [];

        if ($value === null || !$value instanceof OptionsValue) {
            return $values;
        }

        $valueData = $value->getData();

        if ($valueData === null) {
            return $values;
        }

        $values[0] = [
            'locale' => null,
            'scope' => null,
            'data' => $value->getData()
        ];

        $options = $this->getAttributeOptionValues($value->getAttributeCode());

        foreach ($localeCodes as $index => $localeCode) {
            foreach ($valueData as $valueDataItem) {
                if (!isset($options[$valueDataItem][$localeCode])) {
                    continue;
                }

                $valueString = (string)$options[$valueDataItem][$localeCode];

                if (array_key_exists($index + 1, $values)) {
                    $values[$index + 1]['data'][] = $valueString;
                    continue;
                }

                $values[$index + 1] = [
                    'locale' => $localeCode,
                    'scope' => null,
                    'data' => [
                        $valueString
                    ]
                ];
            }
        }

        return $values;
    }

    /**
     * Get attribute option values
     *
     * @param string $attributeCode
     * @return array
     * @throws \ErrorException
     */
    protected function getAttributeOptionValues(string $attributeCode): array
    {
        /** @var \Akeneo\Pim\Structure\Component\Model\Attribute $attribute */
        if (!isset($this->attributeOptions[$attributeCode])) {
            $attribute = $this->attributeRepository->findOneBy(['code' => $attributeCode]);

            if ($attribute === null) {
                throw new \ErrorException('attribute not found by code: '. $attributeCode);
            }

            /** @var \Doctrine\ORM\PersistentCollection $options */
            $options = $attribute->getOptions();

            foreach ($options as $option) {
                /** @var \Akeneo\Pim\Structure\Component\Model\AttributeOption $option */
                foreach ($option->getOptionValues()->getValues() as $attributeOptionValue) {
                    /** @var \Akeneo\Pim\Structure\Component\Model\AttributeOptionValue $attributeOptionValue */
                    $this->attributeOptions[$attributeCode][$option->getCode()][$attributeOptionValue->getLocale()] = $attributeOptionValue->getValue();
                }
            }
        }

        return $this->attributeOptions[$attributeCode];
    }
}
