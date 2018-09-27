<?php

namespace Drupal\Tests\redis\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\Core\Lock\LockTest;
use Drupal\Tests\redis\Traits\RedisTestInterfaceTrait;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the Redis non-persistent lock backend.
 *
 * Extends the core test to include test coverage for lockMayBeAvailable()
 * method invoked on a non-yet acquired lock.
 *
 * @group redis
 */
class RedisLockTest extends LockTest {

  use RedisTestInterfaceTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'redis',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    self::setUpSettings();
    parent::register($container);

    $container->register('lock', 'Drupal\Core\Lock\LockBackendInterface')
      ->setFactory([new Reference('redis.lock.factory'), 'get']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->lock = $this->container->get('lock');
  }

  /**
   * {@inheritdoc}
   */
  public function testBackendLockRelease() {
    $redis_interface = self::getRedisInterfaceEnv();
    $this->assertInstanceOf('\Drupal\redis\Lock\\' . $redis_interface, $this->lock);

    // Verify that a lock that has never been acquired is marked as available.
    $this->assertTrue($this->lock->lockMayBeAvailable('lock_a'));

    parent::testBackendLockRelease();
  }

}
