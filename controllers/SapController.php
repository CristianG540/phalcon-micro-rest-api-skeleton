<?php

define('DS', DIRECTORY_SEPARATOR);

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\CouchDBHandler;

use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\Statement;

use PHPMailer\PHPMailer\PHPMailer;

class SapController extends ControllerBase
{
    /**
     * Url del wsdl del servicio para loguearse y desloguearse de sap
     */
    private $_loginService;
    /**
     * Url del wsdl del servicio para enviar las ordenes a sap
     */
    private $_ordersService;
    /**
     * El id de la sesion que me da el sap, este se usa para los pedidos y para el logout
     */
    private $_sessionId = '';
    /**
     * Variable donde guardo la instancia de monolog para hacer el log y debug de los datos
     * @var object
     */
    private $_log;

    public function __construct(){

        $this->_log = new Logger('josefaAPI');
        $this->_log->pushHandler(new StreamHandler(__DIR__.'/../logs/info.log', Logger::DEBUG));

        Monolog\Logger::setTimezone(new \DateTimeZone('America/Bogota'));

        //$this->_loginService = new nusoap_client($this->sapConfig['login_wsdl'], true ); /* desarrollo */
        //$this->_loginService = new nusoap_client($this->sapConfig['login_wsdl'], true, FALSE, FALSE, FALSE, FALSE, 0, 30, 'LoginServiceSoap12'); /* desarrollo */
        $this->_loginService = new nusoap_client($this->sapConfig['login_wsdl'], true, FALSE, FALSE, FALSE, FALSE, 0, 30, 'LoginServiceSoap');
        $this->_loginService->setDebugLevel(0);

        //$this->_ordersService = new nusoap_client($this->sapConfig['order_wsdl'], true, FALSE, FALSE, FALSE, FALSE, 0, 30, 'OrdersServiceSoap12'); /* desarrollo */
        $this->_ordersService = new nusoap_client($this->sapConfig['order_wsdl'], true, FALSE, FALSE, FALSE, FALSE, 0, 30, 'OrdersServiceSoap');
        $this->_ordersService->setDebugLevel(0);
    }

    /**
     * Punto de entrada del controlador
     */
    public function index()
    {

    	// Verifies if is get request
        $this->initializeGet();
        //$this->_log->info("systems online");

    	$this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', ["info"=>"systems online"]);
    }

    /**
     * Este metodo es que se loguea en sap y recupera el id de la sesion
     */
    private function _login($bd){

        $error  = $this->_loginService->getError();
        if(!$error){
            $params = [
                'DatabaseServer'  => '192.168.10.102', //string
                'DatabaseName'    => $bd, //string
                'DatabaseType'    => 'dst_MSSQL2012', //DatabaseType
                'CompanyUsername' => 'manager', //string
                'CompanyPassword' => 'Pa$$w0rd', //string
                'Language'        => 'ln_Spanish', //Language
                'LicenseServer'   => '192.168.10.102:30000' //string
            ];
            $soapRes = $this->_loginService->call('Login', $params);
            $error  = $this->_loginService->getError();
            if($error){
               $this->_log->error('Error en el login SAP: '. json_encode($error) );
               $this->buildErrorResponse(403, 'common.SAP_ERROR_LOGIN', $error);
            }
            $this->_log->info("respuesta login: ".json_encode($soapRes));
            $this->_sessionId = $soapRes['SessionID'];
        }else{
            $this->_log->error('Error en el login SAP: '. json_encode($error) );
            $this->buildErrorResponse(403, 'common.SAP_ERROR_LOGIN', $error);
        }

    }

    /**
     * Este metodo es el encargado de cerrar la sesion en sap, recibe el id de la
     * sesion que se desea cerrar
     * @param  string $sessionId Id de la sesion que se desea cerrar
     */
    private function _logout($sessionId = ''){
        $id = ($sessionId) ? $sessionId : $this->_sessionId;
        $params = [
            'SessionID' => $id
        ];
        $this->_loginService->setHeaders(['MsgHeader' => $params]);
        $error = $this->_loginService->getError();
        if(!$error){
            $soapRes = $this->_loginService->call('Logout', '<Logout xmlns="LoginService" />');
            $error  = $this->_loginService->getError();
            if($error){
               $this->_log->error('Error en el logout SAP: '. json_encode($error) );
               $this->buildErrorResponse(403, 'common.SAP_ERROR_LOGOUT', $error);
            }
            $this->_log->info( "respuesta logout: ". json_encode($this->utf8ize($soapRes)) );
            return true;
        }else{
            $this->_log->error('Error en el logout SAP: '. json_encode($error) );
            $this->buildErrorResponse(403, 'common.SAP_ERROR_LOGIN', $error);
        }
    }

    /**
     * El metodo order se encarga de enviar la peticion al servicio de ordenes del webservice
     * y procesarla debidamente, tambien se encarga de enviar un correo con la confirmacion del email
     * @param  array $order     Este array recibe la orden que llega desde la pagina de prestashop
     * tiene los productos, la fecha en que se creo y el id de la orden
     * @param  string $sessionId Opcionalmente se le puede enviar el id de la sesion en sap con el que
     * se quiere procesar la orden
     * @return integer  me regresa el numero de la orden dependiendo de si se proceso o no la orden
     * si no se procesa retorna un false
     */
    public function order() {

        // Verifies if is post request
        $this->initializePost();

        //Me logue en el soap de sap
        $this->_login('IGB');
        $id = $this->_sessionId;
        $order = $this->request->getJsonRawBody();

        $order->trasportadora = $order->trasportadora ?? "No se ingreso";
        $order->nit_cliente = $order->nit_cliente ?? "No se ingreso";
        $order->asesor = $order->asesor ?? "No se ingreso";
        $order->asesor_id = $order->asesor_id ?? "No se ingreso";
        $order->user_email = $order->user_email ?? "No se ingreso";
        $order->total = $order->total ?? "No se ingreso";

        if (!isset($order->id)) {
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_RECEIVED');
        }elseif( count($order->productos) < 1 ){
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_INSERT_AT_LEAST_ONE_PRODUCT');
        }

        // Guardo un log de la orden
        $this->saveOrderLog($order);

        try {
            /**
             * busque en la bd si la orden ya se creo para el asesor indicado
             * si la orden ya existe entonces cancelo la operacion
             */
            $prevOrders = Orders::count(
                [
                    'asesor = :asesor: AND order_app_id = :order:',
                    'bind' => [
                        'asesor' => $order->asesor,
                        'order'  => $order->id
                    ]
                ]
            );
        } catch (Throwable $exc) {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED_ORDERS', ["error" => $exc->getTraceAsString()] );
            $this->_log->error('common.ERROR_SEARCH_DUPLICATED_ORDERS: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
        }

        if ( $prevOrders > 0 ) {
            $this->buildErrorResponse(400, 'common.ORDER_DUPLICATED');
        }

        /**
         * El metodo "Add" del webservice pide unos headers entonces los agrego
         */
        $paramsH = [
            'SessionID'   => $id,
            'ServiceName' => 'OrdersService'
        ];
        $this->_ordersService->setHeaders(['MsgHeader' => $paramsH]);

        /**
         * Con un reduce meto todos los productos de al array en un texto con el formato que pide el
         * webservice
         * @var array
         */
        $products = array_reduce($order->productos, function($carry, $item){
            $carry .= '<DocumentLine>'
                            . "<ItemCode>{$item->referencia}</ItemCode>"
                            . "<Quantity>{$item->cantidad}</Quantity>"
                            . "<DiscountPercent>{$item->descuento}</DiscountPercent>"
                    . '</DocumentLine>';
            return $carry;
        }, '');

        $error = $this->_ordersService->getError();
        if(!$error){
            /**
             * Armo la estructura xml que le voy a enviar al metodo Add del webservice
             */
            try {
                $soapRes = $this->_ordersService->call('Add', ''
                    . '<Add>'
                        . '<Document>'
                                . '<Confirmed>N</Confirmed>'
                                . "<CardCode>{$order->nit_cliente}</CardCode>"
                                . "<U_TRANSP>{$order->trasportadora}</U_TRANSP>"
                                . "<Comments>{$order->comentarios}</Comments>"
                                . "<DocDueDate>{$order->fecha_creacion}</DocDueDate>"
                                . "<NumAtCard>{$order->id}</NumAtCard>"
                                . '<DocumentLines>'
                                    . $products
                                . '</DocumentLines>'
                        . '</Document>'
                    . '</Add>'
                    );
                /**
                * Me trae la peticion en xml crudo de lo que se envio por soap al sap
                * algo asi como soap envelope bla, bla
                */
                $this->_log->info('Request orden es: '.$this->_ordersService->request);
                /**
                 * Lo mismo que el anterior, pero en vez de traer la peticion, trae la respuesta
                 */
                $this->_log->info('Response orden es: '.$this->_ordersService->response);
                /**
                 * Me devuelve el string con todo el debug de todos los procesos que ha hecho nusoap
                 * para activarlo hay q setear el nivel de debug a mas de 0 ejemplo: "$this->ordersService->setDebugLevel(9);"
                 */
                $this->_log->info('Debug orden es: '.$this->_ordersService->debug_str);
                // Verifico que no haya ningun error, tambien reviso si existe exactamente la ruta del array que especifico
                // si esa rut ano existe significa que algo raro paso muy posiblemente un error
                $error .= $this->_ordersService->getError();
                //Cierro la sesion en sap ya que no es necsario tenerla abierta
                $this->_logout();
            } catch (Throwable $exc) {
                $error .= $exc->getTraceAsString();
            }


            if($error || !isset($soapRes['DocumentParams']['DocEntry'])){
                $this->_log->error('Error al hacer el pedido SAP: '. json_encode($error) );
                $this->_log->error("respuesta del error pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
                $this->buildErrorResponse( 400, 'common.SAP_ERROR_ORDER', ["error" => $error, "soap_res" => $this->utf8ize($soapRes)] );
            }
            // Start a transaction
            $this->db->begin();
            try {
                $newOrder = new Orders();
                $newOrder->asesor = $order->asesor;
                $newOrder->asesor_id = $order->asesor_id;
                $newOrder->order_app_id = $order->id;
                $newOrder->productos = json_encode($order->productos);
                $newOrder->cliente = $order->nit_cliente;
                $newOrder->observaciones = $order->comentarios;

                if ($newOrder->save()) {
                    // Commit the transaction
                    $this->db->commit();

                    $this->sendEmailLog($order);

                }else{
                    $this->db->rollback();
                    // Send errors
                    $errors = array();
                    foreach ($newOrder->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }
                    $this->buildErrorResponse(400, 'common.ORDER_COULD_NOT_BE_CREATED', $errors);
                    $this->_log->error('common.ORDER_COULD_NOT_BE_CREATED: '. json_encode($this->utf8ize($soapRes)) );
                }

            } catch (Throwable $exc) {
                $this->db->rollback();
                $this->buildErrorResponse( 400, 'common.ERROR_ORDERS_MYSQLBD', ["error" => $exc->getTraceAsString()] );
                $this->_log->error('common.ERROR_ORDERS_MYSQLBD: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
            }

            $this->_log->info("respuesta del pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
            $this->buildSuccessResponse(201, 'common.CREATED_SUCCESSFULLY', $this->utf8ize($soapRes));
        }else{
            $this->_logout();
            $this->_log->error('Error al procesar la orden SAP: '. json_encode($error) );
            $this->buildErrorResponse(400, 'common.SAP_ERROR_ORDER', $error);
        }
    }

    public function order_motorzone() {

        // Verifies if is post request
        $this->initializePost();

        //Me logue en el soap de sap
        $this->_login('VARROC');
        $id = $this->_sessionId;
        $order = $this->request->getJsonRawBody();

        $order->trasportadora = $order->trasportadora ?? "No se ingreso";
        $order->nit_cliente = $order->nit_cliente ?? "No se ingreso";
        $order->asesor = $order->asesor ?? "No se ingreso";
        $order->asesor_id = $order->asesor_id ?? "No se ingreso";
        $order->user_email = $order->user_email ?? "No se ingreso";
        $order->total = $order->total ?? "No se ingreso";
        $order->descuento = $order->descuento ?? 0;
        $order->bodega = $order->bodega ?? '01';
        $order->tipo_usuario = $order->tipo_usuario ?? '';

        if (!isset($order->id)) {
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_RECEIVED');
        }elseif( count($order->productos) < 1 ){
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_INSERT_AT_LEAST_ONE_PRODUCT');
        }

        // Guardo un log de la orden
        $this->_saveOrderLogMotorzone($order);

        try {
            /**
             * busque en la bd si la orden ya se creo para el asesor indicado
             * si la orden ya existe entonces cancelo la operacion
             */
            $prevOrders = Orders::count(
                [
                    'asesor = :asesor: AND order_app_id = :order:',
                    'bind' => [
                        'asesor' => $order->asesor,
                        'order'  => $order->id
                    ]
                ]
            );
        } catch (Throwable $exc) {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED_ORDERS', ["error" => $exc->getTraceAsString()] );
            $this->_log->error('common.ERROR_SEARCH_DUPLICATED_ORDERS: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
        }

        if ( $prevOrders > 0 ) {
            $this->buildErrorResponse(400, 'common.ORDER_DUPLICATED');
        }

        /**
         * El metodo "Add" del webservice pide unos headers entonces los agrego
         */
        $paramsH = [
            'SessionID'   => $id,
            'ServiceName' => 'OrdersService'
        ];
        $this->_ordersService->setHeaders(['MsgHeader' => $paramsH]);

        /**
         * Con un reduce meto todos los productos de al array en un texto con el formato que pide el
         * webservice
         * @var array
         */
        $products = array_reduce($order->productos, function($carry, $item){
            $item->bodega = $item->bodega ?? "";
            $bodega = $item->bodega ? "<WarehouseCode>{$item->bodega}</WarehouseCode>" : "";
            $carry .= '<DocumentLine>'
                            . "<ItemCode>{$item->referencia}</ItemCode>"
                            . "<Quantity>{$item->cantidad}</Quantity>"
                            . "<DiscountPercent>{$item->descuento}</DiscountPercent>"
                            . $bodega
                    . '</DocumentLine>';
            return $carry;
        }, '');

        $error = $this->_ordersService->getError();
        if(!$error){
            /**
             * Armo la estructura xml que le voy a enviar al metodo Add del webservice
             */
            try {
                $soapRes = $this->_ordersService->call('Add', ''
                    . '<Add>'
                        . '<Document>'
                                . '<Confirmed>N</Confirmed>'
                                . "<CardCode>{$order->nit_cliente}</CardCode>"
                                . "<U_TRANSP>{$order->trasportadora}</U_TRANSP>"
                                . "<Comments>{$order->comentarios}</Comments>"
                                . "<DocDueDate>{$order->fecha_creacion}</DocDueDate>"
                                . "<NumAtCard>{$order->id}</NumAtCard>"
                                . "<U_idLineLeg>{$order->bodega}</U_idLineLeg>"
                                . "<DiscountPercent>{$order->descuento}</DiscountPercent>"
                                . "<U_OBSERVACION>{$order->tipo_usuario}</U_OBSERVACION>"
                                . '<DocumentLines>'
                                    . $products
                                . '</DocumentLines>'
                        . '</Document>'
                    . '</Add>'
                    );
                /**
                * Me trae la peticion en xml crudo de lo que se envio por soap al sap
                * algo asi como soap envelope bla, bla
                */
                $this->_log->info('Request orden es: '.$this->_ordersService->request);
                /**
                 * Lo mismo que el anterior, pero en vez de traer la peticion, trae la respuesta
                 */
                $this->_log->info('Response orden es: '.$this->_ordersService->response);
                /**
                 * Me devuelve el string con todo el debug de todos los procesos que ha hecho nusoap
                 * para activarlo hay q setear el nivel de debug a mas de 0 ejemplo: "$this->ordersService->setDebugLevel(9);"
                 */
                $this->_log->info('Debug orden es: '.$this->_ordersService->debug_str);
                // Verifico que no haya ningun error, tambien reviso si existe exactamente la ruta del array que especifico
                // si esa rut ano existe significa que algo raro paso muy posiblemente un error
                $error .= $this->_ordersService->getError();
                //Cierro la sesion en sap ya que no es necsario tenerla abierta
                $this->_logout();
            } catch (Throwable $exc) {
                $error .= $exc->getTraceAsString();
            }


            if($error || !isset($soapRes['DocumentParams']['DocEntry'])){
                $this->_log->error('Error al hacer el pedido SAP: '. json_encode($error) );
                $this->_log->error("respuesta del error pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
                $this->buildErrorResponse( 400, 'common.SAP_ERROR_ORDER', ["error" => $error, "soap_res" => $this->utf8ize($soapRes)] );
            }
            // Start a transaction
            $this->db->begin();
            try {
                $newOrder = new Orders();
                $newOrder->asesor = $order->asesor;
                $newOrder->asesor_id = $order->asesor_id;
                $newOrder->order_app_id = $order->id;
                $newOrder->productos = json_encode($order->productos);
                $newOrder->cliente = $order->nit_cliente;
                $newOrder->observaciones = $order->comentarios;

                if ($newOrder->save()) {
                    // Commit the transaction
                    $this->db->commit();

                    $this->sendEmailLog($order);

                }else{
                    $this->db->rollback();
                    // Send errors
                    $errors = array();
                    foreach ($newOrder->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }
                    $this->buildErrorResponse(400, 'common.ORDER_COULD_NOT_BE_CREATED', $errors);
                    $this->_log->error('common.ORDER_COULD_NOT_BE_CREATED: '. json_encode($this->utf8ize($soapRes)) );
                }

            } catch (Throwable $exc) {
                $this->db->rollback();
                $this->buildErrorResponse( 400, 'common.ERROR_ORDERS_MYSQLBD', ["error" => $exc->getTraceAsString()] );
                $this->_log->error('common.ERROR_ORDERS_MYSQLBD: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
            }

            $this->_log->info("respuesta del pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
            $this->buildSuccessResponse(201, 'common.CREATED_SUCCESSFULLY', $this->utf8ize($soapRes));
        }else{
            $this->_logout();
            $this->_log->error('Error al procesar la orden SAP: '. json_encode($error) );
            $this->buildErrorResponse(400, 'common.SAP_ERROR_ORDER', $error);
        }
    }

    /**
     * // LEGACY !!!!!!!!!!!!!!!!!!!!!!!!!!!! NO USAR !!!!!!!!!!!!!!!!!!!!!!!!!!
     * Este metodo hace lo mismo que el de ordenes pero mejorado,evitando que las ordenes se repitan
     * duplique el metodo por si cambiaba el anterior, los usuarios de la aplicacion que no la tengan actualizada
     * van a comenzar a tener errores
     * @param  array $order     Este array recibe la orden que llega desde la pagina de prestashop
     * tiene los productos, la fecha en que se creo y el id de la orden
     * @param  string $sessionId Opcionalmente se le puede enviar el id de la sesion en sap con el que
     * se quiere procesar la orden
     * @return integer  me regresa el numero de la orden dependiendo de si se proceso o no la orden
     * si no se procesa retorna un false
     */
    public function order_v2() {

        // Verifies if is post request
        $this->initializePost();

        //Me logue en el soap de sap
        $this->_login('IGB');
        $id = $this->_sessionId;
        $order = $this->request->getJsonRawBody();

        $order->trasportadora = $order->trasportadora ?? "No se ingreso";
        $order->nit_cliente = $order->nit_cliente ?? "No se ingreso";
        $order->asesor = $order->asesor ?? "No se ingreso";
        $order->asesor_id = $order->asesor_id ?? "No se ingreso";
        $order->user_email = $order->user_email ?? "No se ingreso";

        if (!isset($order->id)) {
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_RECEIVED');
        }elseif( count($order->productos) < 1 ){
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_INSERT_AT_LEAST_ONE_PRODUCT');
        }

        // Guardo un log de la orden
        $this->saveOrderLog($order);

        try {
            /**
             * busque en la bd si la orden ya se creo para el asesor indicado
             * si la orden ya existe entonces cancelo la operacion
             */
            $prevOrders = Orders::count(
                [
                    'asesor = :asesor: AND order_app_id = :order:',
                    'bind' => [
                        'asesor' => $order->asesor,
                        'order'  => $order->id
                    ]
                ]
            );
        } catch (Throwable $exc) {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED_ORDERS', ["error" => $exc->getTraceAsString()] );
            $this->_log->error('common.ERROR_SEARCH_DUPLICATED_ORDERS: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
        }

        if ( $prevOrders > 0 ) {
            $this->buildErrorResponse(400, 'common.ORDER_DUPLICATED');
        }

        /**
         * El metodo "Add" del webservice pide unos headers entonces los agrego
         */
        $paramsH = [
            'SessionID'   => $id,
            'ServiceName' => 'OrdersService'
        ];
        $this->_ordersService->setHeaders(['MsgHeader' => $paramsH]);

        /**
         * Con un reduce meto todos los productos de al array en un texto con el formato que pide el
         * webservice
         * @var array
         */
        $products = array_reduce($order->productos, function($carry, $item){
            $carry .= '<DocumentLine>'
                            . "<ItemCode>{$item->referencia}</ItemCode>"
                            . "<Quantity>{$item->cantidad}</Quantity>"
                            . "<DiscountPercent>{$item->descuento}</DiscountPercent>"
                    . '</DocumentLine>';
            return $carry;
        }, '');

        $error = $this->_ordersService->getError();
        if(!$error){
            /**
             * Armo la estructura xml que le voy a enviar al metodo Add del webservice
             */
            try {
                $soapRes = $this->_ordersService->call('Add', ''
                    . '<Add>'
                        . '<Document>'
                                . '<Confirmed>N</Confirmed>'
                                . "<CardCode>{$order->nit_cliente}</CardCode>"
                                . "<U_TRANSP>{$order->trasportadora}</U_TRANSP>"
                                . "<Comments>{$order->comentarios}</Comments>"
                                . "<DocDueDate>{$order->fecha_creacion}</DocDueDate>"
                                . "<NumAtCard>{$order->id}</NumAtCard>"
                                . '<DocumentLines>'
                                    . $products
                                . '</DocumentLines>'
                        . '</Document>'
                    . '</Add>'
                    );
                /**
                * Me trae la peticion en xml crudo de lo que se envio por soap al sap
                * algo asi como soap envelope bla, bla
                */
                $this->_log->info('Request orden es: '.$this->_ordersService->request);
                /**
                 * Lo mismo que el anterior, pero en vez de traer la peticion, trae la respuesta
                 */
                $this->_log->info('Response orden es: '.$this->_ordersService->response);
                /**
                 * Me devuelve el string con todo el debug de todos los procesos que ha hecho nusoap
                 * para activarlo hay q setear el nivel de debug a mas de 0 ejemplo: "$this->ordersService->setDebugLevel(9);"
                 */
                $this->_log->info('Debug orden es: '.$this->_ordersService->debug_str);
                // Verifico que no haya ningun error, tambien reviso si existe exactamente la ruta del array que especifico
                // si esa rut ano existe significa que algo raro paso muy posiblemente un error
                $error .= $this->_ordersService->getError();
                //Cierro la sesion en sap ya que no es necsario tenerla abierta
                $this->_logout();
            } catch (Throwable $exc) {
                $error .= $exc->getTraceAsString();
            }


            if($error || !isset($soapRes['DocumentParams']['DocEntry'])){
                $this->_log->error('Error al hacer el pedido SAP: '. json_encode($error) );
                $this->_log->error("respuesta del error pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
                $this->buildErrorResponse( 400, 'common.SAP_ERROR_ORDER', ["error" => $error, "soap_res" => $this->utf8ize($soapRes)] );
            }
            // Start a transaction
            $this->db->begin();
            try {
                $newOrder = new Orders();
                $newOrder->asesor = $order->asesor;
                $newOrder->asesor_id = $order->asesor_id;
                $newOrder->order_app_id = $order->id;
                $newOrder->productos = json_encode($order->productos);
                $newOrder->cliente = $order->nit_cliente;
                $newOrder->observaciones = $order->comentarios;

                if ($newOrder->save()) {
                    // Commit the transaction
                    $this->db->commit();

                    //$this->sendEmailLog($order);

                }else{
                    $this->db->rollback();
                    // Send errors
                    $errors = array();
                    foreach ($newOrder->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }
                    $this->buildErrorResponse(400, 'common.ORDER_COULD_NOT_BE_CREATED', $errors);
                    $this->_log->error('common.ORDER_COULD_NOT_BE_CREATED: '. json_encode($this->utf8ize($soapRes)) );
                }

            } catch (Throwable $exc) {
                $this->db->rollback();
                $this->buildErrorResponse( 400, 'common.ERROR_ORDERS_MYSQLBD', ["error" => $exc->getTraceAsString()] );
                $this->_log->error('common.ERROR_ORDERS_MYSQLBD: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
            }

            $this->_log->info("respuesta del pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
            $this->buildSuccessResponse(201, 'common.CREATED_SUCCESSFULLY', $this->utf8ize($soapRes));
        }else{
            $this->_logout();
            $this->_log->error('Error al procesar la orden SAP: '. json_encode($error) );
            $this->buildErrorResponse(400, 'common.SAP_ERROR_ORDER', $error);
        }
    }

    /**
     * Esta metodo se encarga de guardar un registro de todas las ordenes que llegan al API
     * @param type $order
     */
    private function saveOrderLog($order){
        try {
            /**
             * busque en la bd si la orden ya se creo para el asesor indicado
             * si la orden ya existe entonces cancelo la operacion
             */
            $prevOrders = OrdersLog::count(
                [
                    'asesor = :asesor: AND order_app_id = :order:',
                    'bind' => [
                        'asesor' => $order->asesor,
                        'order'  => $order->id
                    ]
                ]
            );
        } catch (Throwable $exc) {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED_ORDERS', ["error" => $exc->getTraceAsString()] );
            $this->_log->error('common.ERROR_SEARCH_DUPLICATED_ORDERS: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
        }

        if ( $prevOrders == 0 ) {
            // Start a transaction
            $this->db->begin();
            try {
                $newOrderLog = new OrdersLog();
                $newOrderLog->asesor = $order->asesor;
                $newOrderLog->asesor_id = $order->asesor_id;
                $newOrderLog->order_app_id = $order->id;
                $newOrderLog->productos = json_encode($order->productos);
                $newOrderLog->cliente = $order->nit_cliente;
                $newOrderLog->observaciones = $order->comentarios;

                if ($newOrderLog->save()) {
                    // Commit the transaction
                    $this->db->commit();

                }else{
                    $this->db->rollback();
                    // Send errors
                    $errors = array();
                    foreach ($newOrderLog->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }
                    $this->buildErrorResponse(400, 'common.ORDER_LOG_COULD_NOT_BE_CREATED', $errors);
                    $this->_log->error('common.ORDER_LOG_COULD_NOT_BE_CREATED: '. json_encode($this->utf8ize($order)) );
                }

            } catch (Throwable $exc) {
                $this->db->rollback();
                $this->buildErrorResponse( 400, 'common.ERROR_ORDERS_MYSQLBD', ["error" => $exc->getTraceAsString()] );
                $this->_log->error('common.ERROR_ORDERS_LOG_MYSQLBD: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
            }
        }

    }

    /**
     * Esta metodo se encarga de guardar un registro de todas las ordenes que llegan al API
     * @param type $order
     */
    private function _saveOrderLogMotorzone($order){
        try {
            /**
             * busque en la bd si la orden ya se creo para el asesor indicado
             * si la orden ya existe entonces cancelo la operacion
             */
            $prevOrders = OrdersLogMotorzone::count(
                [
                    'asesor = :asesor: AND order_app_id = :order:',
                    'bind' => [
                        'asesor' => $order->asesor,
                        'order'  => $order->id
                    ]
                ]
            );
        } catch (Throwable $exc) {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED_ORDERS', ["error" => $exc->getTraceAsString()] );
            $this->_log->error('common.ERROR_SEARCH_DUPLICATED_ORDERS: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
        }

        if ( $prevOrders == 0 ) {
            // Start a transaction
            $this->db->begin();
            try {
                $newOrderLog = new OrdersLogMotorzone();
                $newOrderLog->asesor = $order->asesor;
                $newOrderLog->asesor_id = $order->asesor_id;
                $newOrderLog->order_app_id = $order->id;
                $newOrderLog->productos = json_encode($order->productos);
                $newOrderLog->cliente = $order->nit_cliente;
                $newOrderLog->observaciones = $order->comentarios;

                if ($newOrderLog->save()) {
                    // Commit the transaction
                    $this->db->commit();

                }else{
                    $this->db->rollback();
                    // Send errors
                    $errors = array();
                    foreach ($newOrderLog->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }
                    $this->buildErrorResponse(400, 'common.ORDER_LOG_COULD_NOT_BE_CREATED', $errors);
                    $this->_log->error('common.ORDER_LOG_COULD_NOT_BE_CREATED: '. json_encode($this->utf8ize($order)) );
                }

            } catch (Throwable $exc) {
                $this->db->rollback();
                $this->buildErrorResponse( 400, 'common.ERROR_ORDERS_MYSQLBD', ["error" => $exc->getTraceAsString()] );
                $this->_log->error('common.ERROR_ORDERS_LOG_MYSQLBD: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
            }
        }

    }


    /**
     * Este metodo envia un email con informacion de las ordenes al usuario
     * @param type $order
     */
    private function sendEmailLog($order) {

        $products = array_reduce($order->productos, function($carry, $item){
            $item->titulo = (isset($item->titulo)) ? $item->titulo : "";
            $item->total = (isset($item->total)) ? $item->total : "";
            $carry .= '<tr>'
                            . "<td style='font-family:Arial, sans-serif;font-size:14px;padding:10px 5px;border-style:solid;border-width:1px;overflow:hidden;word-break:normal;border-color:black;text-align:center;vertical-align:top'>{$item->referencia}</th>"
                            . "<td style='font-family:Arial, sans-serif;font-size:14px;padding:10px 5px;border-style:solid;border-width:1px;overflow:hidden;word-break:normal;border-color:black;text-align:center;vertical-align:top'>{$item->titulo}</th>"
                            . "<td style='font-family:Arial, sans-serif;font-size:14px;padding:10px 5px;border-style:solid;border-width:1px;overflow:hidden;word-break:normal;border-color:black;text-align:center;vertical-align:top'>{$item->cantidad}</th>"
                            . "<td style='font-family:Arial, sans-serif;font-size:14px;padding:10px 5px;border-style:solid;border-width:1px;overflow:hidden;word-break:normal;border-color:black;text-align:center;vertical-align:top'>$".$item->total."</th>"
                    . '</tr>';
            return $carry;
        }, '');
        $body = file_get_contents(__DIR__.'/../public/email.html');
        $body = str_replace('%productos%', $products, $body);
        $body = str_replace('%codigo%', $order->id, $body);
        $body = str_replace('%cliente%', $order->nit_cliente, $body);
        $body = str_replace('%observaciones%', $order->comentarios, $body);
        $body = str_replace('%total%', $order->total, $body);

        $mail = new PHPMailer(true);                              // Passing `true` enables exceptions
        try {
            //Server settings
            $mail->SMTPDebug = 0;                                 // Enable verbose debug output
            $mail->isSMTP();                                      // Set mailer to use SMTP
            $mail->Host = 'smtp.gmail.com';                       // Specify main and backup SMTP servers
            $mail->SMTPAuth = true;                               // Enable SMTP authentication
            $mail->Username = 'desarrollowebigb@gmail.com';       // SMTP username
            $mail->Password = 'Webmaster2017#@';                  // SMTP password
            $mail->SMTPSecure = 'ssl';                            // Enable TLS encryption, `ssl` also accepted
            $mail->Port = 465;                                    // TCP port to connect to

            //Recipients
            $mail->setFrom('desarrollo@igbcolombia.com', 'Desarrollo');
            $mail->addAddress($order->user_email, $order->asesor);     // Add a recipient
            $mail->addReplyTo('desarrollo@igbcolombia.com', 'PedidoApp');

            //Content
            $mail->isHTML(true);                                  // Set email format to HTML
            $mail->Subject = 'Informacion pedido IGB APP';
            $mail->Body    = $body;

            $mail->send();


        } catch (Exception $e) {
            $this->_log->error('Error al enviar el e-mail: '. json_encode($e) );
        }

    }

    /**
    * Con esta funcion intento hacer algo parecido al api para consultas de sails.js
    * por el momento es solo una version basica que solo recibe un query con la sgte estructura
    *  {
    *      "and" : {
    *          "codCliente"  : "C80815956",
    *          "codVendedor" : 129
    *      }
    *  }
    * cada registro dentro de la llave key equivale a una condicion de igual de tipo "and"
    * osea que si algun por ej codVendedor no es igual 129 la condicion devolvera false, lo mismo con codCliente
    * mas adelante se podria intentar agregar el operador "or"
    * se pueden agregar cuantos campos sean necesarios a la condicion o no tener ninguna condicion enviado un objeto json vacio { }
    */
    public function cartera() {
        // Verifies if is post request
        $this->initializePost();
        $query = $this->request->getJsonRawBody(true);

        $filterCartera = function (array $record) use ($query) : bool  {
            $andCondition = true;
            if( isset($query['and']) ) {
                foreach ($query['and'] as $key => $val) {
                    $andCondition = ($andCondition && $record[$key] == $val);
                }
            }
            return (bool) $andCondition;
        };

        try {

            /**
             * Leo el archivo csv que contiene solo los productos por modificar
             * mediante la libreria csv de phpleague
             */
            $csv = Reader::createFromPath( DS.'var'.DS.'www'.DS.'html'.DS.'reactphp-couchdb-importer'.DS.'observados'.DS.'invoice.txt', 'r');
            $csv->setDelimiter(';');
            $csv->setHeaderOffset(0); //set the CSV header offset


            $stmt = (new Statement())
                ->where($filterCartera);

            $records = [];
            foreach ($stmt->process($csv) as $offset => $record) {
                $records[] = [
                    "_id"               => $record['factura'],
                    "valor"             => $record['valorFac'],
                    "valor_total"       => $record['ValorTotalFac'],
                    "cod_cliente"       => $record['codCliente'],
                    "cod_vendedor"      => $record['codVendedor'],
                    "fecha_emision"     => $record['fecha'],
                    "fecha_vencimiento" => $record['fechaVencimiento']
                ];
            }

            $this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', $records);

        } catch (Throwable $e) {
            $this->buildErrorResponse(400, 'common.SAP_ERROR_ORDER', $e->getMessage()." ".$e->getLine());
        }

    }

    /**
    * Con esta funcion intento hacer algo parecido al api para consultas de sails.js
    * por el momento es solo una version basica que solo recibe un query con la sgte estructura
    *  {
    *      "and" : {
    *          "codCliente"  : "C80815956",
    *          "codVendedor" : 129
    *      }
    *  }
    * cada registro dentro de la llave key equivale a una condicion de igual de tipo "and"
    * osea que si algun por ej codVendedor no es igual 129 la condicion devolvera false, lo mismo con codCliente
    * mas adelante se podria intentar agregar el operador "or"
    * se pueden agregar cuantos campos sean necesarios a la condicion o no tener ninguna condicion enviado un objeto json vacio { }
    */
    public function cartera_motorzone() {
        // Verifies if is post request
        $this->initializePost();
        $query = $this->request->getJsonRawBody(true);

        $filterCartera = function (array $record) use ($query) : bool  {
            $andCondition = true;
            if( isset($query['and']) ) {
                foreach ($query['and'] as $key => $val) {
                    $andCondition = ($andCondition && $record[$key] == $val);
                }
            }
            return (bool) $andCondition;
        };

        try {

            /**
             * Leo el archivo csv que contiene solo los productos por modificar
             * mediante la libreria csv de phpleague
             */
            $csv = Reader::createFromPath( DS.'var'.DS.'www'.DS.'html'.DS.'reactphp-couchdb-importer'.DS.'observados'.DS.'invoice_motozone.txt', 'r');
            $csv->setDelimiter(';');
            $csv->setHeaderOffset(0); //set the CSV header offset


            $stmt = (new Statement())
                ->where($filterCartera);

            $records = [];
            foreach ($stmt->process($csv) as $offset => $record) {
                $records[] = [
                    "_id"               => $record['factura'],
                    "valor"             => $record['valorFac'],
                    "valor_total"       => $record['ValorTotalFac'],
                    "cod_cliente"       => $record['codCliente'],
                    "cod_vendedor"      => $record['codVendedor'],
                    "fecha_emision"     => $record['fecha'],
                    "fecha_vencimiento" => $record['fechaVencimiento']
                ];
            }

            $this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', $records);

        } catch (Throwable $e) {
            $this->buildErrorResponse(400, 'common.SAP_ERROR_ORDER', $e->getMessage()." ".$e->getLine());
        }

    }

    public function request_account() {
        // Verifies if is post request
        $this->initializePost();
        $data = $this->request->getJsonRawBody(true);

        try {
            /**
             * busque en la bd si el nit ya solicito una cuenta
             * si es asi regreso un error para evitar spam
             */
            $solicitudesPrev = LogCuentasSolicitadas::count(
                [
                    'nit = :nit:',
                    'bind' => [
                        'nit' => $data["nit"]
                    ]
                ]
            );
        } catch (Throwable $exc) {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED', ["error" => $exc->getTraceAsString(),] );
            $this->_log->error('common.ERROR_SEARCH_DUPLICATED: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
        }

        if ( $solicitudesPrev == 0 ) {
            // Start a transaction
            $this->db->begin();
            try {
                $newRequest = new LogCuentasSolicitadas();
                $newRequest->nombre = $data["nombre"];
                $newRequest->email = $data["email"];
                $newRequest->nit = $data["nit"];
                $newRequest->telefono = $data["telefono"];
                $newRequest->ciudad = $data["ciudad"];
                $newRequest->motivo = $data["motivo"];
                $newRequest->observacion = isset($data["observacion"]) ? $data["observacion"] : "";

                if ($newRequest->save()) {
                    // Commit the transaction
                    $this->db->commit();

                    $body = file_get_contents(__DIR__.'/../public/email_request_account.html');
                    $body = str_replace('%nombre%', $data["nombre"], $body);
                    $body = str_replace('%email%', $data["email"], $body);
                    $body = str_replace('%nit%', $data["nit"], $body);
                    $body = str_replace('%telefono%', $data["telefono"], $body);
                    $body = str_replace('%ciudad%', $data["ciudad"], $body);
                    $body = str_replace('%motivo%', $data["motivo"], $body);
                    $body = str_replace('%observacion%', isset($data["observacion"]) ? $data["observacion"] : "", $body);

                    $mail = new PHPMailer(true);                              // Passing `true` enables exceptions
                    try {
                        //Server settings
                        $mail->SMTPDebug = 0;                                 // Enable verbose debug output
                        $mail->isSMTP();                                      // Set mailer to use SMTP
                        $mail->Host = 'smtp.gmail.com';                       // Specify main and backup SMTP servers
                        $mail->SMTPAuth = true;                               // Enable SMTP authentication
                        $mail->Username = 'desarrollowebigb@gmail.com';       // SMTP username
                        $mail->Password = 'Webmaster2017#@';                  // SMTP password
                        $mail->SMTPSecure = 'ssl';                            // Enable TLS encryption, `ssl` also accepted
                        $mail->Port = 465;                                    // TCP port to connect to

                        //Recipients
                        $mail->setFrom('desarrollo@igbcolombia.com', 'Desarrollo');
                        $mail->addAddress("analistacomercial@igbcolombia.com", 'Telemercadeo');     // Add a recipient
                        $mail->addReplyTo('desarrollo@igbcolombia.com', 'PedidoApp');

                        //Content
                        $mail->isHTML(true);                                  // Set email format to HTML
                        $mail->Subject = 'Solicitud de cuenta IGB APP';
                        $mail->Body    = $body;

                        $mail->send();


                    } catch (Exception $e) {
                        $this->_log->error('Error al enviar el e-mail: '. json_encode($e) );
                    }

                    $this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', $data);

                }else{
                    $this->db->rollback();
                    // Send errors
                    $errors = array();
                    foreach ($newRequest->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }
                    $this->buildErrorResponse(400, 'common.REQUEST_COULD_NOT_BE_CREATED', $errors);
                    $this->_log->error('common.REQUEST_COULD_NOT_BE_CREATED: '. json_encode($this->utf8ize($order)) );
                }

            } catch (Throwable $exc) {
                $this->db->rollback();
                // Send errors
                $errors = array();
                foreach ($newRequest->getMessages() as $message) {
                    $errors[] = $message->getMessage();
                }
                $this->buildErrorResponse( 400, 'common.ERROR_ORDERS_MYSQLBD', ["error" => $exc->getTraceAsString(), "errors"=>$exc, "data"=>$data] );
                $this->_log->error('common.ERROR_ORDERS_LOG_MYSQLBD: '. json_encode($this->utf8ize(["error" => $exc->getTraceAsString()])) );
            }
        } else {
            $this->buildErrorResponse( 400, 'common.ERROR_SEARCH_DUPLICATED_NIT', [ "data"=>$data] );
        }



    }

}

