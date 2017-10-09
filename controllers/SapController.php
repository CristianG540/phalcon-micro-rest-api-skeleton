<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\CouchDBHandler;

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
        $this->_log->pushHandler(new CouchDBHandler([
            'host'     => '45.77.74.23',
            'port'     => 5984,
            'dbname'   => 'josefalogger',
            'username' => '',
            'password' => ''
        ], Logger::DEBUG));

        //$this->_loginService = new nusoap_client($this->sapConfig['login_wsdl'], true ); /* desarrollo */
        //$this->_loginService = new nusoap_client($this->sapConfig['login_wsdl'], true, FALSE, FALSE, FALSE, FALSE, 0, 30, 'LoginServiceSoap12'); /* desarrollo */
        $this->_loginService = new nusoap_client($this->sapConfig['login_wsdl'], true, FALSE, FALSE, FALSE, FALSE, 0, 30, 'LoginServiceSoap12');
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
        $this->_log->info("systems online");

    	$this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', ["info"=>"systems online"]);
    }

    /**
     * Este metodo es que se loguea en sap y recupera el id de la sesion
     */
    private function _login(){

        $error  = $this->_loginService->getError();
        if(!$error){
            $params = [
                'DatabaseServer'  => '192.168.10.102', //string
                'DatabaseName'    => $this->sapConfig['db_name'], //string
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
     * y procesarla debidamente
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
        $this->_login();
        $id = $this->_sessionId;
        $order = $this->request->getJsonRawBody();

        if (!isset($order->id)) {
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_RECEIVED');
        }elseif( count($order->productos) < 1 ){
            $this->buildErrorResponse(400, 'common.INCOMPLETE_DATA_INSERT_AT_LEAST_ONE_PRODUCT');
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
            $soapRes = $this->_ordersService->call('Add', ''
                    . '<Add>'
                        . '<Document>'
                                . '<Confirmed>N</Confirmed>'
                                . "<CardCode>{$order->nit_cliente}</CardCode>"
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
            $error = $this->_ordersService->getError();
            //Cierro la sesion en sap ya que no es necsario tenerla abierta
            $this->_logout();
            if($error || !isset($soapRes['DocumentParams']['DocEntry'])){
                $this->_log->error('Error al hacer el pedido SAP: '. json_encode($error) );
                $this->_log->error("respuesta del error pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
                $this->buildErrorResponse( 400, 'common.SAP_ERROR_ORDER', ["error" => $error, "soap_res" => $this->utf8ize($soapRes)] );
            }
            $this->_log->info("respuesta del pedido a SAP: ". json_encode($this->utf8ize($soapRes)) );
            $this->buildSuccessResponse(201, 'common.CREATED_SUCCESSFULLY', $this->utf8ize($soapRes));
        }else{
            $this->_logout();
            $this->_log->error('Error al procesar la orden SAP: '. json_encode($error) );
            $this->buildErrorResponse(400, 'common.SAP_ERROR_ORDER', $error);
        }
    }

}

