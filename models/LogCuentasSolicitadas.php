<?php

class LogCuentasSolicitadas extends \Phalcon\Mvc\Model
{
    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $nombre;

    /**
     *
     * @var string
     */
    public $email;

    /**
     *
     * @var string
     */
    public $nit;

    /**
     *
     * @var integer
     */
    public $telefono;

    /**
     *
     * @var string
     */
    public $ciudad;

    /**
     *
     * @var string
     */
    public $motivo;

    /**
     *
     * @var string
     */
    public $observacion;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var integer
     */
    public $estado;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setConnectionService('db');
    }

    /**
     * Returns table name mapped in the model.
     *
     * @return string
     */
    public function getSource()
    {
        return 'log_cuentas_solicitadas';
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return LogCuentasSolicitadas[]
     */
    public static function find($parameters = null)
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return LogCuentasSolicitadas
     */
    public static function findFirst($parameters = null)
    {
        return parent::findFirst($parameters);
    }

    /**
     * Independent Column Mapping.
     * Keys are the real names in the table and the values their names in the application
     *
     * @return array
     */
    public function columnMap()
    {
        return array(
            'id' => 'id',
            'nombre' => 'nombre',
            'email' => 'email',
            'nit' => 'nit',
            'telefono' => 'telefono',
            'ciudad' => 'ciudad',
            'motivo' => 'motivo',
            'observacion' => 'observacion',
            'created_at' => 'created_at',
            'estado' => 'estado'
        );
    }

}

