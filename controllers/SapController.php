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
     * El id de la sesion que me da el sap, este se usa para los pedidos y para el logout
     */
    private $_sessionId = '';
    /**
     * Variable donde guardo la instancia de monolog para hacer el log y debug de los datos
     * @var object
     */
    private $_log;

    public function __construct(){
        //xdebug_break();
        $this->_log = new Logger('josefaAPI');
        $this->_log->pushHandler(new StreamHandler(__DIR__.'/../logs/info.log', Logger::DEBUG));
        $this->_log->pushHandler(new CouchDBHandler([
            'host'     => '108.163.227.76',
            'port'     => 5984,
            'dbname'   => 'josefalogger',
            'username' => '',
            'password' => ''
        ], Logger::DEBUG));

        $this->_loginService = new nusoap_client('http://b1ws.igbcolombia.com/B1WS/WebReferences/LoginService.wsdl', true);
        $this->_loginService->setDebugLevel(0);
    }

    /**
     * Punto de entrada del controlador
     */
    public function index()
    {
        //xdebug_break();
    	// Verifies if is get request
        $this->initializeGet();
        $this->_log->info("systems online");
    	$this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', ["info"=>"systems online"]);
    }

    private function _login(){

        $error  = $this->_loginService->getError();
        if(!$error){
            $params = [
                'DatabaseServer'  => '192.168.10.102', //string
                'DatabaseName'    => 'MERCHANDISING', //string
                'DatabaseType'    => 'dst_MSSQL2012', //DatabaseType
                'CompanyUsername' => 'manager', //string
                'CompanyPassword' => 'Pa$$w0rd', //string
                'Language'        => 'ln_Spanish', //Language
                'LicenseServer'   => '192.168.10.102:30000' //string
            ];
            $soapRes = $this->_loginService->call('Login', $params);
            $error  = $this->_loginService->getError();
            if($error){
               $this->log->error('Error en el login SAP: '. json_encode($error) );
               $this->buildErrorResponse(403, 'common.SAP_ERROR_LOGIN', $error);
               return false;
            }
            $this->log->info("respuesta login: ".json_encode($soapRes));
            $this->_sessionId = $soapRes['SessionID'];
        }else{
            $this->_log->error('Error en el login SAP: '. json_encode($error) );
            $this->buildErrorResponse(403, 'common.SAP_ERROR_LOGIN', $error);
        }

    }

    private function _logout(){

    }

}

