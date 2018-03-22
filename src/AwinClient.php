<?php

namespace whitelabeled\AwinApi;

use DateTime;
use Httpful\Request;

class AwinClient {
    /**
     * @var boolean Whether to request commission groups
     */
    public $verboseCommissionGroups = false;

    /**
     * @var string
     */
    private $authToken;

    /**
     * @var integer
     */
    private $publisherId;

    /**
     * @var string Endpoint for Awin API
     */
    protected $endpoint = 'https://api.awin.com';

    /**
     * @var integer request timeout in seconds (default = 10s)
     */
    public $timeout = 10;

    /**
     * @var integer api limit per minute (default = 20 calls per minute)
     * API calls are limited: http://wiki.awin.com/index.php/Publisher_API#Limitation.2FThrottling
     */
    public $apiCallsLimit = 20;

    private $apiCallsCount = 0;

    /**
     * @var CommissionGroup[] Commission groups
     */
    protected $commissionGroups = [];

    /**
     * DaisyconClient constructor.
     * @param $authToken   string Awin auth token
     * @param $publisherId integer Awin Publisher ID
     */
    public function __construct($authToken, $publisherId) {
        $this->authToken = $authToken;
        $this->publisherId = $publisherId;
    }

    private function throttleApiCalls() {
        if ($this->apiCallsCount < $this->apiCallsLimit) {
            $this->apiCallsCount ++;
            return;
        }

        sleep(60);
        $this->apiCallsCount = 1;
    }

    /**
     * Get all transactions from $startDate until $endDate.
     *
     * @param DateTime $startDate Start date
     * @param DateTime $endDate   End date
     * @param string   $timezone  Awin timezone format, see http://wiki.awin.com/index.php/API_get_transactions_list
     * @return array Transaction objects. Each part of a transaction is returned as a separate Transaction.
     */
    public function getTransactions(DateTime $startDate, DateTime $endDate, $timezone = 'Europe/Paris') {
        $params = [
            'startDate' => $startDate->format('Y-m-d\TH:i:s'),
            'endDate'   => $endDate->format('Y-m-d\TH:i:s'),
            'timezone'  => $timezone
        ];

        $query = '?' . http_build_query($params);
        $response = $this->makeRequest("/publishers/{$this->publisherId}/transactions/", $query);

        $transactions = [];
        $transactionsData = $response->body;

        if ($transactionsData != null) {
            foreach ($transactionsData as $transactionData) {
                $transaction = Transaction::createFromJson($transactionData);

                if ($this->verboseCommissionGroups == true) {
                    // Search commission groups for this transaction
                    foreach ($transaction->transactionParts as $transactionPart) {
                        $transactionPart->commissionGroup = $this->findCommissionGroup($transactionPart->commissionGroupId, $transaction->advertiserId);
                    }
                }

                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }


    /**
     * @param $advertiserId integer Advertiser ID
     * @return CommissionGroup[]
     */
    public function getCommissionGroups($advertiserId) {
        $params = [
            'advertiserId' => $advertiserId,
        ];

        $query = '?' . http_build_query($params);
        $response = $this->makeRequest("/publishers/{$this->publisherId}/commissiongroups/", $query);

        $commissionGroups = [];
        $cgData = $response->body;

        if ($cgData != null) {
            foreach ($cgData->commissionGroups as $commissionGroupData) {
                $commissionGroup = CommissionGroup::createFromJson((array)$commissionGroupData, $cgData->advertiser);

                $commissionGroups[] = $commissionGroup;
            }
        }

        return $commissionGroups;
    }

    protected function makeRequest($resource, $query = "") {

        if ($this->apiCallsLimit) {
            $this->throttleApiCalls();
        }

        $uri = $this->endpoint . $resource;

        $request = Request::get($uri . $query)
            ->addHeader('Authorization', 'Bearer ' . $this->authToken)
            ->timeout($this->timeout)
            ->expectsJson();

        $response = $request->send();

        // Check for errors
        if ($response->hasErrors()) {
            if (isset($response->body->description)) {
                throw new \Exception('API Error: ' . $response->body->description);
            } else {
                throw new \Exception('Invalid data');
            }
        }

        return $response;
    }

    /**
     * @param $commissionGroupID integer
     * @param $advertiserId      integer
     * @return null|CommissionGroup
     */
    private function findCommissionGroup($commissionGroupID, $advertiserId) {
        if (empty($commissionGroupID)) {
            return null;
        }

        if (isset($this->commissionGroups[$commissionGroupID])) {
            return $this->commissionGroups[$commissionGroupID];
        }

        // Request commission groups:
        try {
            $commissionGroups = $this->getCommissionGroups($advertiserId);
        }
        catch (\Exception $e) {
            return null;
        }

        foreach ($commissionGroups as $commissionGroup) {
            $this->commissionGroups[$commissionGroup->id] = $commissionGroup;
        }

        if (isset($this->commissionGroups[$commissionGroupID])) {
            return $this->commissionGroups[$commissionGroupID];
        } else {
            return null;
        }
    }
}
