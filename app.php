<?php
/**
* Local variables
* @var \Phalcon\Mvc\Micro $app
*/

use Phalcon\Mvc\Controller;
use Phalcon\Mvc\Micro\Collection as MicroCollection;

/**
* ACL checks
*/
$app->before(new AccessMiddleware());

/**
* Insert your Routes below
*/

/**
* Index
*/
$index = new MicroCollection();
$index->setHandler(new IndexController());
// Gets index
$index->get('/', 'index');
// Authenticates a user
$index->post('/authenticate', 'login');
// logout
$index->get('/logout', 'logout');
// Adds index routes to $app
$app->mount($index);

/**
* Profile
*/
$profile = new MicroCollection();
$profile->setHandler(new ProfileController());
$profile->setPrefix('/profile');
// Gets profile
$profile->get('/', 'index');
// // Updates user profile
$profile->patch('/update', 'update');
// Changes user password
$profile->patch('/change-password', 'changePassword');
// Adds profile routes to $app
$app->mount($profile);

/**
* Users
*/
$users = new MicroCollection();
$users->setHandler(new UsersController());
$users->setPrefix('/users');
// Gets all users
$users->get('/', 'index');
// Creates a new user
$users->post('/create', 'create');
// Gets user based on unique key
$users->get('/get/{id}', 'get');
// Updates user based on unique key
$users->patch('/update/{id}', 'update');
// Changes user password
$users->patch('/change-password/{id}', 'changePassword');
// Adds users routes to $app
$app->mount($users);

/**
* Cities
*/
$cities = new MicroCollection();
$cities->setHandler(new CitiesController());
$cities->setPrefix('/cities');
// Gets cities
$cities->get('/', 'index');
// Creates a new city
$cities->post('/create', 'create');
// Gets city based on unique key
$cities->get('/get/{id}', 'get');
// Updates city based on unique key
$cities->patch('/update/{id}', 'update');
// Deletes city based on unique key
$cities->delete('/delete/{id}', 'delete');
// Adds cities routes to $app
$app->mount($cities);

/**
* SAP - Utilidades y metodos relacionados con la concexion a sap,
* esto se puede mejorar en un futuro
*/
$sap = new MicroCollection();
$sap->setHandler(new SapController());
$sap->setPrefix('/sap');
/**
 * Punto de entrada al api de SAP, normalmente este metodo
 * devolveria un get de todos los datos ej si fueran ciudades
 * todas las ciudades con algunos parametros habilitados como limit-order-offset
 * tal como los ejemplos de mas arriba, pero como es un API un poco mas enfocada
 * a metodos de utilidades no lo uso de esa manera
 */
$sap->get('/', 'index');
// Crea ordenes nuevas en sap mediante SOAP
$sap->post('/order', 'order');
// Crea ordenes nuevas en sap mediante SOAP
$sap->post('/order_v2', 'order_v2');
// Motorzone Crea ordenes nuevas en sap mediante SOAP
$sap->post('/order_motorzone', 'order_motorzone');
// Lee un csv con datos de cartera
$sap->post('/cartera', 'cartera');
// Se encarga de administrar las peticiones para cuentas nuevas
$sap->post('/request_account', 'request_account');
// Adds sap routes to $app
$app->mount($sap);

/**
* Not found handler
*/
$app->notFound(function () use ($app) {
    $app->response->setStatusCode(404, "Not Found")->sendHeaders();
    $app->response->setContentType('application/json', 'UTF-8');
    $app->response->setJsonContent(array(
        "status" => "error",
        "code" => "404",
        "messages" => "URL Not found",
    ));
    $app->response->send();
});

/**
* Error handler
*/
$app->error(
    function ($exception) {
        echo "An error has occurred";
    }
);
