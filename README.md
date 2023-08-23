 [Requirements](#requirements) | [Installation](#installation) | [Branches or tags](#branches-or-tags) | [Quickstart](#quickstart) | [Note](#note) | [Documentation](#documentation) | [Contributing](#contributing) | [License](#license)

<h1 align="center">hyperf-wise-locksmith</h1>

<p align="center">

[![Latest Stable Version](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/v/stable.svg)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)
[![Total Downloads](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/downloads.svg)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)
[![Latest Unstable Version](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/v/unstable.svg)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)
[![Minimum PHP Version](http://img.shields.io/badge/php-%3E%3D%207.2-8892BF.svg)](https://php.net/)
[![Packagist](https://img.shields.io/packagist/v/pudongping/hyperf-wise-locksmith.svg)](https://github.com/pudongping/hyperf-wise-locksmith)
[![License](https://poser.pugx.org/pudongping/hyperf-wise-locksmith/license)](https://packagist.org/packages/pudongping/hyperf-wise-locksmith)

</p>

English | [中文](./README-ZH.md)

:lock: A mutex library provider for the Hyperf framework, designed to enable serialized execution of PHP code in high-concurrency scenarios. This library is based on [pudongping/wise-locksmith](https://github.com/pudongping/wise-locksmith).

## Requirements

- PHP >= 7.2
- hyperf ~2.2.0

## Installation

```shell
composer require pudongping/hyperf-wise-locksmith:^1.0 -vvv
```

## Branches or tags

### Branch

- **2.2:** For hyperf 2.2
- **3.0:** For hyperf 3.0

### Tag

- **1.0.x:** For hyperf 2.2
- **2.0.x:** For hyperf 3.0

## Quickstart

Below, an example of deducting user balances in a high-concurrency scenario will be provided to demonstrate the functionality and usage of this library.

Create the `app\Controller\BalanceController.php` file and write the following code:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\AutoController;
use Hyperf\Utils\Parallel;
use App\Services\AccountBalanceService;

/**
 * @AutoController()
 */
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

Next, create the `app\Services\AccountBalanceService.php` file and write the following code:

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

    private $logger;

    private $locker;

    /**
     * 用户账户初始余额
     *
     * @var float
     */
    private $balance = 10;

    public function __construct(StdoutLoggerInterface $logger, Locker $locker)
    {
        $this->logger = $logger;
        $this->locker = $locker;

        $this->locker->setLogger($logger);
    }

    private function deductBalance(float $amount)
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

When we access the `/balance/consumer?type=noMutex` URL, we can observe that the user's balance goes negative, which is clearly illogical. However, when we visit the following URLs, we can see that the user's balance is not going negative, demonstrating effective protection of the accuracy of shared resources in a race condition.

- `/balance/consumer?type=flock` : File lock
- `/balance/consumer?type=redisLock` : Distributed lock
- `/balance/consumer?type=redLock` : Redlock
- `/balance/consumer?type=channelLock` : Coroutine-level mutex

## Note

Regarding the use of `redisLock` and `redLock`:

- When using `redisLock`, it defaults to using the first `key` configuration in the `config/autoload/redis.php` configuration file, which corresponds to the **default** Redis instance. You can optionally pass the fourth parameter `string|null $redisPoolName` to re-specify a different Redis instance as needed.
- When using `redLock`, it defaults to using all the `key` configurations in the `config/autoload/redis.php` configuration file. You can optionally pass the fourth parameter `?array $redisPoolNames = null` to re-specify different Redis instances as needed.

## Documentation

You can find detailed documentation for [pudongping/wise-locksmith](https://github.com/pudongping/wise-locksmith).

## Contributing

Bug reports (and small patches) can be submitted via the [issue tracker](https://github.com/pudongping/hyperf-wise-locksmith/issues). Forking the repository and submitting a Pull Request is preferred for substantial patches.

## License

MIT, see [LICENSE file](LICENSE).
