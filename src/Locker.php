<?php

namespace Pudongping\HyperfWiseLocksmith;

use RuntimeException;
use Psr\Log\LoggerInterface;
use Hyperf\Redis\RedisProxy;
use Hyperf\Redis\RedisFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Contract\ConfigInterface;
use Pudongping\WiseLocksmith\Locker as BaseLocker;
use Pudongping\WiseLocksmith\Lock\File\Flock;
use Pudongping\WiseLocksmith\Contract\LoopInterface;

class Locker
{

    /**
     * @return BaseLocker
     */
    protected function locksmith(): BaseLocker
    {
        return new BaseLocker();
    }

    protected function getRedisPoolNames(): array
    {
        if (! ApplicationContext::hasContainer()) {
            throw new RuntimeException('The application context lacks the container.');
        }

        $container = ApplicationContext::getContainer();

        if (! $container->has(ConfigInterface::class)) {
            throw new RuntimeException('ConfigInterface is missing in container.');
        }

        $configs = $container->get(ConfigInterface::class)->get('redis', []);
        if (! $configs) {
            throw new RuntimeException('No redis configuration information exists.');
        }

        return array_keys($configs);
    }

    protected function getRedisInstance(string $poolName): RedisProxy
    {
        if (! ApplicationContext::hasContainer()) {
            throw new RuntimeException('The application context lacks the container.');
        }

        $container = ApplicationContext::getContainer();

        if (! $container->has(RedisFactory::class)) {
            throw new RuntimeException('RedisFactory is missing in container.');
        }

        return $container->get(RedisFactory::class)->get($poolName);
    }

    /**
     * @param LoggerInterface|null $logger
     * @return $this
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function setLogger(?LoggerInterface $logger): Locker
    {
        if (is_null($logger)) {
            if (ApplicationContext::hasContainer()) {
                $container = ApplicationContext::getContainer();
                if ($container->has(StdoutLoggerInterface::class)) {
                    $logger = $container->get(StdoutLoggerInterface::class);
                }
            }
        }

        $this->locksmith()->setLogger($logger);

        return $this;
    }

    /**
     * @param resource $fileHandle 文件资源
     * @param callable $businessLogic 业务逻辑代码
     * @param float $timeoutSeconds 超时时间，单位：秒（支持浮点型，如 1.5 表示 1s+500ms，-1 表示永不超时）
     * @param LoopInterface|null $loop 循环器
     * @return mixed
     * @throws \Pudongping\WiseLocksmith\Exception\InvalidArgumentException
     * @throws \Pudongping\WiseLocksmith\Exception\MutexException
     * @throws \Throwable
     */
    public function flock($fileHandle, callable $businessLogic, float $timeoutSeconds = Flock::INFINITE_TIMEOUT, ?LoopInterface $loop = null)
    {
        return $this->locksmith()->flock($fileHandle, $businessLogic, $timeoutSeconds, $loop);
    }

    /**
     * @param string $key 锁的名称
     * @param callable $businessLogic 业务逻辑代码
     * @param float $timeoutSeconds 超时时间，单位：秒（支持浮点型，如 1.5 表示 1s+500ms，-1 表示永不超时）
     * @param bool $isPrintLog 是否打印日志，true 打印，false 不打印
     * @return null
     */
    public function channelLock(string $key, callable $businessLogic, float $timeoutSeconds = -1, bool $isPrintLog = false)
    {
        return $this->locksmith()->channelLock($key, $businessLogic, $timeoutSeconds, $isPrintLog);
    }

    /**
     * @param string $key 锁的名称
     * @param callable $businessLogic 业务逻辑代码
     * @param float $timeoutSeconds 超时时间，单位：秒（支持浮点型，如 1.5 表示 1s+500ms）
     * @param string|null $redisPoolName `config/autoload/redis.php` 配置文件中的 `key` 名称，如果有多个时，默认取的第一个
     * @param string|null $token 锁的值
     * @param LoopInterface|null $loop 循环器
     * @return mixed
     * @throws \Pudongping\WiseLocksmith\Exception\MutexException
     * @throws \Throwable
     */
    public function redisLock(
        string         $key,
        callable       $businessLogic,
        float          $timeoutSeconds = 5,
        ?string        $redisPoolName = null,
        ?string        $token = null,
        ?LoopInterface $loop = null
    ) {
        if (is_null($redisPoolName)) {
            $redisPoolName = $this->getRedisPoolNames()[0];
        }
        $redis = $this->getRedisInstance($redisPoolName);

        return $this->locksmith()->redisLock($redis, $key, $businessLogic, $timeoutSeconds, $token, $loop);
    }

    /**
     * @param string $key 锁的名称
     * @param callable $businessLogic 业务逻辑代码
     * @param float $timeoutSeconds 超时时间，单位：秒（支持浮点型，如 1.5 表示 1s+500ms）
     * @param array|null $redisPoolNames `config/autoload/redis.php` 配置文件中的 `key` 名称，默认取的全部 `key` 作为 redis 集群节点。可指定具体的 `key`
     * @param string|null $token 锁的值
     * @param LoopInterface|null $loop 循环器
     * @return mixed
     * @throws \Pudongping\WiseLocksmith\Exception\MutexException
     * @throws \Throwable
     */
    public function redLock(
        string         $key,
        callable       $businessLogic,
        float          $timeoutSeconds = 5,
        ?array         $redisPoolNames = null,
        ?string        $token = null,
        ?LoopInterface $loop = null
    ) {
        if (is_null($redisPoolNames)) {
            $redisPoolNames = $this->getRedisPoolNames();
        }

        $redisInstances = [];
        foreach ($redisPoolNames as $redisPoolName) {
            $redisInstances[] = $this->getRedisInstance($redisPoolName);
        }

        return $this->locksmith()->redLock($redisInstances, $key, $businessLogic, $timeoutSeconds, $token, $loop);
    }

}
