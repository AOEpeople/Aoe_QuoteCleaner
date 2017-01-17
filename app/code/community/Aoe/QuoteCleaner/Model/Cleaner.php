<?php

/**
 * Class Aoe_QuoteCleaner_Model_Cleaner
 *
 * @category Model
 * @package  Aoe_QuoteCleaner
 * @author   AOE Magento Team <team-magento@aoe.com>
 * @link     www.aoe.com
 */
class Aoe_QuoteCleaner_Model_Cleaner
{
    /**
     * Clean old quote entries.
     * This method will be called via a Magento crontab task.
     *
     * @return array
     */
    public function clean()
    {
        $report = array();

        $limit = intval(Mage::getStoreConfig('system/quotecleaner/limit'));
        $limit = min($limit, 50000);

        // Minimum Quote age in Days
        $minQuoteAgeSafeguard = max(
            intval(Mage::getStoreConfig('system/quotecleaner/min_quote_age_safeguard')),
            1
        );

        $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
        /* @var $writeConnection Varien_Db_Adapter_Pdo_Mysql */

        $tableName = Mage::getSingleton('core/resource')->getTableName('sales/quote');
        $tableName = $writeConnection->quoteIdentifier($tableName, true);

        $customerConditions = [
            'customer' => '(NOT ISNULL(customer_id) AND customer_id != 0)',
            'anonymous' => '(ISNULL(customer_id) OR customer_id = 0)'
        ];
        $itemsConditions = [
            'anyNumberOfItems' => '',
            'noItems' => 'items_qty = 0'
        ];
        $configurationPaths = [
            'customer_anyNumberOfItems' =>  'system/quotecleaner/clean_quoter_older_than',
            'customer_noItems' =>           'system/quotecleaner/clean_quotes_without_items_older_than',
            'anonymous_anyNumberOfItems' => 'system/quotecleaner/clean_anonymous_quotes_older_than',
            'anonymous_noItems' =>          'system/quotecleaner/clean_anonymous_quotes_without_items_older_than'
        ];

        foreach ($customerConditions as $customerConditionKey => $customerCondition) {
            foreach ($itemsConditions as $itemsConditionKey => $itemsCondition) {
                $key = $customerConditionKey.'_'.$itemsConditionKey;
                $configurationPath = $configurationPaths[$key];
                $olderThan = intval(Mage::getStoreConfig($configurationPath));
                if (empty($olderThan)) {
                    continue;
                }
                $olderThan = max($olderThan, $minQuoteAgeSafeguard);

                $conditions = [
                    'updated_at < DATE_SUB(Now(), INTERVAL '.$olderThan.' DAY)',
                    $customerCondition
                ];
                if ($itemsCondition) {
                    $conditions[] = $itemsCondition;
                }

                $startTime = time();
                $sql = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT ' . $limit;
                $stmt = $writeConnection->query($sql);
                $report[$key]['count'] = $stmt->rowCount();
                $report[$key]['duration'] = time() - $startTime;
                Mage::log('[QUOTECLEANER] Cleaning quotes (mode: '.$key.', duration: ' . $report[$key]['duration'] . ', row count: ' . $report[$key]['count'] . ')');
            }
        }

        return $report;
    }
}
