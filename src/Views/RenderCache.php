<?php

declare(strict_types=1);

namespace Core\Views;

use Latte\Engine;
use Latte\Runtime\FilterExecutor;
use Latte\Runtime\FunctionExecutor;
use Swoole\Coroutine\Channel;

class RenderCache extends Engine
{
  /**
   * @var array<string, string> 编译后的模板缓存
   */
  private static array $compiledCache = [];

  /**
   * @var array<string, Channel> 编译锁通道映射
   */
  private static array $compileLocks = [];

  /**
   * @var \stdClass 模板提供者
   */
  private \stdClass $providers;

  /**
   * @var FilterExecutor 过滤器执行器
   */
  private FilterExecutor $filters;

  /**
   * @var FunctionExecutor 函数执行器
   */
  private FunctionExecutor $functions;

  public function __construct()
  {
    // 初始化基本组件
    $this->filters = new FilterExecutor;
    $this->functions = new FunctionExecutor;
    $this->providers = new \stdClass;

    // 调用父类构造函数
    parent::__construct();

    // 禁用文件缓存
    $this->setTempDirectory(null);
  }

  /**
   * 重写createTemplate方法,确保类加载顺序正确
   */
  public function createTemplate(string $name, array $params = [], $clearCache = true): \Latte\Runtime\Template
  {
    // 获取或创建编译锁
    if (!isset(self::$compileLocks[$name])) {
      self::$compileLocks[$name] = new Channel(1);
      self::$compileLocks[$name]->push(true);
    }

    // 获取锁
    self::$compileLocks[$name]->pop();

    try {
      // 先获取类名，确保和编译时使用相同的类名
      $class = parent::getTemplateClass($name);

      // 如果类不存在，需要编译和加载
      if (!class_exists($class, false)) {
        // 检查缓存
        if (isset(self::$compiledCache[$name])) {
          $compiled = self::$compiledCache[$name];
        } else {
          // 编译模板
          $compiled = $this->compile($name);
          // 替换编译后代码中的类名，确保一致性
          if (preg_match('/class\s+(\w+)/', $compiled, $matches)) {
            $compiledClass = $matches[1];
            if ($compiledClass !== $class) {
              $compiled = str_replace($compiledClass, $class, $compiled);
            }
          }
          self::$compiledCache[$name] = $compiled;
        }

        // 执行编译后的代码
        if (@eval(substr($compiled, 5)) === false) {
          throw new \RuntimeException('Error in template: ' . error_get_last()['message']);
        }
      }

      // 设置函数提供者
      $this->providers->fn = $this->functions;

      // 创建模板实例
      return new $class(
        $this,
        $params,
        $this->filters,
        $this->providers,
        $name,
      );
    } finally {
      // 释放锁
      self::$compileLocks[$name]->push(true);
    }
  }

  /**
   * 清除编译缓存
   */
  public function clearCache(): void
  {
    self::$compiledCache = [];
  }

  /**
   * 重写addFilter方法，确保filters属性正确初始化
   */
  public function addFilter(string $name, callable $callback): static
  {
    $this->filters->add($name, $callback);
    return $this;
  }

  /**
   * 重写addFunction方法，确保functions属性正确初始化
   */
  public function addFunction(string $name, callable $callback): static
  {
    $this->functions->add($name, $callback);
    return $this;
  }

  /**
   * 重写addProvider方法，确保providers属性正确初始化
   */
  public function addProvider(string $name, mixed $provider): static
  {
    $this->providers->$name = $provider;
    return $this;
  }
}
