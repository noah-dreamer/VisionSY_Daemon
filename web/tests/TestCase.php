<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 保险丝：拒绝在非一次性数据库上运行测试。
     *
     * 背景：tests/Feature 下的测试类全部 use RefreshDatabase，它在整轮测试开始时
     * 执行一次 `migrate:fresh`（drop 所有表并重建，且不 seed）。若测试连到开发库或
     * 生产库，数据会被清空，表现为"邮箱或密码不正确"（users 表存在但为空）。
     *
     * 时序保证：Illuminate\Foundation\Testing\TestCase::setUp() 先调用
     * refreshApplication()，再调用 setUpTraits()（后者才触发 RefreshDatabase 的
     * migrate:fresh）。因此在这里拦截，可以保证一行数据都不会丢。
     *
     * ⚠️ 给未来的人类与 AI Agent：
     *    不要为了"让测试跑起来"而删除、注释或放宽本方法。
     *    测试失败时正确的做法是：node deploy/docker/test.mjs
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "拒绝在非一次性数据库上运行测试：connection={$connection}, database={$database}。".PHP_EOL
                .'测试使用 RefreshDatabase，会执行 migrate:fresh 清空整个数据库。'.PHP_EOL
                .'请改用：node deploy/docker/test.mjs      （隔离库 visionsy_test）'
            );
        }
    }
}
