<?php
/**
 * @author     mfris
 * @copyright  Pixel federation
 * @license    Internal use only
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
    ];

    /**
     * @param mixed $value
     * @param int $type
     *
     * @return mixed
     */
    public function process($value, $type = CorePDO::PARAM_STR)
    {
        $handler = $this->getHandler($type);

        return call_user_func([$this, $handler], $value);
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
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function string($value)
    {
        return $this->escapeString((string) $value);
    }

    /**
     * @param $value
     *
     * @return string
     */
    private function escapeString($value)
    {
        if (!empty($value) && is_string($value)) {
            return str_replace(self::$strReplaceFrom, self::$strReplaceTo, $value);
        }

        return $value;
    }
}
