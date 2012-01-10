<?php
namespace Kitpages\ShopBundle\Model\Cart;

use Kitpages\ShopBundle\Entity\Order;
use Kitpages\ShopBundle\Entity\OrderHistory;
use Kitpages\ShopBundle\Entity\OrderLine;
use Kitpages\ShopBundle\Model\Cart\CartInterface;

use Kitano\PaymentBundle\Event\PaymentEvent;
use Kitano\PaymentBundle\Model\Transaction;

use Symfony\Component\HttpFoundation\Session;
use Symfony\Bundle\DoctrineBundle\Registry;
use Symfony\Component\HttpKernel\Log\LoggerInterface;

class OrderManager
{
    protected $isCartIncludingVat = null;
    protected $cartManager = null;
    protected $doctrine = null;
    /** @var null|LoggerInterface */
    protected $logger = null;

    public function __construct(
        Registry $doctrine,
        LoggerInterface $logger,
        CartManagerInterface $cartManager,
        $isCartIncludingVat
    )
    {
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->cartManager = $cartManager;
        $this->isCartIncludingVat = $isCartIncludingVat;
    }


    /**
     * @param string username ($this->get('security.context')->getToken()->getUsername();)
     * @param OrderUser|null $invoiceUser
     * @param OrderUser|null $shippingUser
     * @return Order $order
     */
    public function createOrder(
        $username = null,
        OrderUser $invoiceUser = null,
        OrderUser $shippingUser = null

    )
    {
        $this->logger->debug("in create order");
        $cart = $this->cartManager->getCart();
        $price = $this->cartManager->getTotalPrice();

        // create order
        $order = new Order();
        $this->setOrderPrice($order, $price);
        $order->setRandomKey($this->getNewRandomKey());

        // create first orderHistory
        $orderHistory = new OrderHistory();
        $orderHistory->setUsername($username);
        $orderHistory->setOrder($order);
        $orderHistory->setState(OrderHistory::STATE_CREATED);
        $orderHistory->setStateDate(new \DateTime());
        $orderHistory->setPriceIncludingVat($order->getPriceIncludingVat());
        $orderHistory->setPriceWithoutVat($order->getPriceWithoutVat());
        $order->addOrderHistory($orderHistory);
        $order->setStateFromHistory();

        // create lines
        $lineList = $cart->getLineList();
        foreach ($lineList as $line)
        {
            $orderLine = new OrderLine();
            $orderLine->setOrder($order);
            $orderLine->setCartLineId($line->getId());
            $orderLine->setCartParentLineId($line->getParentLineId());
            $orderLine->setQuantity($line->getQuantity());
            $orderLine->setShopName($line->getCartable()->getShopName());
            $orderLine->setShopDescription($line->getCartable()->getShopDescription());
            $orderLine->setShopData($line->getCartable()->getShopData());
            $orderLine->setShopReference($line->getCartable()->getShopReference());
            if ($this->isCartIncludingVat) {
                $orderLine->setPriceIncludingVat($this->cartManager->getLinePrice($line->getId()));
            } else {
                $orderLine->setPriceWithoutVat($this->cartManager->getLinePrice($line->getId()));
            }
            $order->addOrderLine($orderLine);
        }

        // add users
        if (!is_null($invoiceUser)) {
            $order->setInvoiceUser($invoiceUser);
        }
        if (!is_null($shippingUser)) {
            $order->setShippingUser($shippingUser);
        }

        return $order;
    }

    protected function getNewRandomKey() {
        $em = $this->doctrine->getEntityManager();
        $repo = $em->getRepository("KitpagesShopBundle:Order");
        $keyExists = true;
        while ($keyExists == true ) {
            $key = uniqid("order-", true);
            $order = $repo->findOneBy(array('randomKey' => $key));
            if ($order == null) {
                $keyExists = false;
            }
        }
        return $key;
    }

    protected function setOrderPrice(Order $order, $price)
    {
        if ($this->isCartIncludingVat) {
            $order->setPriceIncludingVat($price);
        }
        else {
            $order->setPriceWithoutVat($price);
        }
    }

    public function paymentListener(PaymentEvent $event)
    {
        $transaction = $event->getTransaction();
        if ($transaction == null) {
            return;
        }
        // check transaction success
        if ($transaction->getSuccess() === false) {
            throw new Exception("transaction failed, transactionId=".$transaction->getId());
        }
        // get order
        $em = $this->doctrine->getEntityManager();
        $repo = $em->getRepository("KitpagesShopBundle:Order");
        $order = $repo->find($transaction->getOrderId());
        if (! $order instanceof Order) {
            throw new Exception("unknown order for transactionId=".$transaction->getId());
        }
        if ($order->getState() != OrderHistory::STATE_READY_TO_PAY) {
            $this->logger->info("orderId=".$order->getId()." not updated by payment process because state is not ready_to_pay");
            return;
        }
        // transaction status ok
        if ($transaction->getState() == Transaction::STATE_APPROVED) {
            // update order
            $orderHistory = new OrderHistory();
            $orderHistory->setUsername("payment-notification");
            $orderHistory->setOrder($order);
            $orderHistory->setState(OrderHistory::STATE_PAYED);
            $orderHistory->setStateDate(new \DateTime());
            $orderHistory->setPriceIncludingVat($order->getPriceIncludingVat());
            $orderHistory->setPriceWithoutVat($order->getPriceWithoutVat());
            $orderHistory->setNote("Transaction accepted by the bank, transactionId=".$transaction->getId());
            $order->addOrderHistory($orderHistory);
            $order->setStateFromHistory();
            $em->flush();
            // empty cart
            $this->cartManager->getCart()->emptyCart();
            // TODO : generate invoice

            return;
        }

        $orderHistory = new OrderHistory();
        $orderHistory->setUsername("payment-notification");
        $orderHistory->setOrder($order);
        $orderHistory->setState(OrderHistory::STATE_READY_TO_PAY);
        $orderHistory->setStateDate(new \DateTime());
        $orderHistory->setPriceIncludingVat($order->getPriceIncludingVat());
        $orderHistory->setPriceWithoutVat($order->getPriceWithoutVat());
        $orderHistory->setNote("Payment refused by the bank, transactionId=".$transaction->getId().", transactionState=".$transaction->getState());
        $order->addOrderHistory($orderHistory);
        $order->setStateFromHistory();
        $em->flush();
    }
}
