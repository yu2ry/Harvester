<?php

namespace Fector\Harvest\Helpers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Class Condition
 * @package Fector\Harvest\Helpers
 *
 * @property $type
 * @property $action
 * @property $param
 * @property $operator
 * @property $value
 */
class Condition
{
    const TYPE_UNKNOWN = 'unknown';
    const TYPE_EQUAL = 'equal';
    const TYPE_IN_ARRAY = 'inArray';
    const TYPE_NOT_IN_ARRAY = 'notInArray';
    const TYPE_IS_NULL = 'isNull';
    const TYPE_IS_NOT_NULL = 'isNotNull';
    const TYPE_LIKE = 'isNotNull';
    const TYPE_LIKE_LEFT = 'isNotNull';
    const TYPE_LIKE_RIGHT = 'isNotNull';

    const CONDITION_IN = 'in';
    const CONDITION_NOT_IN = 'not_in';
    const CONDITION_IS = 'is';
    const CONDITION_IS_NOT = 'is_not';
    const CONDITION_LIKE = 'like';
    const CONDITION_LIKE_LEFT = 'like_left';
    const CONDITION_LIKE_RIGHT = 'like_right';

    /**
     * @var array
     */
    protected $comparators = [
        '=',
        '>',
        '>=',
        '<',
        '<=',
    ];

    /**
     * @var array
     */
    protected $conditions = [
        self::CONDITION_IN,
        self::CONDITION_NOT_IN,
        self::CONDITION_IS,
        self::CONDITION_IS_NOT,
        self::CONDITION_LIKE,
        self::CONDITION_LIKE_LEFT,
        self::CONDITION_LIKE_RIGHT,
    ];

    /**
     * @var string
     */
    protected $_param; // @codingStandardsIgnoreLine

    /**
     * @var string
     */
    protected $_relation;

    /**
     * @var string
     */
    protected $_field;

    /**
     * @var string
     */
    protected $_operator;

    /**
     * @var string
     */
    protected $_value; // @codingStandardsIgnoreLine

    /**
     * @var string
     */
    protected $_type; // @codingStandardsIgnoreLine

    /**
     * @var \Closure
     */
    protected $_action; // @codingStandardsIgnoreLine

    /**
     * Condition constructor.
     * @param array $arg
     */
    public function __construct(array $arg)
    {
        $this->identify($arg);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        $fieldName = '_' . $name;
        return $this->$fieldName;
    }

    /**
     * @param array $arg
     */
    protected function identify(array $arg): void
    {
        $param = key($arg);
        $body = $arg[$param];
        $this->_param = $param;

        if (!is_array($body)) {
            $this->_type = self::TYPE_EQUAL;
            $this->_value = $body;
        } elseif (is_array($body)) {
            if (array_filter(array_keys($body), (function (string $key): bool {
                return in_array($key, $this->conditions);
            })->bindTo($this))) {
                if (key_exists(self::CONDITION_IN, $body)) {
                    $this->_type = self::TYPE_IN_ARRAY;
                    $this->_value = $body[self::CONDITION_IN];
                } elseif (key_exists(self::CONDITION_NOT_IN, $body)) {
                    $this->_type = self::TYPE_NOT_IN_ARRAY;
                    $this->_value = $body[self::CONDITION_NOT_IN];
                } elseif (key_exists(self::CONDITION_IS, $body)) {
                    $this->_type = self::TYPE_IS_NULL;
                } elseif (key_exists(self::CONDITION_IS_NOT, $body)) {
                    $this->_type = self::TYPE_IS_NOT_NULL;
                } elseif (key_exists(self::CONDITION_LIKE, $body)) {
                    $this->_type = self::TYPE_LIKE;
                    $this->_value = $body[self::CONDITION_LIKE];
                } elseif (key_exists(self::CONDITION_LIKE_LEFT, $body)) {
                    $this->_type = self::TYPE_LIKE_LEFT;
                    $this->_value = $body[self::CONDITION_LIKE_LEFT];
                } elseif (key_exists(self::CONDITION_LIKE_RIGHT, $body)) {
                    $this->_type = self::TYPE_LIKE_RIGHT;
                    $this->_value = $body[self::CONDITION_LIKE_RIGHT];
                }
            } else {
                $this->action = function (Builder $builder) use ($body, $param): Builder {
                    return $builder->whereHas($param, function (Builder $builder) use ($body): void {
                        foreach ($body as $column => $value) {
                            $method = (new self([$column => $value]))->action;
                            $method && $method($builder);
                        }
                    });
                };

                return;
            }
        } else {
            $this->_type = self::TYPE_UNKNOWN;
        }

        if (($delimiterPos = strripos($this->param, '.')) !== false) {
            $this->_relation = substr($this->param, 0, $delimiterPos);
            $this->_field = substr($this->param, $delimiterPos + 1);
        }

        $this->buildAction();
    }

    /**
     * @return void
     */
    protected function buildAction(): void
    {
        $method = 'where';
        $methodArgs = [$this->value];
        switch ($this->_type) {
            case self::TYPE_EQUAL:
                break;
            case self::TYPE_LIKE:
                $methodArgs = [DB::raw('LOWER(\'%' . $this->value . '%\')')];
                break;
            case self::TYPE_LIKE_LEFT:
                $methodArgs = [DB::raw('LOWER(\'%' . $this->value . '\')')];
                break;
            case self::TYPE_LIKE_RIGHT:
                $methodArgs = [DB::raw('LOWER(\'' . $this->value . '%\')')];
                break;
            case self::TYPE_IN_ARRAY:
                $method = 'whereIn';
                break;
            case self::TYPE_NOT_IN_ARRAY:
                $method = 'whereNotIn';
                break;
            case self::TYPE_IS_NULL:
                $method = 'whereNull';
                $methodArgs = [];
                break;
            case self::TYPE_IS_NOT_NULL:
                $method = 'whereNotNull';
                $methodArgs = [];
                break;
            default:
                $method = null;
        }

        if (isset($method)) {
            if (in_array($this->_type, [
                self::TYPE_LIKE,
                self::TYPE_LIKE_LEFT,
                self::TYPE_LIKE_RIGHT,
            ])) {
                array_unshift($methodArgs, 'like');
            }

            $prop = (function ($field) {
                if (in_array($this->_type, [
                    self::TYPE_LIKE,
                    self::TYPE_LIKE_LEFT,
                    self::TYPE_LIKE_RIGHT,
                ])) {
                    return DB::raw('LOWER(' . $field . ')');
                }

                return $field;
            })->bindTo($this);

            if ($this->_relation &&
                $this->_field) {
                $this->_action = (function (Builder $builder) use ($method, $methodArgs, $prop): Builder {
                    return $builder->whereHas($this->_relation, function (Builder $query) use ($method, $methodArgs, $prop): void {
                        array_unshift($methodArgs, $prop($query
                            ->getModel()
                            ->getTable() . '.' . $this->_field));
                        $query->{$method}(...$methodArgs);
                    });
                })->bindTo($this);
            } else {
                $this->_action = (function (Builder $query) use ($method, $methodArgs, $prop): Builder {
                    array_unshift($methodArgs, $prop($query
                        ->getModel()
                        ->getTable() . '.' . $this->param));
                    return $query->{$method}(...$methodArgs);
                })->bindTo($this);
            }
        }
    }
}
