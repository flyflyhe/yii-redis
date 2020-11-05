<?php
require dirname(dirname(__DIR__)).'/vendor/autoload.php';
require dirname(dirname(__DIR__)) . '/vendor/yiisoft/yii2/Yii.php';

use \PHPUnit\Framework\TestCase;
use yii\redis\Connection as Redis;


class RedisTest extends TestCase
{
    /**
     * @var $redis \Redis
     */
    protected $redis;

    public function setUp()
    {
        $config = require dirname(__DIR__).'/config.php';
        Yii::$container->setSingleton(Redis::class, $config);

        $this->redis = Yii::$container->get(Redis::class);
        parent::setUp();
    }

    public function testSet()
    {
        $this->assertTrue($this->redis->set("a", 1));
    }

    public function testGet()
    {
        $this->assertEquals($this->redis->get("a"), 1);
    }

    public function testHSet()
    {
        $this->assertNotFalse($this->redis->hSet("ha", "b", 3));
        $this->assertNotFalse($this->redis->hDel("ha", "b"), 1);
    }

    public function testSSet()
    {
        $this->assertNotFalse($this->redis->sAdd("sa", "a"));
        $arr = $this->redis->sMembers("sa");
        $this->assertTrue(count($arr) === 1);
        $this->assertEquals($arr[0], "a");
        $this->assertNotFalse($this->redis->sRem("sa", "a"));
    }

}