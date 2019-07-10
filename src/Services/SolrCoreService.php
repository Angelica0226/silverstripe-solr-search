<?php

namespace Firesphere\SolrSearch\Services;

use Exception;
use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\SearchIntrospection;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Interfaces\ConfigStore;
use LogicException;
use ReflectionClass;
use ReflectionException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Guzzle;
use Solarium\QueryType\Server\CoreAdmin\Query\Query;
use Solarium\QueryType\Server\CoreAdmin\Result\StatusResult;
use Solarium\QueryType\Update\Result;

class SolrCoreService
{
    /**
     * Unique ID in Solr
     */
    public const ID_FIELD = 'id';
    /**
     * SilverStripe ID of the object
     */
    public const CLASS_ID_FIELD = 'ObjectID';
    /**
     * Solr update types
     */
    public const DELETE_TYPE_ALL = 'deleteall';
    public const DELETE_TYPE = 'delete';
    public const UPDATE_TYPE = 'update';
    public const CREATE_TYPE = 'create';


    use Configurable;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $validIndexes = [];

    /**
     * @var Query
     */
    protected $admin;

    /**
     * Add debugging information
     * @var bool
     */
    protected $inDebugMode = false;


    /**
     * SolrCoreService constructor.
     * @throws ReflectionException
     */
    public function __construct()
    {
        $config = static::config()->get('config');
        $this->client = new Client($config);
        $this->client->setAdapter(new Guzzle());
        $this->admin = $this->client->createCoreAdmin();
        $this->filterIndexes();
    }

    /**
     * @throws ReflectionException
     */
    protected function filterIndexes(): void
    {
        $indexes = ClassInfo::subclassesFor(BaseIndex::class);
        foreach ($indexes as $subindex) {
            $ref = new ReflectionClass($subindex);
            if ($ref->isInstantiable()) {
                $this->validIndexes[] = $subindex;
            }
        }
    }

    /**
     * Create a new core
     * @param $core string - The name of the core
     * @param ConfigStore $configStore
     * @return bool
     */
    public function coreCreate($core, $configStore): bool
    {
        $action = $this->admin->createCreate();

        $action->setCore($core);

        $action->setInstanceDir($configStore->instanceDir($core));

        $this->admin->setAction($action);

        $response = $this->client->coreAdmin($this->admin);

        return $response->getWasSuccessful();
    }

    /**
     * @param $core
     * @return StatusResult|null
     */
    public function coreReload($core): ?StatusResult
    {
        $reload = $this->admin->createReload();
        $reload->setCore($core);

        $this->admin->setAction($reload);

        $response = $this->client->coreAdmin($this->admin);

        return $response->getStatusResult();
    }

    /**
     * @param $core
     * @return StatusResult|null
     * @deprecated backward compatibility stub
     */
    public function coreIsActive($core): ?StatusResult
    {
        return $this->coreStatus($core);
    }

    /**
     * @param $core
     * @return StatusResult|null
     */
    public function coreStatus($core): ?StatusResult
    {
        $status = $this->admin->createStatus();
        $status->setCore($core);

        $this->admin->setAction($status);
        $response = $this->client->coreAdmin($this->admin);

        return $response->getStatusResult();
    }

    /**
     * Remove a core from Solr
     * @param string $core core name
     * @return StatusResult|null A result is successful
     */
    public function coreUnload($core): ?StatusResult
    {
        $unload = $this->admin->createUnload();
        $unload->setCore($core);

        $this->admin->setAction($unload);
        $response = $this->client->coreAdmin($this->admin);

        return $response->getStatusResult();
    }

    /**
     * @param ArrayList|DataList|array $items
     * @param string $type
     * @param null|string $index
     * @return bool|Result
     * @throws ReflectionException
     * @throws Exception
     */
    public function updateItems($items, $type, $index = null)
    {
        if ($items === null) {
            throw new LogicException('Can\'t manipulate an empty item set');
        }
        if ($type === static::DELETE_TYPE_ALL) {
            throw new LogicException('To delete all items, call doManipulate directly');
        }

        $indexes = $this->getValidIndexes($index);

        $result = false;
        $items = !($items instanceof SS_List) ? $items : ArrayList::create($items);

        $hierarchy = SearchIntrospection::hierarchy($items->first()->ClassName);

        foreach ($indexes as $indexString) {
            /** @var BaseIndex $index */
            $index = Injector::inst()->get($indexString);
            $classes = $index->getClasses();
            $inArray = array_intersect($classes, $hierarchy);
            // No point in sending a delete|update|create for something that's not in the index
            if (!count($inArray)) {
                continue;
            }

            $result = $this->doManipulate($items, $type, $index);
        }
        gc_collect_cycles();

        return $result;
    }

    /**
     * Get valid indexes for the project
     * @param null|string $index
     * @return array
     */
    public function getValidIndexes($index = null): ?array
    {
        if ($index && !in_array($index, $this->validIndexes, true)) {
            throw new LogicException('Incorrect index ' . $index);
        }

        if ($index) {
            return [$index];
        }

        // return the array values, to reset the keys
        return array_values($this->validIndexes);
    }

    /**
     * @param $items
     * @param $type
     * @param BaseIndex $index
     * @return Result
     * @throws Exception
     */
    public function doManipulate($items, $type, BaseIndex $index): Result
    {
        $client = $index->getClient();

        // get an update query instance
        $update = $client->createUpdate();

        // add the delete query and a commit command to the update query
        if ($type === static::DELETE_TYPE) {
            foreach ($items as $item) {
                $update->addDeleteById(sprintf('%s-%s', $item->ClassName, $item->ID));
            }
        } elseif ($type === static::DELETE_TYPE_ALL) {
            $update->addDeleteQuery('*:*');
        } elseif ($type === static::UPDATE_TYPE || $type === static::CREATE_TYPE) {
            $this->updateIndex($index, $items, $update);
        }
        $update->addCommit();

        return $client->update($update);
    }

    /**
     * @param BaseIndex $index
     * @param ArrayList|DataList $items
     * @param \Solarium\QueryType\Update\Query\Query $update
     * @throws Exception
     */
    public function updateIndex($index, $items, $update): void
    {
        $fields = $index->getFieldsForIndexing();
        $factory = $this->getFactory($items);
        $docs = $factory->buildItems($fields, $index, $update);
        if (count($docs)) {
            $update->addDocuments($docs);
        }
    }

    /**
     * @param ArrayList|DataList $items
     * @return DocumentFactory
     */
    protected function getFactory($items): DocumentFactory
    {
        $factory = Injector::inst()->get(DocumentFactory::class);
        $factory->setItems($items);
        $factory->setClass($items->first()->ClassName);
        $factory->setDebug($this->isInDebugMode());

        return $factory;
    }

    /**
     * @return bool
     */
    public function isInDebugMode(): bool
    {
        return $this->inDebugMode;
    }

    /**
     * @param bool $inDebugMode
     * @return SolrCoreService
     */
    public function setInDebugMode(bool $inDebugMode): SolrCoreService
    {
        $this->inDebugMode = $inDebugMode;

        return $this;
    }

    /**
     * @return int
     */
    public function getSolrVersion(): int
    {
        $config = self::config()->get('config');
        $firstEndpoint = array_shift($config['endpoint']);
        $clientConfig = [
            'base_uri' => 'http://' . $firstEndpoint['host'] . ':' . $firstEndpoint['port']
        ];

        $client = new \GuzzleHttp\Client($clientConfig);

        $result = $client->get('solr/admin/info/system');
        $result = json_decode($result->getBody(), 1);

        $solrVersion = 5;
        $version = version_compare('5.0.0', $result['lucene']['solr-spec-version']);
        if ($version > 0) {
            $solrVersion = 4;
        }

        return $solrVersion;
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param Client $client
     * @return SolrCoreService
     */
    public function setClient($client): SolrCoreService
    {
        $this->client = $client;

        return $this;
    }
}
