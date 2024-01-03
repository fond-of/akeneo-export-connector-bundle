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
use Akeneo\Tool\Component\StorageUtils\Cache\EntityManagerClearerInterface;
use Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface;
use Bynder\Api\BynderClient;
use Bynder\Api\Impl\PermanentTokens\Configuration;
use Induxx\Bundle\CredentialsManagerBundle\Repository\CredentialsRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ProductProcessor extends AkeneoProductProcessor
{
    protected const IMAGE_ATTRIBUTE_CODES = [
        'img_front',
        'img_front_left',
        'img_front_right',
        'img_back',
        'img_back_left',
        'img_back_right',
        'img_right',
        'img_left',
        'img_top',
        'img_set_composing',
        'img_detail_01',
        'img_detail_02',
        'img_detail_03',
        'img_detail_04',
        'img_detail_05',
        'img_detail_06',
        'img_detail_07',
        'img_detail_08',
        'img_detail_09',
        'img_detail_10',
        'img_model_01',
        'img_model_02',
        'img_model_03',
        'img_model_04',
        'img_model_05',
        'img_model_06',
        'img_model_07',
        'img_model_08',
        'img_model_09',
        'img_model_13',
        'img_sole',
        'img_left_inside',
        'img_right_inside',
        'img_left_outside',
        'img_right_outside',
        'img_usp_01',
        'img_usp_02',
        'img_usp_03',
        'img_size_chart'
    ];

    const CACHE_PATH = '/var/www/pim/releases/current/web/public/bynder-cache.json';

    /**
     * @var \Akeneo\Pim\Structure\Component\Repository\AttributeOptionRepositoryInterface|\Akeneo\Pim\Structure\Bundle\Doctrine\ORM\Repository\AttributeOptionRepository $attributeOptionRepository
     */
    protected $attributeOptionRepository;

    /**
     * @var \Induxx\Bundle\CredentialsManagerBundle\Repository\CredentialsRepository
     */
    protected $credentialsRepository;

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
        FillMissingValuesInterface $fillMissingProductModelValues,
        EntityManagerClearerInterface $entityManagerClearer,
        CredentialsRepository $credentialsRepository
    ) {
        parent::__construct($normalizer, $channelRepository, $attributeRepository, $mediaFetcher, $fillMissingProductModelValues);
        $this->attributeOptionRepository = $attributeOptionRepository;
        $this->credentialsRepository = $credentialsRepository;
    }


    /**
     * @param \Akeneo\Pim\Enrichment\Component\Product\Model\Product $product
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

        $productStandard = parent::process($product);

        $productStandard = $this->addBynderMedia($product, $productStandard);

        return $this->changeValuesToOptionLabels($product, $productStandard, $localeCodes);
    }

    /**
     * @param \Akeneo\Pim\Enrichment\Component\Product\Model\Product $product
     * @param $productStandard
     *
     * @return array
     */
    protected function addBynderMedia($product, $productStandard)
    {
        $media = [];
        /** @var \Akeneo\Pim\Enrichment\Component\Product\Model\WriteValueCollection $item */
        foreach ($product->getValues()->getValues() as $attribute) {

            if (!in_array($attribute->getAttributeCode(), static::IMAGE_ATTRIBUTE_CODES)) {
                continue;
            }
            /** @var $mediaValue \Akeneo\Tool\Component\FileStorage\Model\FileInfo */
            $mediaValue = $attribute->getData();
            if ($mediaValue->getKey() === null) {
                continue;
            }
            $media[$mediaValue->getKey()] = $attribute->getAttributeCode();
        }

        if (count($media) === 0) {
            return $productStandard;
        }

        $assetUrls = $this->getBynderDatUrl($media);
        foreach ($assetUrls as $key => $assetUrl) {
            $productStandard['values'][$key][] = [
                'scope' => null,
                'locale' => null,
                'data' => $assetUrl,
            ];
        }

        return $productStandard;
    }

    /**
     * @param $media
     *
     * @return array
     */
    protected function getBynderDatUrl($media)
    {
        $assetUrls = [];
        $assetIds = array_keys($media);
        $cache = json_decode(file_get_contents(static::CACHE_PATH), true);

        foreach ($assetIds as $assetId) {
            if (array_key_exists($assetId, $cache)) {
                $assetUrls[$media[$assetId]] = $cache[$assetId];
                unset($media[$assetId]);
            }
        }

        if (count($media) === 0) {
            return $assetUrls;
        }

        $mediaList = $this->fetchUncachedAssets($media);

        foreach ($mediaList as $item) {
            $name = explode('https://fondof.getbynder.com/transform/', $item['transformBaseUrl'])[1];
            $cache[$item['id']] = $name;
            $assetUrls[$media[$item['id']]] = $name;
        }

        file_put_contents(static::CACHE_PATH, json_encode($cache));

        return $assetUrls;
    }

    /**
     * @param $media
     * @return mixed
     */
    protected function fetchUncachedAssets($media)
    {
        $credential = $this->credentialsRepository->getCredentialFromCode('bynder', 'one_time_key');

        $configuration = new Configuration(
            $credential['host'],
            $credential['token']
        );

        $client = new BynderClient($configuration);
        $assetBankManager = $client->getAssetBankManager();

        $mediaList = $assetBankManager->getMediaList(
            [
                'ids' => join(',', array_keys($media))
            ]
        )->wait();

        return $mediaList;
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
