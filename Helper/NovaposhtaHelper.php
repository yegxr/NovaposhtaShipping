<?php

namespace Perspective\NovaposhtaShipping\Helper;

use Magento\Catalog\Api\ProductRepositoryInterfaceFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Perspective\NovaposhtaShipping\Api\NovaPoshtaApi2Factory;
use Perspective\NovaposhtaShipping\Model\Carrier\DeliveryDate;
use Perspective\NovaposhtaShipping\Model\Carrier\Method\AbstractChain;
use Perspective\NovaposhtaShipping\Model\Carrier\NovaposhtaApi;
use Perspective\NovaposhtaShipping\Model\Carrier\Sender;
use Perspective\NovaposhtaShipping\Model\Carrier\ServiceType;
use Perspective\NovaposhtaShipping\Model\Carrier\ShippingSales;
use Perspective\NovaposhtaShipping\Model\CounterpartyAddressIndexFactory;
use Perspective\NovaposhtaShipping\Model\ResourceModel\CounterpartyAddressIndex\CollectionFactory;

class NovaposhtaHelper
{
    const PALLETE_THRESHOLD = 85;

    /**
     * @var \Perspective\NovaposhtaShipping\Helper\Config
     */
    protected $config;

    /**
     * @var \Perspective\NovaposhtaCatalog\Api\CityRepositoryInterface
     */
    protected $cityRepository;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterfaceFactory
     */
    protected $productRepositoryInterfaceFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $timezone;

    /**
     * @var \Perspective\NovaposhtaShipping\Helper\BoxpackerFactory
     */
    protected $boxpackerFactory;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var ServiceType
     */
    protected $serviceType;

    /**
     * @var DeliveryDate
     */
    protected $deliveryDate;

    /**
     * @var ShippingSales
     */
    protected $shippingSales;

    /**
     * @var NovaposhtaApi
     */
    protected $novaposhtaApi;

    /**
     * @var Sender
     */
    protected $sender;

    /**
     * @var \Perspective\NovaposhtaShipping\Model\Carrier\Method\AbstractChain
     */
    protected $shippingCartProcessor;

    /**
     * @var array<DataObject>
     */
    protected static $cityShippingPriceAndDateArr;

    /**
     * @var \Perspective\NovaposhtaShipping\Api\NovaPoshtaApi2Factory
     */
    protected NovaPoshtaApi2Factory $novaPoshtaApi2Factory;

    /**
     * @var \Perspective\NovaposhtaShipping\Model\CounterpartyAddressIndexFactory
     */
    protected CounterpartyAddressIndexFactory $counterpartyAddressIndexFactory;

    /**
     * @var \Perspective\NovaposhtaShipping\Model\ResourceModel\CounterpartyAddressIndex\CollectionFactory
     */
    protected CollectionFactory $counterpartyAddressIndexCollectionFactory;

    /**
     * NovaposhtaHelper constructor.
     * @param \Perspective\NovaposhtaShipping\Helper\Config $config
     * @param \Perspective\NovaposhtaShipping\Api\NovaPoshtaApi2Factory $novaPoshtaApi2Factory
     * @param \Perspective\NovaposhtaShipping\Model\CounterpartyAddressIndexFactory $counterpartyAddressIndexFactory
     * @param \Perspective\NovaposhtaShipping\Model\ResourceModel\CounterpartyAddressIndex\CollectionFactory $counterpartyAddressIndexCollectionFactory
     * @param \Magento\Catalog\Api\ProductRepositoryInterfaceFactory $productRepositoryInterfaceFactory
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone
     * @param \Perspective\NovaposhtaShipping\Helper\BoxpackerFactory $boxpackerFactory
     * @param \Magento\Quote\Api\CartRepositoryInterface $cartRepository
     * @param \Perspective\NovaposhtaShipping\Model\Carrier\NovaposhtaApi $novaposhtaApi
     * @param \Perspective\NovaposhtaShipping\Model\Carrier\DeliveryDate $deliveryDate
     * @param \Perspective\NovaposhtaShipping\Model\Carrier\ServiceType $serviceType
     * @param \Perspective\NovaposhtaShipping\Model\Carrier\ShippingSales $shippingSales
     * @param \Perspective\NovaposhtaShipping\Model\Carrier\Sender $sender
     * @param \Perspective\NovaposhtaShipping\Model\Carrier\Method\AbstractChain $shippingCartProcessor
     */
    public function __construct(
        Config $config,
        NovaPoshtaApi2Factory $novaPoshtaApi2Factory,
        CounterpartyAddressIndexFactory $counterpartyAddressIndexFactory,
        CollectionFactory $counterpartyAddressIndexCollectionFactory,
        ProductRepositoryInterfaceFactory $productRepositoryInterfaceFactory,
        TimezoneInterface $timezone,
        BoxpackerFactory $boxpackerFactory,
        CartRepositoryInterface $cartRepository,
        NovaposhtaApi $novaposhtaApi,
        DeliveryDate $deliveryDate,
        ServiceType $serviceType,
        ShippingSales $shippingSales,
        Sender $sender,
        AbstractChain $shippingCartProcessor

    ) {
        $this->config = $config;
        $this->novaPoshtaApi2Factory = $novaPoshtaApi2Factory;
        $this->counterpartyAddressIndexFactory = $counterpartyAddressIndexFactory;
        $this->counterpartyAddressIndexCollectionFactory = $counterpartyAddressIndexCollectionFactory;
        $this->productRepositoryInterfaceFactory = $productRepositoryInterfaceFactory;
        $this->timezone = $timezone;
        $this->boxpackerFactory = $boxpackerFactory;
        $this->cartRepository = $cartRepository;
        $this->serviceType = $serviceType;
        $this->deliveryDate = $deliveryDate;
        $this->shippingSales = $shippingSales;
        $this->novaposhtaApi = $novaposhtaApi;
        $this->sender = $sender;
        $this->shippingCartProcessor = $shippingCartProcessor;
    }

    /**
     * @param array $data
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getShippingPriceByData(array $data)
    {
        $this->prepareSenderCity();
        //Конвертация в объект
        $object = new DataObject($data);
        $quote = $this->cartRepository->get($object->getQuoteId());

        $destinationCityRef = $object->getCurrentUserAddress()->getCity();
        $serviceType = $this->getServiceType($object);

        $items = $quote->getAllItems();
        $totals = $quote->getTotals(); //Total object
        $subtotal = round($totals["subtotal"]->getValue()); //Subtotal value
        $weight = 0;
        foreach ($items as $item) {
            $weight += ($item->getWeight() * $item->getQty());
            $result = $this->markProductWithSpecialPrice($item);
        }
        $optionsSeat = $this->boxpackerFactory->create()->calcSeats($items);
        if (floatval($weight) < static::PALLETE_THRESHOLD) {
            $cargoType = 'Cargo';
        } else {
            $cargoType = 'Pallet';
        }
        $lowerShippingPrice = INF;
        $object->setWeight($weight);
        $object->setSubtotal($subtotal);
        $object->setCargoType($cargoType);
        $object->setOptionsSeat($optionsSeat);

        static::$cityShippingPriceAndDateArr = [];
        foreach ($this->sender->getSenderCityListArray() as $city) {
            $object->setData('sender_city', $city);
            $price = $this->shippingCartProcessor->process($quote, $object);
            $lowerShippingPriceArr = $this->calculateDeliveryDate(
                $city,
                $destinationCityRef,
                $serviceType,
                $price['data'] ?? [['Cost' => INF]],
                $lowerShippingPrice
            );
            static::$cityShippingPriceAndDateArr[] = new DataObject(
                [
                    'city' => $city,
                    'price' => $price ?? [],
                    'date' => array_key_first($lowerShippingPriceArr),
                ]
            );
        }
        $result = $this->getLowestShippingPriceAmongWarehouses($lowerShippingPriceArr, $result);

        $result = $this->calculateShippingSales($result, $cargoType, $subtotal, $optionsSeat);
        return $result;
    }

    /**
     * @param $sender
     * @param $recipient
     * @param $type
     * @return mixed
     */
    public function getDeliveryDate($sender, $recipient, $type)
    {
        return $this->deliveryDate->getDeliveryDate($sender, $recipient, $type);
    }

    /**
     * @param $code
     * @param $key
     * @param null $storeId
     * @return mixed
     */
    public function getStoreConfigByCode($key, $code, $storeId = null)
    {
        return $this->config->getShippingConfigByCode($key, $code, $storeId);
    }

    /**
     * @return \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    public function getTimezone(): TimezoneInterface
    {
        return $this->timezone;
    }

    /**
     * @return void
     */
    private function prepareSenderCity()
    {
        $this->sender->prepareSenderCity();
    }

    /**
     * @return \Perspective\NovaposhtaShipping\Api\NovaPoshtaApi2
     */
    public function getApi()
    {
        return $this->novaposhtaApi->getApi();
    }

    /**
     * @return \Magento\Framework\DataObject[]
     */
    public function getCityShippingPriceAndDateArr(): array
    {
        return static::$cityShippingPriceAndDateArr;
    }

    /**
     * @param array $result
     * @param string $cargoType
     * @param float $subtotal
     * @param $optionsSeat
     * @return array
     */
    private function calculateShippingSales(array $result, string $cargoType, float $subtotal, $optionsSeat): array
    {
        return $this->shippingSales->calculateShippingSales($result, $cargoType, $subtotal, $optionsSeat);
    }

    /**
     * @param $city
     * @param $destinationCityRef
     * @param string $service_type
     * @param $data1
     * @param $lowerShippingPrice
     * @param $datum
     * @return array
     */
    private function calculateDeliveryDate($city, $destinationCityRef, string $service_type, $data1, $lowerShippingPrice): array
    {
        return $this->deliveryDate->calculateDeliveryDate($city, $destinationCityRef, $service_type, $data1, $lowerShippingPrice);
    }

    /**
     * @param array $lowerShippingPriceArr
     * @param array $result
     * @return array
     */
    private function getLowestShippingPriceAmongWarehouses(array $lowerShippingPriceArr, array $result): array
    {
        $comparedLowerPrice = INF;
        $comparedLowerDate = $this->timezone->date()->format('d-m-Y');
        if (isset($lowerShippingPriceArr)) {
            foreach ($lowerShippingPriceArr as $index => $value) {
                if ($comparedLowerPrice > $value) {
                    $comparedLowerPrice = $value;
                    $comparedLowerDate = $index;
                }
            }
            $result['price'] = $comparedLowerPrice;
            $result['date'] = $comparedLowerDate;
        }
        return $result;
    }

    /**
     * @param \Magento\Framework\DataObject $object
     * @return string
     */
    private function getServiceType(DataObject $object): string
    {
        return $this->serviceType->getServiceType($object);
    }

    /**
     * @param $item
     * @return array
     */
    private function markProductWithSpecialPrice($item): array
    {
        $productModel = $this->productRepositoryInterfaceFactory->create()->get($item->getProduct()->getSku());
        $specialprice = $productModel->getSpecialPrice();
        $specialPriceFromDate = $productModel->getSpecialFromDate();
        $specialPriceToDate = $productModel->getSpecialToDate();
        $today = time();
        $result = [];
        if ($specialprice && ($productModel->getPrice() > $productModel->getFinalPrice())) {
            if ($today >= strtotime($specialPriceFromDate) && $today <= strtotime($specialPriceToDate) ||
                $today >= strtotime($specialPriceFromDate) && is_null($specialPriceToDate)) {
                $result['sale'] = [$item->getSku()];
            }
        }
        return $result;
    }

}
