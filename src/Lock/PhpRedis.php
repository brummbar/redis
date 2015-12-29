<?php

/**
 * @file
 * Contains \Drupal\redis\Lock\PhpRedis.
 */

namespace Drupal\redis\Lock;

use Drupal\Core\Lock\LockBackendAbstract;
use Drupal\redis\ClientFactory;
use Drupal\redis\RedisPrefixTrait;

/**
 * Predis lock backend implementation.
 */
class PhpRedis extends LockBackendAbstract {

  use RedisPrefixTrait;

  /**
   * @var \Redis
   */
  protected $client;

  /**
   * Creates a PHpRedis cache backend.
   */
  function __construct(ClientFactory $factory) {
    $this->client = $factory->getClient();
    // __destruct() is causing problems with garbage collections, register a
    // shutdown function instead.
    drupal_register_shutdown_function(array($this, 'releaseAll'));
  }

  /**
   * Generate a redis key name for the current lock name.
   *
   * @param string $name
   *   Lock name.
   *
   * @return string
   *   The redis key for the given lock.
   */
  protected function getKey($name) {
    return $this->getPrefix() . ':lock:' . $name;
  }

  /**
   * {@inheritdoc}
   */
  public function acquire($name, $timeout = 30.0) {
    $key    = $this->getPrefix() . ':lock:' . $name;
    $id     = $this->getLockId();

    // Insure that the timeout is at least 1 second, we cannot do otherwise with
    // Redis, this is a minor change to the function signature, but in real life
    // nobody will notice with so short duration.
    $timeout = ceil(max($timeout, 1));

    // If we already have the lock, check for his owner and attempt a new EXPIRE
    // command on it.
    if (isset($this->locks[$name])) {

      // Create a new transaction, for atomicity.
      $this->client->watch($key);

      // Global tells us we are the owner, but in real life it could have expired
      // and another process could have taken it, check that.
      if ($this->client->get($key) != $id) {
        // Explicit UNWATCH we are not going to run the MULTI/EXEC block.
        $this->client->unwatch();
        unset($this->locks[$name]);
        return FALSE;
      }

      // See https://github.com/nicolasff/phpredis#watch-unwatch
      // MULTI and other commands can fail, so we can't chain calls.
      if (FALSE !== ($result = $this->client->multi())) {
        $this->client->setex($key, $timeout, $id);
        $result = $this->client->exec();
      }

      // Did it broke?
      if (FALSE === $result) {
        unset($this->locks[$name]);
        // Explicit transaction release which also frees the WATCH'ed key.
        $this->client->discard();
        return FALSE;
      }

      return ($this->locks[$name] = TRUE);
    }
    else {
      $this->client->watch($key);
      $owner = $this->client->get($key);

      // If the $key is set they lock is not available
      if (!empty($owner) && $id != $owner) {
        $this->client->unwatch();
        return FALSE;
      }

      // See https://github.com/nicolasff/phpredis#watch-unwatch
      // MULTI and other commands can fail, so we can't chain calls.
      if (FALSE !== ($result = $this->client->multi())) {
        $this->client->setex($key, $timeout, $id);
        $result->exec();
      }

      // If another client modified the $key value, transaction will be discarded
      // $result will be set to FALSE. This means atomicity have been broken and
      // the other client took the lock instead of us.
      if (FALSE === $result) {
        // Explicit transaction release which also frees the WATCH'ed key.
        $this->client->discard();
        return FALSE;
      }

      // Register the lock.
      return ($this->locks[$name] = TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lockMayBeAvailable($name) {
    $key    = $this->getKey($name);
    $id     = $this->getLockId();

    $value = $this->client->get($key);

    return FALSE === $value || $id == $value;
  }

  /**
   * {@inheritdoc}
   */
  public function release($name) {
    $key    = $this->getKey($name);
    $id     = $this->getLockId();

    unset($this->locks[$name]);

    // Ensure the lock deletion is an atomic transaction. If another thread
    // manages to removes all lock, we can not alter it anymore else we will
    // release the lock for the other thread and cause race conditions.
    $this->client->watch($key);

    if ($this->client->get($key) == $id) {
      $this->client->multi();
      $this->client->delete($key);
      $this->client->exec();
    }
    else {
      $this->client->unwatch();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function releaseAll($lock_id = NULL) {
    if (!isset($lock_id) && empty($this->locks)) {
      return;
    }

    $id     = isset($lock_id) ? $lock_id : $this->getLockId();

    // We can afford to deal with a slow algorithm here, this should not happen
    // on normal run because we should have removed manually all our locks.
    foreach ($this->locks as $name => $foo) {
      $key   = $this->getKey($name);
      $owner = $this->client->get($key);

      if (empty($owner) || $owner == $id) {
        $this->client->delete($key);
      }
    }
  }
}
