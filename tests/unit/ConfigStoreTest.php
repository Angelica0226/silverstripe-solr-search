<?php


namespace Firesphere\SolrSearch\Tests;

use Firesphere\SolrSearch\Stores\FileConfigStore;
use Firesphere\SolrSearch\Stores\PostConfigStore;
use Psr\Http\Message\ResponseInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use TestPostController;

class ConfigStoreTest extends SapphireTest
{

    /**
     * @expectedException \Solarium\Exception\RuntimeException
     */
    public function testFileConstruct()
    {
        new FileConfigStore([]);
    }

    public function testFileConstructGood()
    {
        $store = new FileConfigStore(['path' => 'test']);
        $this->assertEquals(Director::baseFolder() . '/test/lol', $store->instanceDir('lol'));

        $this->assertEquals(['path' => Director::baseFolder() . '/test'], $store->getConfig());
    }

    /**
     * @expectedException \Solarium\Exception\RuntimeException
     */
    public function testPostConstruct()
    {
        new PostConfigStore([]);
    }

    public function testPostConstructGood()
    {
        $store = new PostConfigStore(['path' => 'test']);

        $this->assertEquals('test/', $store->getPath());

        $this->assertEquals('lol', $store->instanceDir('lol'));
    }

    public function testPostConstructNoPath()
    {
        $store = new PostConfigStore(['test' => 'this']);

        $this->assertEquals('/', $store->getPath());
    }
}
