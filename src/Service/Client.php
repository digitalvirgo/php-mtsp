<?php
namespace DigitalVirgo\MTSP\Service;

use DigitalVirgo\MTSP\Model\ModelAbstractTraitInterface;
use DigitalVirgo\MTSP\Model\Service;
use DigitalVirgo\MTSP\Model\Services;
use DigitalVirgo\MTSP\Model\Subscriptions;
use DigitalVirgo\MTSP\Model\Subscription;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Stream\Stream;

/**
 * Class Client
 * @package DigitalVirgo\MTSP\Service
 *
 * @author Adam Jurek <adam.jurek@digitalvirgo.pl>
 *
 */
class Client extends GuzzleClient {

//    const API_URL = 'http://mtserviceproxy.services.avantis.pl/';
    const API_URL = 'http://beta2:9080/mtsp/';

    /**
     * @var Client
     */
    private static $_instance = null;

    /**
     * @var string
     */
    protected $_username;

    /**
     * @var string
     */
    protected $_password;

    /**
     * Get new instance of client
     *
     * @param string $baseUrl api base url
     * @return Client
     */
    public static function getInstance()
    {
        if (null === static::$_instance) {
            static::$_instance = new static(array(
                'base_url' => self::API_URL,
            ));
        }
        return static::$_instance;
    }

    /**
     * @param $login
     * @param $password
     * @return $this
     */
    public function setAuth($username, $password)
    {
        $this->_username = $username;
        $this->_password = $password;

        $this->_configureAuth();

        return $this;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->_username;
    }

    /**
     * @param string $username
     * @return Client
     */
    public function setUsername($username)
    {
        $this->_username = $username;
        $this->_configureAuth();
        return $this;
    }


    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->_password;
    }

    /**
     * @param string $password
     * @return Client
     */
    public function setPassword($password)
    {
        $this->_password = $password;
        $this->_configureAuth();
        return $this;
    }

    /**
     * Setup basic auth
     *
     * @return $this
     */
    protected function _configureAuth()
    {
        if ($this->_username && $this->_password) {
            $this->setDefaultOption('auth', [$this->_username, $this->_password]);
        }

        return $this;
    }

    /**
     * Send http request
     *
     * @param string $url Request path
     * @param string $method Http method
     * @param mixed $payload Data to send with request
     * @return string Body string response
     */
    protected function _request($url, $method = 'GET', $payload = null) {

        $options = [];

        switch ($method) {
            case 'GET':
                if (is_array($payload)) {
                    $options['query'] = [$payload];
                } else {
                    $options['query'] = $payload;
                }

                break;
            case 'POST':
            case 'PUT':
                if ($payload instanceof ModelAbstractTraitInterface) {
                    $options['body'] = Stream::factory($payload->toXml());
                    $options['headers']['Content-type'] = 'application/xml';
                } else {
                    $options['body'] = Stream::factory($payload);
                }
                break;
        }

        try {
            $response = $this->send(
                $this->createRequest($method, $url, $options)
            );
        } catch (ClientException $e) {
            throw new \Exception((string)$e->getResponse()->getBody());
        }

        /** @var \GuzzleHttp\Stream\Stream $body */
        $body = $response->getBody();

        return (string) $body;
    }

    /**
     * Get Services in xml format
     * @param bool $raw return raw xml output
     * @return Services|string
     */
    public function getServicesNames($raw = false) {

        $response = $this->_request("services");

        if ($raw) {
            return $response;
        }

        return (new Services())->fromXml($response);
    }

    /**
     * @param $serviceName Service name
     * @param bool $raw return raw xml output
     * @return Service
     */
    public function getService($serviceName, $raw = false) {

        $response = $this->_request("services/{$serviceName}");

        if ($raw) {
            return $response;
        }

        return (new Service())->fromXml($response);
    }

    /**
     * @param string $serviceName Service name
     * @param null|string|\DateTime $from Optional date from filter
     * @param null|string|\DateTime $to Optional date to filter
     * @param bool $raw return raw xml output
     * @return Subscriptions
     */
    public function getSubscriptions($serviceName, $from = null, $to = null, $raw = false) {

        if ($from xor $to) {
            throw new \Exception('Both dates are required');
        }

        $payload = [];

        if ($from !== null) {
            if (is_string($from)) {
                $from = new \DateTime($from);
            }

            $payload['fromDate'] = $from->format('c');
        }

        if ($to !== null) {
            if (is_string($to)) {
                $to = new \DateTime($to);
            }

            $payload['toDate'] = $to->format('c');
        }

        $response = $this->_request("services/{$serviceName}/subscriptions", "GET", $payload);

        if ($raw) {
            return $response;
        }

        return (new Subscriptions())->fromXml($response);
    }

    /**
     * @param $serviceName
     * @param $subscriptionId
     * @param bool $raw return raw xml output
     * @return Subscription
     */
    public function getSubscription($serviceName, $subscriptionId, $raw = false) {
        $response = $this->_request("services/{$serviceName}/subscriptions/{$subscriptionId}");

        if ($raw) {
            return $response;
        }

        return (new Subscription())->fromXml($response);
    }

    public function getBilledNumbers($serviceName, $subscriptionId, $id = null, $raw = false) {
        $payload = [];

        if ($id) {
            $payload['id'] = $id;
        }

        return $this->_request("services/{$serviceName}/subscriptions/{$subscriptionId}/billing", "GET", $payload);
    }

    public function getSubscribers($serviceName, $operator = null, $raw = false) {

        return $this->_request("services/{$serviceName}/subscribers/{$operator}");
    }

    /**
     * Creating new subscription
     * @param Subscription|array $subscription Subscription data
     * @param bool $raw return raw xml response
     * @return Subscription|string
     * @throws \Exception
     */
    public function addSubscription($subscription, $raw = false) {
        if (is_array($subscription)) {
            $subscription = new Subscription($subscription);
        }

        $serviceName = $subscription->getServiceName();

        if (!$subscription->getServiceName()) {
            throw new \Exception('Missing serviceName in subscription');
        }

        // clean no updateable data
        $subscription->cleanBeforeSave();

        $response = $this->_request("services/{$serviceName}/subscriptions", "POST", $subscription);

        //update existing subscription
        $subscription->fromXml($response);

        if ($raw) {
            return $response;
        }

        return $subscription;

    }

    /**
     * Update Subscription
     * @param Subscription|array $subscription Subscription data
     * @param bool $raw
     * @return Subscription|string
     * @throws \Exception
     */
    public function updateSubscription($subscription, $raw = false) {
        if (is_array($subscription)) {
            $subscription = new Subscription($subscription);
        }

        $serviceName = $subscription->getServiceName();

        if (!$subscription->getServiceName()) {
            throw new \Exception('Missing serviceName in subscription');
        }

        // clean no updateable data
        $subscription->cleanBeforeSave();

        $response = $this->_request("services/{$serviceName}/subscriptions", "PUT", $subscription);

        //update existing subscription
        $subscription->fromXml($response);



        if ($raw) {
            return $response;
        }

        return $subscription;

    }


}