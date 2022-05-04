<?php

declare(strict_types=1);

namespace App\Model\Order;

use App\Model\BaseService;
use App\Model\Order\Events\OrderUpdatedEvent;
use App\Model\Order\Item\Item;
use App\Model\Order\Voucher\Voucher;
use App\Modules\Front\Components\Cart\Cart;
use Nette\Application\LinkGenerator;
use Nette\Utils\DateTime;
use Nettrine\ORM\EntityManagerDecorator;
use Pixidos\GPWebPay\Data\Operation;
use Pixidos\GPWebPay\Data\OperationInterface;
use Pixidos\GPWebPay\Param\Amount;
use Pixidos\GPWebPay\Param\Currency;
use Pixidos\GPWebPay\Param\OrderNumber;
use Pixidos\GPWebPay\Param\ResponseUrl;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderService extends BaseService
{
    public function __construct(EntityManagerDecorator $entityManager, private EventDispatcherInterface $eventDispatcher, private LinkGenerator $linkGenerator)
    {
        parent::__construct($entityManager);
    }

    public function placeOrder(Cart $cart): Order
    {
        $cartObject = $cart->init(true);
        $cart->calculateStatistics(true);

        $statistics = $cart->statistics;

        $order = new Order();
        $order->setLocale($this->locale);
        $order->setIndex($this->generateIndex());
        $order->setCreatedAt(new DateTime);

        $order->generateSecureHash();

        $order->setCustomer($cart->customer);

        $this->em->persist($order);

        $order->setDeliveryMethod($cartObject->deliveryMethod);
        $order->setPaymentMethod($cartObject->paymentMethod);

        $this->saveAddresses($order, $cartObject);

        $order->hydrateFromCart($cartObject);

        $order->productsPrice = $statistics->productsPrice;
        $order->totalDiscount = $statistics->totalDiscount;

        $order->deliveryPrice = $order->totalDeliveryPrice = $statistics->deliveryPrice;

        if ($order->assembly) {
            $order->assemblyPrice = $statistics->assemblyPrice;
            $order->totalDeliveryPrice += $order->assemblyPrice;
        }

        if ($order->fullDelivery) {
            $order->fullDeliveryPrice = $statistics->fullDeliveryPrice;
            $order->totalDeliveryPrice += $order->fullDeliveryPrice;
        }

        $order->totalPrice = $statistics->totalPrice;
        $order->deposit = $statistics->deposit;

        foreach ($statistics->rules as $cartRule) {
            $voucher = new Voucher();
            $voucher->order = $order;

            $this->em->persist($voucher);

            $voucher->cartRule = $cartRule->rule;
            $voucher->discount = $cartRule->discount;

            $order->getVouchers()->add($voucher);

            $cartRule->rule->increaseUseCounter();
        }

        foreach ($cart->getItems() as $cartItem) {
            $item = new Item();
            $item->order = $order;

            $this->em->persist($item);

            $item->product = $cartItem->product;
            $item->variant = $cartItem->variant;
            $item->quantity = $cartItem->quantity;

            $item->surfaceFinish = $cartItem->surfaceFinish;
            $item->cloth = $cartItem->cloth;
            $item->glass = $cartItem->glass;
            $item->weightCategory = $cartItem->weightCategory;

            $item->isGift = $cartItem->isGift;

            $item->singlePrice = $item->isGift ? 0 : $cartItem->getPrice($this->locale->currency);
            $item->totalPrice = $item->quantity * $item->singlePrice;

            $order->getItems()->add($item);
        }

        $this->em->flush();

        $this->updateOrder($order->getId());

        return $order;
    }

    public function updateOrder(int $order): void
    {
        $this->eventDispatcher->dispatch(
            new OrderUpdatedEvent($this->getOrder($order))
        );
    }

    public function saveAddresses(Order $order, \App\Model\Cart\Cart $cartObject): void
    {
        if ($order->customer) {
            $order->hydrateAddressFromCart($cartObject);

            if ($cartObject->deliveryMethod->code !== \App\Model\Cart\Cart::PERSONAL_PICKAP && $cartObject->deliveryAddress) {
                $order->hydrateDeliveryAddressFromCart($cartObject);
            }
        }
    }

    public function generateIndex(): string
    {
        $index = $this->em->getRepository(Order::class)
            ->createQueryBuilder('r')
            ->select('MAX(r.index)')
            ->where('r.createdAt >= :month')
            ->setParameter('month', (new \DateTime('first day of this month'))->setTime(0, 0, 0))
            ->getQuery()->getSingleScalarResult() ?: 0;

        $number = (int)substr((string)$index, -3) + 1;

        return date('ym') . (sprintf('%03d', $number));
    }

    public function getByIndex(string $index): ?Order
    {
        $this->em->clear(Order::class);

        return $this->em->createQueryBuilder()->select('o')
            ->from(Order::class, 'o')
            ->where("o.index = :identifier")
            ->setParameter('identifier', $index)
            ->leftJoin('o.status', 'status')->addSelect('status')
            ->leftJoin('status.translates', 'statusTranslates', 'WITH', 'statusTranslates.locale = o.locale')
            ->addSelect('statusTranslates')
            ->leftJoin('o.deliveryMethod', 'deliveryMethod')->addSelect('deliveryMethod')
            ->leftJoin('o.paymentMethod', 'paymentMethod')->addSelect('paymentMethod')
            ->leftJoin('deliveryMethod.translates', 'deliveryMethodTranslates', 'WITH', 'deliveryMethodTranslates.locale = o.locale')
            ->addSelect('deliveryMethodTranslates')
            ->leftJoin('paymentMethod.translates', 'paymentMethodTranslates', 'WITH', 'paymentMethodTranslates.locale = o.locale')
            ->addSelect('paymentMethodTranslates')
            ->leftJoin('o.items', 'i')->addSelect('i')
            ->innerJoin('i.variant', 'variant', 'WITH', 'variant.removed = false')->addSelect('variant')
            ->innerJoin('i.product', 'product', 'WITH', 'product.removed = false')->addSelect('product')
            ->leftJoin('product.category', 'category')->addSelect('category')
            ->leftJoin('category.translates', 'categoryT', 'WITH', 'categoryT.locale = o.locale')->addSelect('categoryT')
            ->leftJoin('variant.images', 'images')->addSelect('images')
            ->leftJoin('images.image', 'image')->addSelect('image')
            ->leftJoin('product.translates', 'translates', 'WITH', 'translates.locale = o.locale')->addSelect('translates')
            ->leftJoin('variant.parameters', 'parameters')->addSelect('parameters')
            ->leftJoin('parameters.parameter', 'parameter')->addSelect('parameter')
            ->leftJoin('parameters.value', 'value')->addSelect('value')
            ->leftJoin('parameter.translates', 'parameterTranslates', 'WITH', 'parameterTranslates.locale = o.locale')->addSelect('parameterTranslates')
            ->leftJoin('value.translates', 'valueTranslates', 'WITH', 'valueTranslates.locale = o.locale')->addSelect('valueTranslates')
            ->leftJoin('i.surfaceFinish', 'surfaceFinish')->addSelect('surfaceFinish')
            ->leftJoin('surfaceFinish.translates', 'surfaceFinishTranslates', 'WITH', 'surfaceFinishTranslates.locale = o.locale')->addSelect('surfaceFinishTranslates')
            ->leftJoin('i.cloth', 'cloth')->addSelect('cloth')
            ->leftJoin('cloth.translates', 'clothTranslates', 'WITH', 'clothTranslates.locale = o.locale')->addSelect('clothTranslates')
            ->leftJoin('i.glass', 'glass')->addSelect('glass')
            ->leftJoin('glass.translates', 'glassTranslates', 'WITH', 'glassTranslates.locale = o.locale')->addSelect('glassTranslates')
            ->leftJoin('i.weightCategory', 'weightCategory')->addSelect('weightCategory')
            ->leftJoin('weightCategory.translates', 'weightCategoryTranslates', 'WITH', 'weightCategoryTranslates.locale = o.locale')->addSelect('weightCategoryTranslates')
            ->leftJoin('variant.availability', 'availability')->addSelect('availability')
            ->leftJoin('availability.translates', 'availabilityTranslates', 'WITH', 'availabilityTranslates.locale = o.locale')->addSelect('availabilityTranslates')
            ->addOrderBy('i.id', 'ASC')
            ->addOrderBy('images.order', 'ASC')
            ->getQuery()->getOneOrNullResult();
    }

    public function getOnlineOperation(Order $order): OperationInterface
    {
        $amount = $order->deposit ?: $order->totalPrice;

        return new Operation(
            new OrderNumber(time()),
            new Amount($amount),
            new Currency($order->locale->currency->getGPWebPayIdentifier()),
            null,
            new ResponseUrl(
                $this->linkGenerator->link('Front:Order:payment', [
                    'index' => $order->index,
                    'hash' => $order->hash,
                    'locale' => $order->locale->icu,
                ])
            )
        );
    }
}
