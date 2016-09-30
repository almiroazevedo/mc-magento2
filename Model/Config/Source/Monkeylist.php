<?php
/**
 * mc-magento2 Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento2
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 9/30/16 10:43 AM
 * @file: Monkeylist.php
 */
namespace Ebizmarts\MailChimp\Model\Config\Source;

class Monkeylist implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @var \Ebizmarts\MailChimp\Helper\Data
     */
    protected $_helper = null;
    protected $_options = null;
    /**
     * Monkeylist constructor.
     * @param \Ebizmarts\MailChimp\Helper\Data $helper
     */
    public function __construct(
        \Ebizmarts\MailChimp\Helper\Data $helper
    )
    {
        $this->_helper = $helper;
        if ($this->_helper->getApiKey()) {
            $this->_options = $this->_helper->getApi()->lists->getLists(null, 'lists', null, 100);
        }
    }
    public function toOptionArray()
    {
        if (is_array($this->_options)) {
            $rc = array();
            foreach ($this->_options['lists'] as $list) {
                $memberCount = $list['stats']['member_count'];
                $memberText = __('members');
                $label = $list['name'] . ' (' . $memberCount . ' ' . $memberText . ')';
                $rc[] = ['value' => $list['id'], 'label' => $label];
            }
        } else {
            $rc[] = ['value' => 0, 'label' => __('---No Data---')];
        }
        return $rc;
    }
    public function toArray()
    {
        $rc = array();
        foreach ($this->_options['lists'] as $list) {
            $rc[$list['id']] = $list['name'];
        }
        return $rc;
    }
}