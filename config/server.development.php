<?php

return [
    'database' => [
        'adapter'  => 'Mysql', /* Possible Values: Mysql, Postgres, Sqlite */
        'host'     => '127.0.0.1',
        'username' => 'root',
        'password' => 'root',
        'dbname'   => 'dev_josefa',
        'charset'  => 'utf8',
    ],
    'log_database' => [
        'adapter'  => 'Mysql', /* Possible Values: Mysql, Postgres, Sqlite */
        'host'     => '127.0.0.1',
        'username' => 'root',
        'password' => 'root',
        'dbname'   => 'dev_josefa_log',
        'charset'  => 'utf8',
    ],
    'authentication' => [
        'secret'          => 'your secret key to SIGN token', // This will sign the token. (still insecure)
        'encryption_key'  => 'Your ultra secret key to ENCRYPT the token', // Secure token with an ultra password
        'expiration_time' => 86400 * 7, // One week till token expires
        'iss'             => "myproject", // Token issuer eg. www.myproject.com
        'aud'             => "myproject", // Token audience eg. www.myproject.com
    ],
    'sap' => [
        'login_wsdl' => 'http://192.168.10.102/B1WS/WebReferences/LoginService.wsdl',
        'order_wsdl' => 'http://192.168.10.102/B1WS/WebReferences/OrdersService.wsdl',
        'db_name'    => 'PRUEBAS_IGB_WMS'
    ]
];
