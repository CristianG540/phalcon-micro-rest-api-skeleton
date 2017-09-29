<?php

class SapController extends ControllerBase
{

    /**
     * Punto de entrada del controlador
     */
    public function index()
    {
    	// Verifies if is get request
        $this->initializeGet();
        echo "dd";
    	$this->buildSuccessResponse(200, 'common.SUCCESSFUL_REQUEST', ["info"=> "systems online"]);
    }

}

