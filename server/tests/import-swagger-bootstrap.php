<?php

/**
 * ImportSwaggerTest 引导文件
 *
 * 仅加载 Composer autoload，不初始化数据库。
 * ImportSwaggerController 的单元测试通过反射调用，无需数据库连接。
 */

require dirname(__DIR__) . '/vendor/autoload.php';
