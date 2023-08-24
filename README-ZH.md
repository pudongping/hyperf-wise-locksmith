 [要求](#要求) | [安装](#安装) | [分支或者标签](#分支或者标签) | [快速开始](#快速开始) | [注意](#注意) | [文档](#文档) | [贡献](#贡献) | [License](#License)

<h1 align="center">hyperf-wise-locksmith</h1>

<p align="center">

[![Latest Stable Version](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/v/stable.svg)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)
[![Total Downloads](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/downloads.svg)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)
[![Latest Unstable Version](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/v/unstable.svg)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)
[![Minimum PHP Version](http://img.shields.io/badge/php-%3E%3D%207.2-8892BF.svg)](https://php.net/)
[![Packagist](https://img.shields.io/packagist/v/pudongping/hyperf-wise-locksmith.svg)](https://github.com/pudongping/hyperf-wise-locksmith)
[![License](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/license)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)

</p>

[English](./README.md) | 中文

:lock: 适配 hyperf 框架的互斥锁库，用于在高并发场景下提供 PHP 代码的有序执行。 此库基于 [pudongping/wise-locksmith](https://github.com/pudongping/wise-locksmith) 库构建。

## 要求

- PHP >= 8.0
- hyperf ~3.0.0

## 安装

```shell
composer require pudongping/hyperf-wise-locksmith:^2.0 -vvv
```

## 分支或者标签

### 分支

- **2.2:** For hyperf 2.2
- **3.0:** For hyperf 3.0

### 标签

- **1.0.x:** For hyperf 2.2
- **2.0.x:** For hyperf 3.0

## 快速开始

下面，将给出一个高并发场景下扣减用户余额的示例，用于演示此库的作用以及使用。

创建 `app\Controller\BalanceController.php` 文件，写入如下代码： 

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\AutoController;
use App\Services\AccountBalanceService;
use Hyperf\Coroutine\Parallel;
use function \Hyperf\Support\make;

#[AutoController]
class BalanceController extends AbstractController
{

    // curl '127.0.0.1:9511/balance/consumer?type=noMutex'
    public function consumer()
    {
        $type = $this->request->input('type', 'noMutex');
        $amount = (float)$this->request->input('amount', 1);

        $parallel = new Parallel();
        $balance = make(AccountBalanceService::class);

        // 模拟 20 个并发
        for ($i = 1; $i <= 20; $i++) {
            $parallel->add(function () use ($balance, $i, $type, $amount) {
                return $balance->runLock($i, $type, $amount);
            }, $i);
        }

        $result = $parallel->wait();

        return $this->response->json($result);
    }

}
```

然后，再创建 `app\Services\AccountBalanceService.php` 文件，写入如下代码

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Hyperf\Contract\StdoutLoggerInterface;
use Pudongping\HyperfWiseLocksmith\Locker;
use Pudongping\WiseLocksmith\Exception\WiseLocksmithException;
use Pudongping\WiseLocksmith\Support\Swoole\SwooleEngine;
use Throwable;

class AccountBalanceService
{

    /**
     * 用户账户初始余额
     *
     * @var float|int
     */
    private float|int $balance = 10;

    public function __construct(
        private StdoutLoggerInterface $logger,
        private Locker                $locker
    ) {
        $this->locker->setLogger($logger);
    }

    private function deductBalance(float|int $amount)
    {
        if ($this->balance >= $amount) {
            // 模拟业务处理耗时
            usleep(500 * 1000);
            $this->balance -= $amount;
        }

        return $this->balance;
    }

    /**
     * @return float
     */
    private function getBalance(): float
    {
        return $this->balance;
    }

    public function runLock(int $i, string $type, float $amount)
    {
        try {
            $start = microtime(true);

            switch ($type) {
                case 'flock':
                    $this->flock($amount);
                    break;
                case 'redisLock':
                    $this->redisLock($amount);
                    break;
                case 'redLock':
                    $this->redLock($amount);
                    break;
                case 'channelLock':
                    $this->channelLock($amount);
                    break;
                case 'noMutex':
                default:
                    $this->deductBalance($amount);
                    break;
            }

            $balance = $this->getBalance();
            $id = SwooleEngine::id();
            $cost = microtime(true) - $start;
            $this->logger->notice('[{type} {cost}] ==> [{i}<=>{id}] ==> 当前用户的余额为：{balance}', compact('type', 'i', 'balance', 'id', 'cost'));

            return $balance;
        } catch (WiseLocksmithException|Throwable $e) {
            return sprintf('Err Msg: %s ====> %s', $e, $e->getPrevious());
        }
    }

    private function flock(float $amount)
    {
        $path = BASE_PATH . '/runtime/alex.lock.cache';
        $fileHandler = fopen($path, 'a+');
        // fwrite($fileHandler, sprintf("%s - %s \r\n", 'Locked', microtime()));

        $res = $this->locker->flock($fileHandler, function () use ($amount) {
            return $this->deductBalance($amount);
        });
        return $res;
    }

    private function redisLock(float $amount)
    {
        $res = $this->locker->redisLock('redisLock', function () use ($amount) {
            return $this->deductBalance($amount);
        }, 10);
        return $res;
    }

    private function redLock(float $amount)
    {
        $res = $this->locker->redLock('redLock', function () use ($amount) {
            return $this->deductBalance($amount);
        }, 10);
        return $res;
    }

    private function channelLock(float $amount)
    {
        $res = $this->locker->channelLock('channelLock', function () use ($amount) {
            return $this->deductBalance($amount);
        });
        return $res;
    }

}
```

当我们访问 `/balance/consumer?type=noMutex` 地址时，我们可以看到用户的余额会被扣成负数，这明显不符合逻辑。
然而当我们访问下面几个地址时，我们可以看到用户余额不会被扣成负数，则说明很好的保护了竞态下的共享资源的准确性。

- `/balance/consumer?type=flock` ：文件锁
- `/balance/consumer?type=redisLock` ：分布式锁
- `/balance/consumer?type=redLock` ：红锁
- `/balance/consumer?type=channelLock` ：协程级别的互斥锁

## 注意

关于使用到 `redisLock` 和 `redLock` 时： 

- 使用 `redisLock` 默认采用的 `config/autoload/redis.php` 配置文件中的第一个 `key` 配置 redis 实例（即 **default**）。可按需传入第 4 个参数 `string|null $redisPoolName` 进行重新指定。  
- 使用 `redLock` 默认采用的 `config/autoload/redis.php` 配置文件中的所有 `key` 对应的配置 redis 实例。可按需传入第 4 个参数 `?array $redisPoolNames = null` 进行重新指定。

## 文档

详细文档可见 [pudongping/wise-locksmith](https://github.com/pudongping/wise-locksmith)。

## 贡献

Bug 报告(或者小补丁)可以通过 [issue tracker](https://github.com/pudongping/hyperf-wise-locksmith/issues) 提交。对于大量的补丁，最好对库进行 Fork 并提交 Pull Request。

## License

MIT, see [LICENSE file](LICENSE).
