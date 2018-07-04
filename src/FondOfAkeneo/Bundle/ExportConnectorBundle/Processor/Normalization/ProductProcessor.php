<?php

namespace FondOfAkeneo\Bundle\ExportConnectorBundle\Processor\Normalization;

use Pim\Component\Connector\Processor\Normalization\ProductProcessor as AkeneoProductProcessor;

class ProductProcessor extends AkeneoProductProcessor
{
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
     * @param $product
     * @param $productStandard
     * @param $localeCodes
     *
     * @return array
     */
    protected function changeValuesToOptionLabels($product, $productStandard, $localeCodes)
    {
        foreach ($product->getAttributes() as $attribute) {
            $attributeType = $attribute->getType();
            $attributeCode = $attribute->getCode();

            if (!array_key_exists($attributeCode, $productStandard['values'])) {
                continue;
            }

            $value = $product->getValue($attribute->getCode());

            switch ($attributeType) {
                case 'pim_catalog_simpleselect':
                    $values = $this->changeSelectValueToOptionLabel($value, $localeCodes);
                    break;
                case 'pim_catalog_multiselect':
                    $values = $this->changeMultiSelectValuesToOptionLabels($value, $localeCodes);
                    break;
                default:
                    $values = [];
            }


            if (empty($values)) {
                continue;
            }

            $productStandard['values'][$attribute->getCode()] = $values;
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

        if ($value === null) {
            return $values;
        }

        $valueData = $value->getData();

        if ($valueData === null) {
            return $values;
        }

        $values[0] = [
            'locale' => null,
            'scope' => null,
            'data' => $valueData->getCode()
        ];

        foreach ($localeCodes as $index => $localeCode) {
            $optionValue = $valueData->setLocale($localeCode)->getOptionValue();

            if ($optionValue === null) {
                continue;
            }

            $values[$index + 1] = [
                'locale' => $localeCode,
                'scope' => null,
                'data' => $optionValue->getValue()
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

        if ($value === null) {
            return $values;
        }

        $valueData = $value->getData();

        if ($valueData === null) {
            return $values;
        }

        $values[0] = [
            'locale' => null,
            'scope' => null,
            'data' => $value->getOptionCodes()
        ];

        foreach ($localeCodes as $index => $localeCode) {
            foreach ($valueData as $valueDataItem) {
                $optionValue = $valueDataItem->setLocale($localeCode)->getOptionValue();

                if ($optionValue === null) {
                    continue;
                }

                if (array_key_exists($index + 1, $values)) {
                    $values[$index + 1]['data'][] = $optionValue->getValue();
                    continue;
                }

                $values[$index + 1] = [
                    'locale' => $localeCode,
                    'scope' => null,
                    'data' => [
                        $optionValue->getValue()
                    ]
                ];
            }
        }

        return $values;
    }
}
