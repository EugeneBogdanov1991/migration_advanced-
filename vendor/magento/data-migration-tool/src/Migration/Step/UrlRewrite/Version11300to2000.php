<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\UrlRewrite;

use Migration\App\Step\RollbackInterface;
use Migration\App\Step\StageInterface;
use Migration\Reader\MapInterface;
use Migration\Step\DatabaseStage;
use Migration\ResourceModel\Document;
use Migration\Step\UrlRewrite\Model;

/**
 * Class Version11300to2000
 * @SuppressWarnings(PHPMD)
 */
class Version11300to2000 extends DatabaseStage implements StageInterface, RollbackInterface
{
    /**
     * @var Model\TemporaryTable
     */
    protected $temporaryTable;

    /**
     * @var string
     */
    protected $cmsPageTableName = 'cms_page';

    /**
     * @var string
     */
    protected $cmsPageStoreTableName = 'cms_page_store';

    /**
     * @var array
     */
    protected $duplicateIndex;

    /**
     * @var array
     */
    protected $resolvedDuplicates = [];

    /**
     * ResourceModel of source
     *
     * @var \Migration\ResourceModel\Source
     */
    protected $source;

    /**
     * ResourceModel of destination
     *
     * @var \Migration\ResourceModel\Destination
     */
    protected $destination;

    /**
     * Record Factory
     *
     * @var \Migration\ResourceModel\RecordFactory
     */
    protected $recordFactory;

    /**
     * Record Collection Factory
     *
     * @var \Migration\ResourceModel\Record\CollectionFactory
     */
    protected $recordCollectionFactory;

    /**
     * LogLevelProcessor instance
     *
     * @var \Migration\App\ProgressBar\LogLevelProcessor
     */
    protected $progress;

    /**
     * Logger instance
     *
     * @var \Migration\Logger\Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $stage;

    /**
     * @var bool
     */
    protected static $dataInitialized = false;

    /**
     * @var array
     */
    protected $suffixData;

    /**
     * @var \Migration\Step\UrlRewrite\Helper
     */
    protected $helper;

    /**
     * @var Model\Version11300to2000\ProductRewritesIncludedIntoCategories
     */
    private $productRewritesIncludedIntoCategories;

    /**
     * @var Model\Version11300to2000\ProductRewritesWithoutCategories
     */
    private $productRewritesWithoutCategories;

    /**
     * @var Model\Suffix
     */
    private $suffix;

    /**
     * @var array
     */
    protected $structure = [
        MapInterface::TYPE_SOURCE => [
            'enterprise_url_rewrite' => [
                'url_rewrite_id',
                'request_path',
                'target_path',
                'is_system',
                'guid',
                'identifier',
                'inc',
                'value_id'
            ],
            'catalog_category_entity_url_key' => [
                'value_id',
                'entity_type_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value'
            ],
            'catalog_product_entity_url_key' => [
                'value_id',
                'entity_type_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value'
            ],
            'enterprise_url_rewrite_redirect' => [
                'redirect_id',
                'identifier',
                'target_path',
                'options',
                'description'
            ],
        ],
        MapInterface::TYPE_DEST => [
            'url_rewrite' => [
                'url_rewrite_id',
                'entity_type',
                'entity_id',
                'request_path',
                'target_path',
                'redirect_type',
                'store_id',
                'description',
                'is_autogenerated',
                'metadata'
            ],
            'catalog_category_entity_varchar' => [
                'value_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value',
            ],
            'catalog_product_entity_varchar' => [
                'value_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value',
            ]
        ],
    ];

    /**
     * @var string[]
     */
    private $resultMessages = [];

    /**
     * @param \Migration\App\ProgressBar\LogLevelProcessor $progress
     * @param \Migration\Logger\Logger $logger
     * @param \Migration\Config $config
     * @param \Migration\ResourceModel\Source $source
     * @param \Migration\ResourceModel\Destination $destination
     * @param \Migration\ResourceModel\Record\CollectionFactory $recordCollectionFactory
     * @param \Migration\ResourceModel\RecordFactory $recordFactory
     * @param \Migration\Step\UrlRewrite\Helper $helper
     * @param Model\Version11300to2000\ProductRewritesWithoutCategories $productRewritesWithoutCategories
     * @param Model\Version11300to2000\ProductRewritesIncludedIntoCategories $productRewritesIncludedIntoCategories
     * @param Model\Suffix $suffix
     * @param Model\TemporaryTable $temporaryTable
     * @param string $stage
     * @throws \Migration\Exception
     */
    public function __construct(
        \Migration\App\ProgressBar\LogLevelProcessor $progress,
        \Migration\Logger\Logger $logger,
        \Migration\Config $config,
        \Migration\ResourceModel\Source $source,
        \Migration\ResourceModel\Destination $destination,
        \Migration\ResourceModel\Record\CollectionFactory $recordCollectionFactory,
        \Migration\ResourceModel\RecordFactory $recordFactory,
        \Migration\Step\UrlRewrite\Helper $helper,
        Model\Version11300to2000\ProductRewritesWithoutCategories $productRewritesWithoutCategories,
        Model\Version11300to2000\ProductRewritesIncludedIntoCategories $productRewritesIncludedIntoCategories,
        Model\Suffix $suffix,
        Model\TemporaryTable $temporaryTable,
        $stage
    ) {
        $this->progress = $progress;
        $this->logger = $logger;
        $this->source = $source;
        $this->destination = $destination;
        $this->recordCollectionFactory = $recordCollectionFactory;
        $this->recordFactory = $recordFactory;
        $this->temporaryTable = $temporaryTable;
        $this->stage = $stage;
        $this->helper = $helper;
        $this->productRewritesWithoutCategories = $productRewritesWithoutCategories;
        $this->productRewritesIncludedIntoCategories = $productRewritesIncludedIntoCategories;
        $this->suffix = $suffix;
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function perform()
    {
        if (!method_exists($this, $this->stage)) {
            throw new \Migration\Exception('Invalid step configuration');
        }

        return call_user_func([$this, $this->stage]);
    }

    /**
     * Data migration
     *
     * @return bool
     * @throws \Migration\Exception
     */
    protected function data()
    {
        $this->getRewritesSelect();
        $this->progress->start($this->getIterationsCount());
        $this->destination->clearDocument('url_rewrite');

        $sourceDocument = $this->source->getDocument($this->temporaryTable->getName());
        $destinationDocument = $this->destination->getDocument('url_rewrite');
        $destProductCategory = $this->destination->getDocument('catalog_url_rewrite_product_category');

        $duplicates = $this->getDuplicatesList();
        if (!empty($duplicates) && !empty($this->configReader->getOption('auto_resolve_urlrewrite_duplicates'))
            && empty($this->duplicateIndex)
        ) {
            foreach ($duplicates as $row) {
                $this->duplicateIndex[$row['request_path']][] = $row;
            }
        }

        $pageNumber = 0;
        while (!empty($data = $this->source->getRecords($sourceDocument->getName(), $pageNumber))) {
            $pageNumber++;
            $records = $this->recordCollectionFactory->create();
            $destProductCategoryRecords = $destProductCategory->getRecords();
            foreach ($data as $row) {
                $this->progress->advance();
                $records->addRecord($this->recordFactory->create(['data' => $row]));
                $productCategoryRecord = $this->getProductCategoryRecord($destProductCategory, $row);
                if ($productCategoryRecord) {
                    $destProductCategoryRecords->addRecord($productCategoryRecord);
                }
            }
            $destinationRecords = $destinationDocument->getRecords();
            $this->migrateRewrites($records, $destinationRecords);
            $this->destination->saveRecords($destinationDocument->getName(), $destinationRecords);
            $this->destination->saveRecords($destProductCategory->getName(), $destProductCategoryRecords);
            $this->source->setLastLoadedRecord($sourceDocument->getName(), end($data));
        }
        $this->copyEavData('catalog_category_entity_url_key', 'catalog_category_entity_varchar', 'category');
        $this->copyEavData('catalog_product_entity_url_key', 'catalog_product_entity_varchar', 'product');
        $this->progress->finish();
        foreach ($this->resultMessages as $message) {
            $this->logger->addInfo($message);
        }
        return true;
    }

    /**
     * Get product category record
     *
     * @param Document $destProductCategory
     * @param array $row
     * @return \Migration\ResourceModel\Record|null
     * @throws \Migration\Exception
     */
    private function getProductCategoryRecord(Document $destProductCategory, array $row)
    {
        $destProductCategoryRecord = null;
        if ($row['is_system'] && $row['product_id'] && $row['category_id']) {
            $destProductCategoryRecord = $this->recordFactory->create(['document' => $destProductCategory]);
            $destProductCategoryRecord->setValue('url_rewrite_id', $row['id']);
            $destProductCategoryRecord->setValue('category_id', $row['category_id']);
            $destProductCategoryRecord->setValue('product_id', $row['product_id']);
        }
        return $destProductCategoryRecord;
    }

    /**
     * Get rewrites select
     *
     * @return \Magento\Framework\DB\Select
     */
    protected function getRewritesSelect()
    {
        if (!self::$dataInitialized) {
            $this->initTemporaryTable();
        }
        /** @var \Migration\ResourceModel\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();
        $select = $adapter->getSelect();
        $select->from(['r' => $this->source->addDocumentPrefix($this->temporaryTable->getName())]);
        return $select;
    }

    /**
     * Migrate rewrites
     *
     * @param \Migration\ResourceModel\Record\Collection $source
     * @param \Migration\ResourceModel\Record\Collection $destination
     * @return void
     */
    protected function migrateRewrites($source, $destination)
    {
        /** @var \Migration\ResourceModel\Record $sourceRecord */
        foreach ($source as $sourceRecord) {
            /** @var \Migration\ResourceModel\Record $destinationRecord */
            $destinationRecord = $this->recordFactory->create();
            $destinationRecord->setStructure($destination->getStructure());

            $destinationRecord->setValue('url_rewrite_id', $sourceRecord->getValue('id'));
            $destinationRecord->setValue('store_id', $sourceRecord->getValue('store_id'));
            $destinationRecord->setValue('description', $sourceRecord->getValue('description'));
            $destinationRecord->setValue('redirect_type', 0);
            $destinationRecord->setValue('is_autogenerated', $sourceRecord->getValue('is_system'));
            $destinationRecord->setValue('metadata', '');
            $destinationRecord->setValue('redirect_type', $sourceRecord->getValue('redirect_type'));
            $destinationRecord->setValue('entity_type', $sourceRecord->getValue('entity_type'));
            $destinationRecord->setValue('request_path', $sourceRecord->getValue('request_path'));

            $targetPath = $sourceRecord->getValue('target_path');

            $productId = $sourceRecord->getValue('product_id');
            $categoryId = $sourceRecord->getValue('category_id');
            $cmsPageId = $sourceRecord->getValue('cms_page_id');
            if (!empty($productId) && !empty($categoryId)) {
                $destinationRecord->setValue('metadata', json_encode(['category_id' => $categoryId]));
                $destinationRecord->setValue('entity_type', 'product');
                $destinationRecord->setValue('entity_id', $productId);
                $targetPath = "catalog/product/view/id/$productId/category/$categoryId";
            } elseif (!empty($productId) && empty($categoryId)) {
                $destinationRecord->setValue('entity_type', 'product');
                $destinationRecord->setValue('entity_id', $productId);
                $targetPath = 'catalog/product/view/id/' . $productId;
            } elseif (empty($productId) && !empty($categoryId)) {
                $destinationRecord->setValue('entity_type', 'category');
                $destinationRecord->setValue('entity_id', $categoryId);
                if ($sourceRecord->getValue('entity_type') != 'custom') {
                    $targetPath = 'catalog/category/view/id/' . $categoryId;
                }
            } elseif (!empty($cmsPageId)) {
                $destinationRecord->setValue('entity_id', $cmsPageId);
            } else {
                $destinationRecord->setValue('entity_id', 0);
            }

            if (!empty($this->duplicateIndex[$sourceRecord->getValue('request_path')])) {
                $shouldResolve = false;
                foreach ($this->duplicateIndex[$sourceRecord->getValue('request_path')] as &$duplicate) {
                    $onStore = $duplicate['store_id'] == $sourceRecord->getValue('store_id');
                    if ($onStore && empty($duplicate['used'])) {
                        $duplicate['used'] = true;
                        break;
                    }
                    if ($onStore) {
                        $shouldResolve = true;
                    }
                }
                if ($shouldResolve) {
                    $hash = md5(mt_rand());
                    $requestPath = preg_replace(
                        '/^(.*)\.([^\.]+)$/i',
                        '$1-' . $hash . '.$2',
                        $sourceRecord->getValue('request_path'),
                        1,
                        $isChanged
                    );
                    if (!$isChanged) {
                        $requestPath = $sourceRecord->getValue('request_path') . '-' . $hash;
                    }
                    $this->resolvedDuplicates[$destinationRecord->getValue('entity_type')]
                        [$destinationRecord->getValue('entity_id')]
                        [$sourceRecord->getValue('store_id')] = $hash;
                    $destinationRecord->setValue('request_path', $requestPath);
                    $this->resultMessages[] = 'Duplicate resolved. '
                        . sprintf(
                            'Request path was: %s Target path was: %s Store ID: %s New request path: %s',
                            $sourceRecord->getValue('request_path'),
                            $sourceRecord->getValue('target_path'),
                            $sourceRecord->getValue('store_id'),
                            $destinationRecord->getValue('request_path')
                        );
                }
            }

            $destinationRecord->setValue(
                'target_path',
                $targetPath
            );
            $destination->addRecord($destinationRecord);
        }
    }

    /**
     * Copy eav data
     *
     * @param string $sourceName
     * @param string $destinationName
     * @param string $type
     * @return void
     */
    protected function copyEavData($sourceName, $destinationName, $type)
    {
        $destinationDocument = $this->destination->getDocument($destinationName);
        $pageNumber = 0;
        while (!empty($recordsData = $this->source->getRecords($sourceName, $pageNumber))) {
            $pageNumber++;
            $records = $destinationDocument->getRecords();
            foreach ($recordsData as $row) {
                $this->progress->advance();
                $row['value_id'] = null;
                unset($row['entity_type_id']);
                if (!empty($this->resolvedDuplicates[$type][$row['entity_id']][$row['store_id']])) {
                    $row['value'] = $row['value'] . '-'
                        . $this->resolvedDuplicates[$type][$row['entity_id']][$row['store_id']];
                } elseif (!empty($this->resolvedDuplicates[$type][$row['entity_id']]) && $row['store_id'] == 0) {
                    foreach ($this->resolvedDuplicates[$type][$row['entity_id']] as $storeId => $urlKey) {
                        $storeRow = $row;
                        $storeRow['store_id'] = $storeId;
                        $storeRow['value'] = $storeRow['value'] . '-' . $urlKey;
                        $storeRow = $this->helper->processFields(
                            MapInterface::TYPE_DEST,
                            $destinationName,
                            $storeRow,
                            true
                        );
                        $records->addRecord($this->recordFactory->create(['data' => $storeRow]));
                        if (!isset($this->resolvedDuplicates[$destinationName])) {
                            $this->resolvedDuplicates[$destinationName] = 0;
                        }
                        $this->resolvedDuplicates[$destinationName]++;
                    }
                }
                $row = $this->helper->processFields(MapInterface::TYPE_DEST, $destinationName, $row, true);
                $records->addRecord($this->recordFactory->create(['data' => $row]));
            }
            $this->destination->saveRecords($destinationName, $records, true);
        }
    }

    /**
     * @inheritdoc
     */
    protected function integrity()
    {
        $errors = false;
        $this->progress->start(
            count($this->structure[MapInterface::TYPE_SOURCE]) + count($this->structure[MapInterface::TYPE_DEST])
        );
        foreach ($this->structure as $resourceName => $documentList) {
            $resource = $resourceName == MapInterface::TYPE_SOURCE ? $this->source : $this->destination;
            foreach ($documentList as $documentName => $documentFields) {
                $this->progress->advance();
                $document = $resource->getDocument($documentName);
                if ($document === false) {
                    $message = sprintf('%s table does not exist: %s', ucfirst($resourceName), $documentName);
                    $this->logger->error($message);
                    $errors = true;
                    continue;
                }
                $documentFields = $this->helper->processFields($resourceName, $documentName, $documentFields);
                $structure = array_keys($document->getStructure()->getFields());
                if (!(empty(array_diff($structure, $documentFields))
                    && empty(array_diff($documentFields, $structure)))
                ) {
                    $message = sprintf(
                        '%s table structure does not meet expectation: %s',
                        ucfirst($resourceName),
                        $documentName
                    );
                    $this->logger->error($message);
                    $errors = true;
                }
            }
        }
        $this->progress->finish();

        return !$errors && !$this->processDuplicatesList();
    }

    /**
     * Process duplicates list
     *
     * @return bool
     */
    private function processDuplicatesList()
    {
        $errors = false;
        $data = $this->getDuplicatesList();
        if (!empty($data)) {
            $duplicates = [];
            foreach ($data as $row) {
                $duplicates[] = sprintf(
                    'Request path: %s Store ID: %s Target path: %s',
                    $row['request_path'],
                    $row['store_id'],
                    $row['target_path']
                );
            }

            $message = sprintf(
                'There are duplicates in URL rewrites:%s',
                PHP_EOL . implode(PHP_EOL, $duplicates)
            );

            if (!empty($this->configReader->getOption('auto_resolve_urlrewrite_duplicates'))) {
                $this->logger->addInfo($message);
            } else {
                $this->logger->error($message);
                $errors = true;
            }
        }
        return $errors;
    }

    /**
     * @inheritdoc
     */
    protected function volume()
    {
        $this->progress->start(1);
        $this->getRewritesSelect();
        $this->progress->advance();
        $result = $this->source->getRecordsCount($this->temporaryTable->getName())
            == $this->destination->getRecordsCount('url_rewrite');
        if (!$result) {
            $this->logger->error('Mismatch of entities in the document: url_rewrite');
        }
        $this->progress->finish();
        return $result;
    }

    /**
     * Get iterations count for step
     *
     * @return int
     */
    protected function getIterationsCount()
    {
        return $this->source->getRecordsCount($this->temporaryTable->getName())
        + $this->source->getRecordsCount('catalog_category_entity_url_key')
        + $this->source->getRecordsCount('catalog_product_entity_url_key');
    }

    /**
     * Get duplicates list
     *
     * @return array
     */
    protected function getDuplicatesList()
    {
        $subSelect = $this->getRewritesSelect();
        $subSelect->group(['request_path', 'store_id'])
            ->having('COUNT(*) > 1');

        /** @var \Migration\ResourceModel\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();

        /** @var \Magento\Framework\DB\Select $select */
        $select = $adapter->getSelect();
        $select->from(['t' => $this->source->addDocumentPrefix($this->temporaryTable->getName())], ['t.*'])
            ->join(
                ['t2' => new \Zend_Db_Expr(sprintf('(%s)', $subSelect->assemble()))],
                't2.request_path = t.request_path AND t2.store_id = t.store_id',
                []
            )
            ->order(['store_id', 'request_path', 'priority']);
        $resultData = $adapter->loadDataFromSelect($select);

        return $resultData;
    }

    /**
     * Initialize temporary table and insert UrlRewrite data
     *
     * @codeCoverageIgnore
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @return void
     */
    protected function initTemporaryTable()
    {
        /** @var \Migration\ResourceModel\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();
        $this->temporaryTable->create();
        $this->collectRedirects($adapter);
        $this->collectProductRewrites($adapter);
        $this->collectCategoryRewrites($adapter);
        $this->collectCmsPageRewrites($adapter);
        self::$dataInitialized = true;
    }

    /**
     * Fulfill temporary table with category url rewrites
     *
     * @param \Migration\ResourceModel\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectCategoryRewrites(\Migration\ResourceModel\Adapter\Mysql $adapter)
    {
        $requestPath = sprintf("CONCAT(`r`.`request_path`, %s)", $this->suffix->getSuffix('category', 'eccr'));
        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => $requestPath,
                'target_path' => 'r.target_path',
                'is_system' => 'r.is_system',
                'store_id' => 's.store_id',
                'entity_type' => "trim('category')",
                'redirect_type' => "trim('0')",
                'product_id' => "trim('0')",
                'category_id' => "c.entity_id",
                'cms_page_id' => "trim('0')",
                'priority' => "trim('3')"
            ]
        );
        $select->join(
            ['c' => $this->source->addDocumentPrefix('catalog_category_entity_url_key')],
            'r.value_id = c.value_id',
            []
        );
        $select->join(
            ['eccr' => $this->source->addDocumentPrefix('enterprise_catalog_category_rewrite')],
            'eccr.url_rewrite_id = r.url_rewrite_id and eccr.store_id = 0',
            []
        );
        $select->join(
            ['s' => $this->source->addDocumentPrefix('core_store')],
            's.store_id > 0',
            []
        );

        $query = $select
            ->insertFromSelect($this->source->addDocumentPrefix($this->temporaryTable->getName()));
        $select->getAdapter()->query($query);

        $requestPath = sprintf("CONCAT(`r`.`request_path`, %s)", $this->suffix->getSuffix('category', 'eccr'));
        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => $requestPath,
                'target_path' => 'r.target_path',
                'is_system' => 'r.is_system',
                'store_id' => 'eccr.store_id',
                'entity_type' => "trim('category')",
                'redirect_type' => "trim('0')",
                'product_id' => "trim('0')",
                'category_id' => "c.entity_id",
                'cms_page_id' => "trim('0')",
                'priority' => "trim('3')"
            ]
        );
        $select->join(
            ['c' => $this->source->addDocumentPrefix('catalog_category_entity_url_key')],
            'r.value_id = c.value_id',
            []
        );
        $select->join(
            ['eccr' => $this->source->addDocumentPrefix('enterprise_catalog_category_rewrite')],
            'eccr.url_rewrite_id = r.url_rewrite_id and eccr.store_id > 0',
            []
        );

        $query = $select
            ->insertFromSelect($this->source->addDocumentPrefix($this->temporaryTable->getName()));
        $select->getAdapter()->query($query);
    }

    /**
     * Fulfill temporary table with Cms Page url rewrites
     *
     * @param \Migration\ResourceModel\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectCmsPageRewrites(\Migration\ResourceModel\Adapter\Mysql $adapter)
    {
        $select = $adapter->getSelect();
        $select->distinct()->from(
            ['cp' => $this->source->addDocumentPrefix($this->cmsPageTableName)],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => 'cp.identifier',
                'target_path' => 'CONCAT("cms/page/view/page_id/", cp.page_id)',
                'is_system' => "trim('1')",
                'store_id' => 'IF(cps.store_id = 0, 1, cps.store_id)',
                'entity_type' => "trim('cms-page')",
                'redirect_type' => "trim('0')",
                'product_id' => "trim('0')",
                'category_id' => "trim('0')",
                'cms_page_id' => "cp.page_id",
                'priority' => "trim('5')"
            ]
        )->joinLeft(
            ['cps' => $this->source->addDocumentPrefix($this->cmsPageStoreTableName)],
            'cps.page_id = cp.page_id',
            []
        )->group(['request_path', 'cps.store_id']);
        $query = $select->insertFromSelect($this->source->addDocumentPrefix($this->temporaryTable->getName()));
        $select->getAdapter()->query($query);
    }

    /**
     * Fulfill temporary table with product url rewrites
     *
     * @param \Migration\ResourceModel\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectProductRewrites(\Migration\ResourceModel\Adapter\Mysql $adapter)
    {
        $queryExecute = function ($query) use ($adapter) {
            $adapter->getSelect()->getAdapter()->query($query);
        };
        $queryExecute($this->productRewritesWithoutCategories->getQueryProductsSavedForDefaultScope());
        $queryExecute($this->productRewritesWithoutCategories->getQueryProductsSavedForParticularStoreView());
        $queryExecute($this->productRewritesIncludedIntoCategories->getQueryProductsSavedForDefaultScope());
        $queryExecute($this->productRewritesIncludedIntoCategories->getQueryProductsSavedForParticularStoreView());
    }

    /**
     * Fulfill temporary table with redirects
     *
     * @param \Migration\ResourceModel\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectRedirects(\Migration\ResourceModel\Adapter\Mysql $adapter)
    {
        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => 'r.request_path',
                'target_path' => 'r.target_path',
                'is_system' => 'r.is_system',
                'store_id' => "s.store_id",
                'entity_type' => "trim('custom')",
                'redirect_type' => "(SELECT CASE eurr.options WHEN 'RP' THEN 301 WHEN 'R' THEN 302 ELSE 0 END)",
                'product_id' => "trim('0')",
                'category_id' => "trim('0')",
                'cms_page_id' => "trim('0')",
                'priority' => "trim('2')"
            ]
        );
        $select->join(
            ['eurrr' => $this->source->addDocumentPrefix('enterprise_url_rewrite_redirect_rewrite')],
            'eurrr.url_rewrite_id = r.url_rewrite_id',
            []
        );
        $select->join(
            ['eurr' => $this->source->addDocumentPrefix('enterprise_url_rewrite_redirect')],
            'eurrr.redirect_id = eurr.redirect_id',
            []
        );
        $select->join(
            ['s' => $this->source->addDocumentPrefix('core_store')],
            's.store_id > 0',
            []
        );

        $query = $select
            ->insertFromSelect($this->source->addDocumentPrefix($this->temporaryTable->getName()));
        $select->getAdapter()->query($query);
    }

    /**
     * Perform rollback
     *
     * @return void
     */
    public function rollback()
    {
        $this->destination->clearDocument('url_rewrite');
        $this->destination->clearDocument('catalog_url_rewrite_product_category');
    }
}
