<?php
declare(strict_types=1);

namespace VueStorefront\CartSync\Controller\Cart;

use VueStorefront\CartSync\Model\Config;
use VueStorefront\CartSync\Service\SyncInterface;
use VueStorefront\CartSync\Service\SyncLoggerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\Oauth\TokenFactory;
use Monolog\Logger;

/**
 * Class Sync
 */
class Sync extends Action
{
    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;
    /**
     * @var CustomerSession
     */
    protected $customerSession;
    /**
     * @var Logger
     */
    protected $logger;
    /**
     * @var TokenFactory
     */
    protected $tokenFactory;
    /**
     * @var SyncInterface
     */
    protected $sync;
    /**
     * @var Config
     */
    protected $config;

    /**
     * Sync constructor.
     *
     * @param Config $config
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerSession $customerSession
     * @param SyncInterface $sync
     * @param SyncLoggerFactory $syncLoggerFactory
     * @param TokenFactory $tokenFactory
     *
     * @throws \Exception
     */
    public function __construct(
        Config $config,
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        CustomerSession $customerSession,
        SyncInterface $sync,
        SyncLoggerFactory $syncLoggerFactory,
        TokenFactory $tokenFactory
    ) {
        parent::__construct($context);
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->tokenFactory = $tokenFactory;
        $this->sync = $sync;
        $this->logger = $syncLoggerFactory->create();
        $this->config = $config;
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        $checkoutPath = $this->config->getCheckoutPath();

        if (!$this->hasRequestAllRequiredParams()) {
            return $this->resultRedirectFactory->create()->setPath($checkoutPath);
        }

        $customerToken = $this->getRequest()->getParam('token');
        $cartId = $this->getRequest()->getParam('cart');

        /** @var Token $token */
        $token = $this->tokenFactory->create()->loadByToken($customerToken);

        if ($this->isGuestCart($token)) {
            if ($this->customerSession->isLoggedIn()) {
                $guestToken = md5(microtime() . mt_rand());

                return $this->logoutCustomer($guestToken, $cartId);
            }

            $this->sync->synchronizeGuestCart($cartId);
        } else {
            $isCustomerLogged = false;

            if ($this->customerSession->isLoggedIn()) {
                $isCustomerLogged = true;

                if ($token->getCustomerId() !== $this->customerSession->getCustomerId()) {
                    return $this->logoutCustomer($customerToken, $cartId);
                }
            }

            if (!$isCustomerLogged) {
                try {
                    $customer = $this->customerRepository->getById($token->getCustomerId());
                } catch (NoSuchEntityException $e) {
                    $this->logger->error($e->getMessage());
                    $this->messageManager->addErrorMessage(__('Required customer doesn\'t exist'));

                    return $this->resultRedirectFactory->create()->setPath($checkoutPath);
                } catch (LocalizedException $e) {
                    $this->logger->error($e->getMessage());
                    $this->messageManager->addErrorMessage(__('Cannot synchronize customer cart'));

                    return $this->resultRedirectFactory->create()->setPath($checkoutPath);
                }

                $this->customerSession->loginById($customer->getId());
            }

            $this->sync->synchronizeCustomerCart($this->customerSession->getCustomerId(), $cartId);
        }

        $params = [];
        if ($this->getRequest()->getParam('paypal')) {
            $params['button'] = 1;
        }
        return $this->resultRedirectFactory->create()->setPath($checkoutPath, $params);
    }

    /**
     * @return bool
     */
    private function hasRequestAllRequiredParams(): bool
    {
        return null !== $this->getRequest()->getParam('token')
            && !empty($this->getRequest()->getParam('cart'));
    }

    /**
     * @param Token $token
     *
     * @return bool
     */
    private function isCustomerCart(Token $token): bool
    {
        return $this->isCustomerToken($token);
    }

    /**
     * @param Token $token
     *
     * @return bool
     */
    private function isGuestCart(Token $token): bool
    {
        return !$this->isCustomerCart($token);
    }

    /**
     * @param Token $token
     *
     * @return bool
     */
    private function isCustomerToken(Token $token): bool
    {
        return $token->getId() && !$token->getRevoked() && $token->getCustomerId();
    }

    /**
     * @param string $customerToken
     * @param string $cartId
     *
     * @return ResponseInterface
     */
    private function logoutCustomer(string $customerToken, string $cartId): ResponseInterface
    {
        $this->customerSession->logout();

        return $this->_redirect(
            'vue/cart/sync',
            [
                'token' => $customerToken,
                'cart' => $cartId,
            ]
        );
    }
}
