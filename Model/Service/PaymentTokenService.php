<?php
/**
 * Checkout.com Magento 2 Payment module (https://www.checkout.com)
 *
 * Copyright (c) 2017 Checkout.com (https://www.checkout.com)
 * Author: David Fiaty | integration@checkout.com
 *
 * License GNU/GPL V3 https://www.gnu.org/licenses/gpl-3.0.en.html
 */

namespace CheckoutCom\Magento2\Model\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use CheckoutCom\Magento2\Model\Adapter\ChargeAmountAdapter;
use CheckoutCom\Magento2\Gateway\Http\Client;
use CheckoutCom\Magento2\Gateway\Config\Config;
use CheckoutCom\Magento2\Helper\Watchdog;

class PaymentTokenService {

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Watchdog
     */
    protected $watchdog;

    /**
     * @var RemoteAddress
     */
    protected $remoteAddress;

    /**
     * PaymentTokenService constructor.
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        Client $client,
        Config $config,
        Watchdog $watchdog,
        RemoteAddress $remoteAddress
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->storeManager    = $storeManager;
        $this->client          = $client;
        $this->config          = $config;
        $this->watchdog        = $watchdog;
        $this->remoteAddress   = $remoteAddress;
    }

    /**
     * PaymentTokenService Constructor.
     */
    public function getToken() {
        // Prepare the request URL
        $url = $this->config->getApiUrl() . 'tokens/payment';

        // Get the currency code
        $currencyCode = $this->storeManager->getStore()->getCurrentCurrencyCode();

        // Get the quote object
        $quote = $this->checkoutSession->getQuote();

        // Get the quote amount
        $amount = ChargeAmountAdapter::getPaymentFinalCurrencyValue($quote->getGrandTotal());

        if ((float) $amount >= 0 && !empty($amount)) {
            // Prepare the amount 
            $value = ChargeAmountAdapter::getGatewayAmountOfCurrency($amount, $currencyCode);

            // Prepare the transfer data
            $params = [
                'value' => $value,
                'currency' => $currencyCode,
                'trackId' => $quote->reserveOrderId()->save()->getReservedOrderId()
            ];

            // Send the request
            $response = $this->client->post($url, $params);

            // Format the response
            $response = isset($response) ? (array) json_decode($response) : null;

            // Logging
            $this->watchdog->bark($response);

            // Extract the payment token
            if (isset($response['id'])){
                return $response['id'];
            }
        }

        return false;
    }

    public function sendChargeRequest($cardToken, $entity = false, $trackId = false) {
        // Set the request url
        $url = $this->config->getApiUrl() . 'charges/token';

        // Set the request parameters
        $params = [
            'autoCapTime'   => $this->config->getAutoCaptureTimeInHours(),
            'autoCapture'   => $this->config->isAutoCapture() ? 'Y' : 'N',
            'chargeMode'    => $this->config->isVerify3DSecure() ? 2 : 1,
            'attemptN3D'    => filter_var($this->config->isAttemptN3D(), FILTER_VALIDATE_BOOLEAN),
            'cardToken'     => $cardToken
        ];

        // Set the track id if available
        if ($trackId) $params['trackId'] = $trackId;

        // Set the entity (quote or order) params if available
        if ($entity) {
            $params['email'] = $entity->getBillingAddress()->getEmail();
            $params['customerIp'] = $entity->getRemoteIp();
            $params['customerName'] = $entity->getCustomerName();
            $params['value'] = $entity->getGrandTotal()*100;
            $params['currency'] = ChargeAmountAdapter::getPaymentFinalCurrencyCode($entity->getCurrencyCode());
        }
        else {
            $params['email'] = $this->customerSession->getCustomer()->getEmail();
            $params['customerIp'] = $this->remoteAddress->getRemoteAddress();
            $params['value'] = 0;
            $params['currency'] = 'USD';
        }
       
        // Handle the request
        $response = $this->client->post($url, $params);

        // Logging
        $this->watchdog->bark($response);

        // Return the response
        return $response;
    }

    public function verifyToken($paymentToken) {
        // Build the payment token verification URL
        $url = $this->config->getApiUrl() . '/charges/' . $paymentToken;

        // Send the request and get the response
        $response = $this->client->get($url);

        // Logging
        $this->watchdog->bark($response);

        return $response;
    }
}
