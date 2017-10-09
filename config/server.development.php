<?php

return [
    'database' => [
        'adapter'  => 'Mysql', /* Possible Values: Mysql, Postgres, Sqlite */
        'host'     => '127.0.0.1',
        'username' => 'root',
        'password' => 'Webmaster2017',
        'dbname'   => 'dev_josefa',
        'charset'  => 'utf8',
    ],
    'log_database' => [
        'adapter'  => 'Mysql', /* Possible Values: Mysql, Postgres, Sqlite */
        'host'     => '127.0.0.1',
        'username' => 'root',
        'password' => 'Webmaster2017',
        'dbname'   => 'dev_josefa_log',
        'charset'  => 'utf8',
    ],
    'authentication' => [
        'secret'          => 'que me quiebren la nalga', // This will sign the token. (still insecure)
        'encryption_key'  => 'B<~o{X?-P~`A<iK7u%JtS4PpHR)BJtrHx9AczVmYn*a.q4Q+""K*#G1/9]%RYq+', // Secure token with an ultra password
        'expiration_time' => 86400 * 7, // One week till token expires
        'iss'             => "myproject", // Token issuer eg. www.myproject.com
        'aud'             => "myproject", // Token audience eg. www.myproject.com
    ],
    'sap' => [
        'login_wsdl' => 'http://b1ws.igbcolombia.com/B1WS/WebReferences/LoginService.wsdl',
        'order_wsdl' => 'http://b1ws.igbcolombia.com/B1WS/WebReferences/OrdersService.wsdl',
        'db_name'    => 'MERCHANDISING'
    ]
];
