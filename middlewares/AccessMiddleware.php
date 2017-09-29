<?php

use Phalcon\Mvc\Micro\MiddlewareInterface;

/**
* AccessMiddleware
*
* Access and user permissions
 */
class AccessMiddleware extends ControllerBase implements MiddlewareInterface
{
    public function call(Phalcon\Mvc\Micro $app)
    {
        $nameController = "";
        // Initialize
        // Gets users ACL
        xdebug_break();
        include APP_PATH.'/config/acl.php';
        $arrHandler = $app->getActiveHandler();
        //get the controller for this handler

        /*********************************************************************/
        /**
         * Verifico si los controladores (handlers) se estan cargando por medio
         * de lazy loading o no, y asi cambio la forma en la que se recupera el
         * nombre del controlador que se esta llamando
         */
        //https://forum.phalconphp.com/discussion/1388/acl-in-micro
        if(get_class($arrHandler[0]) != "Phalcon\Mvc\Micro\LazyLoader" ){
            $nameController = get_class($arrHandler[0]);
        }else{
            $array = (array) $arrHandler[0];
            $nameController = implode("", $array);
        }
        /********************************************************************/

        $controller = str_replace('Controller', '', $nameController);
        // get function
        $function = $arrHandler[1];
        // check if controller is Index, if it´s Index, then checks if any of functions are called if so return allow
        if ($controller === 'Index') {
            $allowed = 1;
            return $allowed;
        }

        // gets user token
        $mytoken = $this->getToken();

        // Verifies Token exists and is not empty
        if( empty($mytoken) || $mytoken == '' ) {
            $this->buildErrorResponse(400, "common.EMPTY_TOKEN_OR_NOT_RECEIVED");
        } else {

            // Verifies Token
            try {

                $token_decoded = $this->decodeToken($mytoken);

                // Verifies User role Access
                $allowed_access = $acl->isAllowed($token_decoded->username_level, $controller, $arrHandler[1]);
                if(!$allowed_access) {
                    $this->buildErrorResponse(403, "common.YOUR_USER_ROLE_DOES_NOT_HAVE_THIS_FEATURE");
                } else {
                    return $allowed_access;
                }

            } catch(Exception $e) {
                // BAD TOKEN
                $this->buildErrorResponse(401, "common.BAD_TOKEN_GET_A_NEW_ONE");
            }
        }
    }
}
