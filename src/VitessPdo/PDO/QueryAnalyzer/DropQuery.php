<?php
/**
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 *
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the PIXEL FEDERATION, s.r.o. nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL PIXEL FEDERATION, s.r.o. BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace VitessPdo\PDO\QueryAnalyzer;

use VitessPdo\PDO\Exception;
use VitessPdo\PDO\QueryAnalyzer\Query\CreateExpression;
use VitessPdo\PDO\QueryAnalyzer\Query\Expression;

/**
 * Description of class DropQuery
 *
 * @author  mfris
 * @package VitessPdo\PDO\QueryAnalyzer
 */
class DropQuery extends QueryDecorator
{

    /**
     * @var string
     */
    private $object;

    /**
     * @const string
     */
    const TYPE = QueryInterface::TYPE_DROP;

    /**
     * @const string
     */
    const EXPRESSION_TABLE = 'TABLE';

    /**
     * @const string
     */
    const EXPRESSION_EXPRESSION = 'expression';

    /**
     *
     * @return string
     * @throws Exception
     */
    public function getObject()
    {
        if ($this->object === null) {
            /* @var $expression Expression */
            $expression = $this->getExpressions()[0];
            /* @var $expressions Expression[] */
            $expressions = $expression->getSubTree();

            if (!isset($expressions[0])) {
                throw new Exception("Object missing.");
            }

            $stopExprTypes = [self::EXPRESSION_EXPRESSION];
            $objectParts = [];
            /* @var $expr Expression */
            foreach ($expressions as $index => $expr) {
                if (in_array($expr->getType(), $stopExprTypes) || $expr->getNoQuotes()) {
                    $this->afterObjectIndex = $index;
                    break;
                }

                $objectParts[] = $expr->getExpression();
            }

            $this->object = implode(' ', $objectParts);
        }

        return $this->object;
    }

    /**
     * @return CreateExpression
     * @throws Exception
     */
    public function getObjectExpression()
    {
        $objectName = $this->getObject();
        $parsedSql = $this->getParsedSqlByExprType($objectName);

        return new CreateExpression(new Expression($parsedSql));
    }
}
