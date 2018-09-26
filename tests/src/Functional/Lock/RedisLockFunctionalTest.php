<?php

namespace Drupal\Tests\redis\Functional\Lock;

use Drupal\Component\Utility\OpCodeCache;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\Tests\system\Functional\Lock\LockFunctionalTest;
use Drupal\Tests\redis\Traits\RedisTestInterfaceTrait;

/**
 * Confirm locking works between two separate requests.
 *
 * @group redis
 */
class RedisLockFunctionalTest extends LockFunctionalTest {

  use RedisTestInterfaceTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['redis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Write the containers_yaml update by hand, since writeSettings() doesn't
    // support this syntax.
    $filename = $this->siteDirectory . '/settings.php';
    chmod($filename, 0666);
    $contents = file_get_contents($filename);
    $redis_interface = self::getRedisInterfaceEnv();
    $module_path = drupal_get_path('module', 'redis');
    $contents .= "\n\n" . "\$settings['container_yamls'][] = '$module_path/example.services.yml';";
    $contents .= "\n\n" . '$settings["redis.connection"]["interface"] = \'' . $redis_interface . '\';';
    file_put_contents($filename, $contents);
    $settings = Settings::getAll();
    $settings['container_yamls'][] = $module_path . '/example.services.yml';
    $settings['redis.connection']['interface'] = $redis_interface;
    new Settings($settings);
    OpCodeCache::invalidate(DRUPAL_ROOT . '/' . $filename);

    $this->rebuildContainer();

    // Get database schema.
    $db_schema = Database::getConnection()->schema();
    // Make sure that the semaphore table isn't used.
    $db_schema->dropTable('semaphore');
  }

  /**
   * {@inheritdoc}
   */
  public function testLockAcquire() {
    $redis_interface = self::getRedisInterfaceEnv();
    $this->assertInstanceOf('\Drupal\redis\Lock\\' . $redis_interface, $this->container->get('lock'));

    parent::testLockAcquire();
  }

  /**
   * {@inheritdoc}
   */
  public function testPersistentLock() {
    $redis_interface = self::getRedisInterfaceEnv();
    $this->assertInstanceOf('\Drupal\redis\PersistentLock\\' . $redis_interface, $this->container->get('lock.persistent'));

    parent::testPersistentLock();
  }

  /**
   * Tests lock availability.
   *
   * Core tests don't cover the case when locks are checked for availability
   * before being acquired.
   *
   * @todo While the core tests are fixed, move this to a kernel.
   */
  public function testLockMayBeAvailable() {
    $lock_name = 'redis_test_lock';
    $lock_service = $this->container->get('lock');
    // If a lock has not been acquired yet, it should be available.
    $this->assertTrue($lock_service->lockMayBeAvailable($lock_name));
    $lock_service->acquire($lock_name);
    $this->assertFalse($lock_service->lockMayBeAvailable($lock_name));
    $lock_service->release($lock_name);
    $this->assertTrue($lock_service->lockMayBeAvailable($lock_name));
  }
}
