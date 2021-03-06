<?php

namespace WEBcoast\VersatileCrawler\Crawler;


use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\IndexedSearch\Indexer;
use WEBcoast\VersatileCrawler\Domain\Model\Item;
use WEBcoast\VersatileCrawler\Frontend\IndexHook;
use WEBcoast\VersatileCrawler\Queue\Manager;

abstract class FrontendRequestCrawler implements CrawlerInterface, QueueInterface
{
    /**
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item                $item
     * @param \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $typoScriptFrontendController
     *
     * @return boolean
     */
    abstract public function isIndexingAllowed(Item $item, TypoScriptFrontendController $typoScriptFrontendController);

    /**
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item $item
     * @param array                                        $configuration
     *
     * @return string
     */
    abstract protected function buildQueryString(Item $item, array $configuration);

    public function processQueueItem(Item $item, array $configuration)
    {
        $url = $this->buildRequestUrl($item, $configuration);
        $hash = md5($item->getIdentifier() . time());
        $queueManager = GeneralUtility::makeInstance(Manager::class);
        $queueManager->prepareItemForProcessing($item->getConfiguration(), $item->getIdentifier(), $hash);
        $curlHandle = curl_init();
        curl_setopt_array(
            $curlHandle,
            [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_RETURNTRANSFER => true,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_HTTPHEADER => [IndexHook::HASH_HEADER . ': ' . $hash]
            ]
        );
        $extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['versatile_crawler']);
        if ((int)$extensionConfiguration['disableCertificateCheck'] === 1) {
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, 0);
        }
        // use this for debugging the frontend indexing part
//        curl_setopt($curlHandle, CURLOPT_COOKIE, 'XDEBUG_SESSION=PHPSTORM');
        $content = curl_exec($curlHandle);
        $response = curl_getinfo($curlHandle);
        curl_close($curlHandle);

        if ($response['http_code'] >= 200 && $response['http_code'] <= 300) {
            $result = json_decode($content, true);
            if (is_array($result) && $result['state'] === 'success') {
                $item->setState(Item::STATE_SUCCESS);
            } else {
                $item->setState(Item::STATE_ERROR);
                $item->setMessage(sprintf('An error occurred. the call to the url "%s" did not returned a valid json. This could only happen in the unlikely case of a missing "%s" header.', $response['url'], IndexHook::HASH_HEADER));
            }
        } else {
            $result = json_decode($content, true);
            $item->setState(Item::STATE_ERROR);
            if (is_array($result)) {
                $item->setMessage($result['message']);
            } else {
                $item->setMessage(sprintf('An error occurred. The call to the url "%s" returned the status code %d', $response['url'], $response['http_code']));
            }
        }

        return true;
    }

    protected function buildRequestUrl(Item $item, array $configuration) {
        $url = isset($configuration['base_url']) && !empty($configuration['base_url']) ? $configuration['base_url'] : null;
        if ($url === null && $configuration['domain'] > 0) {
            $domainResult = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_domain')
                ->select(['domainName'], 'sys_domain', ['uid' => $configuration['domain']]);
            $domain = $domainResult->fetch();
            if (is_array($domain) && isset($domain['domainName'])) {
                $urlParts = ['host' => $domain['domainName']];
            }
        } else {
            $urlParts = parse_url($url);
        }
        if (!isset($urlParts['scheme']) || empty($urlParts['scheme'])) {
            $urlParts['scheme'] = 'http';
        }
        if (!isset($urlParts['path']) || empty($urlParts['path'])) {
            $urlParts['path'] = '/index.php';
        } else {
            if (substr($urlParts['path'], -1) !== '/') {
                $urlParts['path'] .= '/';
            }
            $urlParts['path'] .= 'index.php';
        }
        $urlParts['query'] = $this->buildQueryString($item, $configuration);

        return HttpUtility::buildUrl($urlParts);
    }

    /**
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item $item
     *
     * @return int
     */
    public function getRecordUid(Item $item)
    {
        return 0;
    }

    public function enrichIndexData(Item $item, TypoScriptFrontendController $typoScriptFrontendController, Indexer &$indexer)
    {
        // just do nothing here
    }
}
