<?php
/**
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 */

namespace VitessPdo\PDO;

use PDO as CorePDO;

/**
 * Description of class ParamProcessor
 *
 * @author  mfris
 * @package VitessPdo\PDO
 */
class ParamProcessor
{

    /**
     * @var array
     */
    private static $strReplaceFrom = ['\\', "\0", "\n", "\r", "'", "\x1a"];

    /**
     * @var array
     */
    private static $strReplaceTo = ['\\\\', '\\0', '\\n', '\\r', "''", '\\Z'];

    /**
     * @var array
     */
    private static $typeHandlers = [
        CorePDO::PARAM_BOOL => 'boolean',
        CorePDO::PARAM_INT => 'integer',
        CorePDO::PARAM_NULL => 'null',
        CorePDO::PARAM_STR => 'string',
        CorePDO::PARAM_LOB => 'string',
    ];

    /**
     * @param mixed $value
     * @param int   $type
     *
     * @return mixed
     * @throws Exception
     */
    public function process($value, $type = CorePDO::PARAM_STR)
    {
        $handler = $this->getHandler($type);

        return $this->{$handler}($value);
    }

    /**
     * @param mixed $value
     * @param int   $type
     *
     * @return mixed
     * @throws Exception
     */
    public function processEscaped($value, $type = CorePDO::PARAM_STR)
    {
        if ($type !== CorePDO::PARAM_STR) {
            return $this->process($value, $type);
        }

        $handler = $this->getHandler($type);

        return $this->{$handler}($value, true);
    }

    /**
     * @param int $type
     *
     * @return null|string
     * @throws Exception
     */
    private function getHandler($type)
    {
        if (!isset(self::$typeHandlers[$type])) {
            throw new Exception("Unsupported PDO param type - " . $type);
        }

        return self::$typeHandlers[$type];
    }

    /**
     * @param mixed $value
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function boolean($value)
    {
        return (bool) $value;
    }

    /**
     * @param mixed $value
     *
     * @return int
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function integer($value)
    {
        return (int) $value;
    }

    /**
     * @param mixed $value
     *
     * @return null
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function null($value)
    {
        return null;
    }

    /**
     * @param mixed $value
     * @param bool  $escape
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function string($value, $escape)
    {
        $value = (string) $value;

        if ($escape) {
            $value = str_replace(self::$strReplaceFrom, self::$strReplaceTo, $value);
        }

        return $value;
    }
}
