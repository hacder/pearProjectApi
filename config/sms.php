<?php

return [
    // HTTP 请求的超时时间（秒）
    'timeout' => 5.0,

    // 默认发送配置
    'default' => [
        // 网关调用策略，默认：顺序调用
        'strategy' => \Overtrue\EasySms\Strategies\OrderStrategy::class,

        // 默认可用的发送网关
        'gateways' => [
            'submail'
        ],
    ],
    // 可用的网关配置
    'gateways' => [
        'errorlog' => [
            'file' => '/tmp/easy-sms.log',
        ],
        'submail' => [
            'app_id' => '29150',
            'app_key' => '29a09968886d7ab43f035a598bc2755d',
            'project' => '', // 默认 project，可在发送时 data 中指定
        ],
    ],
];
