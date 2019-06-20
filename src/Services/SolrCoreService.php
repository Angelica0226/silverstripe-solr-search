<?php

namespace Firesphere\SolrSearch\Services;

use Firesphere\SolrSearch\Interfaces\ConfigStore;
use SilverStripe\Core\Config\Configurable;
use Solarium\Client;
use Solarium\QueryType\Server\CoreAdmin\Query\Query;
use Solarium\QueryType\Server\CoreAdmin\Result\StatusResult;

class SolrCoreService
{
    use Configurable;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Query
     */
    protected $admin;

    public function __construct()
    {
        $config = static::config()->get('config');
        $this->client = new Client($config);
        $this->admin = $this->client->createCoreAdmin();
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
