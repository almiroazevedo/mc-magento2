<?php
/**
 * mc-magento2 Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento2
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 5/15/17 11:02 AM
 * @file: Subscriber.php
 */
namespace Ebizmarts\MailChimp\Model\Api;

class Subscriber
{
    const BATCH_LIMIT = 100;
    /**
     * @var \Ebizmarts\MailChimp\Helper\Data
     */
    protected $_helper;
    /**
     * @var \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory
     */
    protected $_subscriberCollection;
    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;
    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $_message;
    /**
     * @var \Magento\Newsletter\Model\SubscriberFactory
     */
    protected $_subscriberFactory;
    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $_customerRepo;
    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $_customerFactory;
    protected $_countryInformation;

    /**
     * Subscriber constructor.
     * @param \Ebizmarts\MailChimp\Helper\Data $helper
     * @param \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subscriberCollection
     * @param \Magento\Newsletter\Model\SubscriberFactory $subscriberFactory
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepo
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Framework\Message\ManagerInterface $message
     */
    public function __construct(
        \Ebizmarts\MailChimp\Helper\Data $helper,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subscriberCollection,
        \Magento\Newsletter\Model\SubscriberFactory $subscriberFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepo,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Framework\Message\ManagerInterface $message
    )
    {
        $this->_helper                  = $helper;
        $this->_subscriberCollection    = $subscriberCollection;
        $this->_date                    = $date;
        $this->_message                 = $message;
        $this->_subscriberFactory       = $subscriberFactory;
        $this->_customerRepo            = $customerRepo;
        $this->_customerFactory         = $customerFactory;
        $this->_countryInformation      = $countryInformation;
    }

    public function sendSubscribers($storeId, $listId)
    {
        //get subscribers
//        $listId = $this->_helper->getGeneralList($storeId);
        $collection = $this->_subscriberCollection->create();
        $collection->addFieldToFilter('subscriber_status', array('eq' => 1))
            ->addFieldToFilter('store_id', array('eq' => $storeId));
        $collection->getSelect()->joinLeft(
            ['m4m' => $this->_helper->getTableName('mailchimp_sync_ecommerce')],
            "m4m.related_id = main_table.subscriber_id and m4m.type = '".\Ebizmarts\MailChimp\Helper\Data::IS_SUBSCRIBER.
            "' and m4m.mailchimp_store_id = '".$listId."'",
            ['m4m.*']
        );
        $collection->getSelect()->where("m4m.mailchimp_sync_delta IS null ".
            "OR (m4m.mailchimp_sync_delta > '".$this->_helper->getMCMinSyncDateFlag().
            "' and m4m.mailchimp_sync_modified = 1)");
        $collection->getSelect()->limit(self::BATCH_LIMIT);
        $subscriberArray = array();
        $date = $this->_helper->getDateMicrotime();
        $batchId = \Ebizmarts\MailChimp\Helper\Data::IS_SUBSCRIBER . '_' . $date;
        $counter = 0;
        /**
         * @var $subscriber \Magento\Newsletter\Model\Subscriber
         */
        foreach ($collection as $subscriber) {
            $data = $this->_buildSubscriberData($subscriber);
            $md5HashEmail = md5(strtolower($subscriber->getSubscriberEmail()));
            $subscriberJson = "";
            //enconde to JSON
            try {
                $subscriberJson = json_encode($data);
            } catch (\Exception $e) {
                //json encode failed
                $errorMessage = "Subscriber ".$subscriber->getSubscriberId()." json encode failed";
                $this->_helper->log($errorMessage, $storeId);
            }
            if (!empty($subscriberJson)) {
                $subscriberArray[$counter]['method'] = "PUT";
                $subscriberArray[$counter]['path'] = "/lists/" . $listId . "/members/" . $md5HashEmail;
                $subscriberArray[$counter]['operation_id'] = $batchId . '_' . $subscriber->getSubscriberId();
                $subscriberArray[$counter]['body'] = $subscriberJson;
                //update subscribers delta
                $this->_updateSubscriber($listId, $subscriber->getId(), $this->_date->gmtDate(), '', 0);
            }
            $counter++;
        }
        return $subscriberArray;
    }

    protected function _buildSubscriberData(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        $storeId = $subscriber->getStoreId();
        $data = array();
        $data["email_address"] = $subscriber->getSubscriberEmail();
        $mergeVars = $this->getMergeVars($subscriber);
        if ($mergeVars) {
            $data["merge_fields"] = $mergeVars;
        }
        $data["status_if_new"] = $this->_getMCStatus($subscriber->getStatus(), $storeId);
        return $data;
    }

    /**
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @return array
     */
    public function getMergeVars(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        $mergeVars = [];
        $storeId = $subscriber->getStoreId();
        $mapFields = $this->_helper->getMapFields($storeId);
        $webSiteId = $this->_helper->getWebsiteId($subscriber->getStoreId());
        try {
            /**
             * @var $customer \Magento\Customer\Model\Customer
             */
            $customer = $this->_customerFactory->create();
            $customer->setWebsiteId($webSiteId);
            $customer->loadByEmail($subscriber->getEmail());
            if($customer->getData('email')==$subscriber->getEmail()) {
                foreach ($mapFields as $map) {
                    $value = $customer->getData($map['customer_field']);
                    if($value) {
                        if ($map['isDate']) {
                            $format = $this->_helper->getDateFormat($storeId);
                            if($map['customer_field']=='dob') {
                                $format = substr($format,0,3);
                            }
                            $value = date($format, strtotime($value));
                        } elseif($map['isAddress']) {
                            $value = $this->_getAddressValues($customer->getPrimaryAddress($map['customer_field']));
                        } elseif(count($map['options'])) {
                            foreach($map['options'] as $option) {
                                if($option['value']==$value) {
                                    $value = $option['label'];
                                    break;
                                }
                            }
                        }
                        $mergeVars[$map['mailchimp']] = $value;
                    }
                }
            }
        } catch(\Exception $e) {
            $this->_helper->log($e->getMessage());
        }
        return (!empty($mergeVars)) ? $mergeVars : null;
    }

    /**
     * @param \Magento\Customer\Model\Address\AbstractAddress $value
     * @return array
     */
    private function _getAddressValues(\Magento\Customer\Model\Address\AbstractAddress $address)
    {
        $addressData = array();
        if ($address) {
            $street = $address->getStreet();
            if (count($street) > 1) {
                $addressData["addr1"] = $street[0];
                $addressData["addr2"] = $street[1];
            } else {
                if (!empty($street[0])) {
                    $addressData["addr1"] = $street[0];
                }
            }
            if ($address->getCity()) {
                $addressData["city"] = $address->getCity();
            }
            if ($address->getRegion()) {
                $addressData["state"] = $address->getRegion();
            }
            if ($address->getPostcode()) {
                $addressData["zip"] = $address->getPostcode();
            }
            if ($address->getCountry()) {
                $country = $this->_countryInformation->getCountryInfo($address->getCountryId());
                $addressData["country"] = $country->getFullNameLocale();
            }
        }
        return $addressData;
    }
    /**
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @param bool|false $updateStatus
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateSubscriber(\Magento\Newsletter\Model\Subscriber $subscriber, $updateStatus = false)
    {
        $storeId = $subscriber->getStoreId();
        $listId = $this->_helper->getGeneralList($storeId);
        $newStatus = $this->_getMCStatus($subscriber->getStatus(), $storeId);
        $forceStatus = ($updateStatus) ? $newStatus : null;
        $api = $this->_helper->getApi($storeId);
        $mergeVars = $this->getMergeVars($subscriber);
        $md5HashEmail = md5(strtolower($subscriber->getSubscriberEmail()));
        try {
            $api->lists->members->addOrUpdate(
                $listId, $md5HashEmail, $subscriber->getSubscriberEmail(), $newStatus, null, $forceStatus, $mergeVars,
                null, null, null, null
            );
            $this->_updateSubscriber($listId, $subscriber->getId(), $this->_date->gmtDate(), '', 0);
        } catch(\MailChimp_Error $e) {
            if ($newStatus === 'subscribed' && strstr($e->getMessage(), 'is in a compliance state')) {
                try {
                    $api->lists->members->update($listId, $md5HashEmail, null, 'pending', $mergeVars);
                    $subscriber->setSubscriberStatus(\Magento\Newsletter\Model\Subscriber::STATUS_UNCONFIRMED);
                    $message = __('To begin receiving the newsletter, you must first confirm your subscription');
                    $this->_message->addWarningMessage($message);
                } catch(\MailChimp_Error $e) {
                    $this->_helper->log($e->getMessage(), $storeId);
                    $this->_message->addErrorMessage(($e->getMessage()));
                    $subscriber->unsubscribe();
                } catch (\Exception $e) {
                    $this->_helper->log($e->getMessage(), $storeId);
                }
            } else {
                $subscriber->unsubscribe();
                $this->_helper->log($e->getMessage(), $storeId);
                $this->_message->addErrorMessage($e->getMessage());
            }
        } catch (\Exception $e) {
            $this->_helper->log($e->getMessage(), $storeId);
        }
    }
    /**
     * Get status to send confirmation if Need to Confirm enabled on Magento
     *
     * @param $status
     * @param $storeId
     * @return string
     */
    protected function _getMCStatus($status, $storeId)
    {
        $confirmationFlagPath = \Magento\Newsletter\Model\Subscriber::XML_PATH_CONFIRMATION_FLAG;
        if ($status == \Magento\Newsletter\Model\Subscriber::STATUS_UNSUBSCRIBED) {
            $status = 'unsubscribed';
        } elseif ($this->_helper->getConfigValue($confirmationFlagPath, $storeId) &&
            ($status == \Magento\Newsletter\Model\Subscriber::STATUS_NOT_ACTIVE ||
                $status == \Magento\Newsletter\Model\Subscriber::STATUS_UNCONFIRMED)
        ) {
            $status = 'pending';
        } elseif ($status == \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED) {
            $status = 'subscribed';
        }
        return $status;
    }
    public function removeSubscriber(\Magento\Newsletter\Model\Subscriber  $subscriber)
    {
        $storeId = $subscriber->getStoreId();
        $listId = $this->_helper->getGeneralList($storeId);
        $api = $this->_helper->getApi($storeId);
        try {
            $md5HashEmail = md5(strtolower($subscriber->getSubscriberEmail()));
            $api->lists->members->update($listId, $md5HashEmail, null, 'unsubscribed');
        } catch(\MailChimp_Error $e) {
            $this->_helper->log($e->getMessage(), $storeId);
            $this->_message->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->_helper->log($e->getMessage(), $storeId);
        }
    }
    public function deleteSubscriber(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        $storeId = $subscriber->getStoreId();
        $listId = $this->_helper->getGeneralList($storeId);
        $api = $this->_helper->getApi($storeId);
        try {
            $md5HashEmail = md5(strtolower($subscriber->getSubscriberEmail()));
            $api->lists->members->update($listId, $md5HashEmail, null, 'cleaned');
        } catch(\MailChimp_Error $e) {
            $this->_helper->log($e->getMessage(), $storeId);
            $this->_message->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->_helper->log($e->getMessage(), $storeId);
        }
    }
    public function update($emailAddress, $storeId)
    {
        $subscriber = $this->_subscriberFactory->create();
        $subscriber->getResource()->loadByEmail($emailAddress);
        if ($subscriber->getStatus() == \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED &&
            $subscriber->getMailchimpSyncDelta() > $this->_helper->getMCMinSyncDateFlag($storeId)) {
            $listId = $this->_helper->getGeneralList($storeId);
            $this->_updateSubscriber($listId, $subscriber->getId(),$this->_date->gmtDate(),'',1 );
        }
    }
    protected function _updateSubscriber($listId, $entityId, $sync_delta, $sync_error='', $sync_modified=0)
    {
        $this->_helper->saveEcommerceData($listId, $entityId, $sync_delta, $sync_error, $sync_modified,
            \Ebizmarts\MailChimp\Helper\Data::IS_SUBSCRIBER);
    }
}