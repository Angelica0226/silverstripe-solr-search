<?php


namespace Firesphere\SolrSearch\Tasks;

use Exception;
use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\SearchIntrospection;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Services\SolrCoreService;
use ReflectionClass;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\Versioned\Versioned;
use Solarium\Core\Client\Client;

class SolrIndexTask extends BuildTask
{
    /**
     * @var string
     */
    private static $segment = 'SolrIndexTask';

    /**
     * @var string
     */
    protected $title = 'Solr Index update';

    /**
     * @var string
     */
    protected $description = 'Add or update documents to an existing Solr core.';

    /**
     * @var SearchIntrospection
     */
    protected $introspection;

    /**
     * @var DocumentFactory
     */
    protected $factory;

    /**
     * SolrIndexTask constructor. Sets up the document factory
     */
    public function __construct()
    {
        parent::__construct();
        $this->factory = Injector::inst()->get(DocumentFactory::class);
        // Only index live items.
        // The old FTS module also indexed Draft items. This is unnecessary
        Versioned::set_reading_mode(Versioned::DRAFT . '.' . Versioned::LIVE);

        $this->introspection = new SearchIntrospection();
    }

    /**
     * Implement this method in the task subclass to
     * execute via the TaskRunner
     *
     * @param HTTPRequest $request
     * @return int|bool
     * @throws Exception
     * @todo clean up a bit, this is becoming a mess
     * @todo defer to background because it may run out of memory
     * @todo give Solr more time to respond
     * @todo Change the debug messaging to use the logger
     */
    public function run($request)
    {
        $start = time();
        $vars = $request->getVars();
        $indexes = ClassInfo::subclassesFor(BaseIndex::class);
        // If the given index is not an actual index, skip
        if (isset($vars['index']) && !in_array($vars['index'], $indexes, true)) {
            return false;
        }
        // If above doesn't fail, make the set var into an array to be indexed downstream, or continue with all indexes
        if (isset($vars['index']) && in_array($vars['index'], $indexes, true)) {
            $indexes = [$vars['index']];
        }
        // If all else fails, assume we're running a full index.

        $debug = isset($vars['debug']) ? true : false;
        // Debug if in dev or CLI, or debug is requested explicitly
        $debug = (Director::isDev() || Director::is_cli()) || $debug;

        Debug::message(date('Y-m-d H:i:s' . "\n"));

        $groups = 0;
        foreach ($indexes as $indexName) {
            // Skip the abstract base
            $ref = new ReflectionClass($indexName);
            if (!$ref->isInstantiable()) {
                continue;
            }

            /** @var BaseIndex $index */
            $index = Injector::inst()->get($indexName);

            // Only index the classes given in the var if needed, should be a single class
            $classes = isset($vars['class']) ? [$vars['class']] : $index->getClasses();

            $client = $index->getClient();
            $group = $request->getVar('group') ?: 0; // allow starting from a specific group
            $start = $request->getVar('start') ?: 0;
            // Set the start point to the requested value, if there is only one class to index
            if ($start > $group && count($classes) === 1) {
                $group = $start;
            }

            foreach ($classes as $class) {
                [$groups, $group] = $this->reindexClass(
                    $request->getVar('group'),
                    $class,
                    $debug,
                    $index,
                    $group,
                    $client
                );
            }
        }
        $end = time();

        Debug::message(sprintf("It took me %d seconds to do all the indexing\n", ($end - $start)), false);
        Debug::message("done!\n", false);
        gc_collect_cycles(); // Garbage collection to prevent php from running out of memory

        return $groups;
    }

    /**
     * @param $isGroup
     * @param $class
     * @param bool $debug
     * @param BaseIndex $index
     * @param int $group
     * @param Client $client
     * @return array
     * @throws Exception
     */
    protected function reindexClass($isGroup, $class, bool $debug, BaseIndex $index, int $group, Client $client): array
    {
        $batchLength = DocumentFactory::config()->get('batchLength');
        $groups = (int)ceil($class::get()->count() / $batchLength);
        if ($debug) {
            Debug::message(sprintf('Indexing %s for %s', $class, $index->getIndexName()), false);
        }
        $count = 0;
        $fields = $index->getFieldsForIndexing();
        // Run a single group
        if ($isGroup) {
            $this->doReindex($group, $groups, $client, $class, $fields, $index, $count, $debug);
        } else {
            // Otherwise, run them all
            while ($group <= $groups) { // Run from oldest to newest
                try {
                    [$count, $group] = $this->doReindex($group, $groups, $client, $class, $fields, $index, $count, $debug);
                } catch (Exception $e) {
                    Debug::message(date('Y-m-d H:i:s' . "\n"), false);
                    gc_collect_cycles(); // Garbage collection to prevent php from running out of memory
                    $group++;
                    continue;
                }
            }
            // Reset the group for the next class
            if ($group >= $groups) {
                $group = 0;
            }
        }

        return [$groups, $group];
    }

    /**
     * @param int $group
     * @param int $groups
     * @param Client $client
     * @param string $class
     * @param array $fields
     * @param BaseIndex $index
     * @param int $count
     * @param bool $debug
     * @return array[int, int]
     * @throws Exception
     */
    protected function doReindex(
        $group,
        $groups,
        Client $client,
        $class,
        array $fields,
        BaseIndex $index,
        $count,
        $debug
    ): array {
        gc_collect_cycles(); // Garbage collection to prevent php from running out of memory
        Debug::message(sprintf('Indexing %s group of %s', $group, $groups), false);
        $update = $client->createUpdate();
        $docs = $this->factory->buildItems($class, array_unique($fields), $index, $update, $group, $count, $debug);
        $update->addDocuments($docs, true, Config::inst()->get(SolrCoreService::class, 'commit_within'));
        $client->update($update);
        $group++;
        // get an update query instance
        $update = $client->createUpdate();
        // optimize the index
        $update->addOptimize(true, false, 5);
        $update->addCommit();
        $client->update($update);
        Debug::message(date('Y-m-d H:i:s' . "\n"), false);
        gc_collect_cycles(); // Garbage collection to prevent php from running out of memory

        return [$count, $group];
    }
}
