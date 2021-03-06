<?php
abstract class Model
{
    /**
     * @var array List of errors 
     */
    protected $errors = [];
    
    /**
     * @var bool It is new record or not
     */
    protected $isNewRecord = true;
    
    /**
     * @return bool Is new record or existing
     */
    public function getIsNewRecord()
    {
        return $this->isNewRecord;
    }
    
    /**
     * @param bool $value
     * @return Model
     */
    public function setIsNewRecord($value)
    {
        $this->isNewRecord = $value;
        return $this;
    }

    /**
     * @return string Table name
     */
    abstract public static function tableName();
    
    /**
     * @return string Primary key name
     */
    abstract public static function primaryKey();
    
    /**
     * @return array List of model attributes
     */
    abstract public function getAttributes();
    
    /**
     * @return array Required fileds list
     */
    abstract public function getRequiredFields();

    /**
     * @return array Field types list
     */
    abstract public function getFieldTypes();
    
    /**
     * @return array Unique fileds list
     */
    abstract public function getUniqueFields();

    /**
     * Finds a single row with the specified condition.
     *
     * @param string $condition Where SQL condition
     * @param array $params List of parameters
     * @return Model|null
     */
    public static function find($condition = '', array $params = [])
    {
        $db = DB::getInstance();
        $data = $db->select(static::tableName(), ['*'], $condition, $params);
        $model = null;
        if (count($data)) {
            $model = new static();
            $model->populate(reset($data));
            $model->setIsNewRecord(false);
        }
        return $model;
    }
    
    /**
     * Finds all rows with the specified condition.
     *
     * @param string $condition Where SQL condition
     * @param array $params List of parameters
     * @return Model[]
     */
    public static function findAll($condition = '', array $params = [])
    {
        $db = DB::getInstance();
        $models = [];
        $data = $db->select(static::tableName(), ['*'], $condition, $params);
        if (count($data)) {
            foreach ($data as $row) {
                $model = new static();
                $model->populate($row);
                $model->setIsNewRecord(false);
                $models[] = $model;
            }
        }
        return $models;
    }
    
    /**
     * Finds a row record with the specified primary key.
     *
     * @param int $pk
     * @return Model|null
     */
    public static function findByPk($pk)
    {
        return static::find(static::primaryKey() . ' = ?', [$pk]);
    }
    
    /**
     * Deletes corresponding row.
     *
     * @return bool
     */
    public function delete()
    {
        $db = DB::getInstance();
        $deleted = $db->delete(
            $this->tableName(),
            $this->primaryKey() . ' = ?',
            [$this->{$this->primaryKey()}]
        );
        return $deleted > 0;
    }
    
    /**
     * Deletes rows with the specified condition.
     *
     * @param string $condition Where SQL condition
     * @param array $params List of parameters
     * @return int The number of rows deleted
     */
    public static function deleteAll($condition = '', array $params = [])
    {
        $deleted = 0;
        $models = static::findAll($condition, $params);
        foreach ($models as $model) {
            if ($model->delete()) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Deletes row with the specified primary key.
     *
     * @param int $pk
     * @return boolean
     */
    public static function deleteByPk($pk)
    {
        $model = static::findByPk($pk);
        if ($model) {
             return $model->delete();
        }
        return false;
    }
    
    /**
     * Populates record with the given attributes.
     *
     * @param array $data
     * @return Model
     */
    public function populate(array $data, $attributes = null)
    {
        $list = $this->getAttributes();
        if (is_array($attributes)) {
            $list = array_intersect($list, $attributes);
        }
        foreach ($data as $key => $value) {
            if (in_array($key, $list)) {
                $this->{$key} = $value;
            }
        }
        return $this;
    }
    
    /**
     * Get record's array representation
     *
     * @return array Associative array representation of current model 
     */
    public function toArray($attributes = null)
    {
        $arr = [];
        $list = $this->getAttributes();
        if (is_array($attributes)) {
            $list = array_intersect($list, $attributes);
        }
        foreach ($list as $attribute) {
            $arr[$attribute] = $this->{$attribute};
        }
        return $arr;
    }

    /**
     * Before save event
     *
     * @return bool
     */
    protected function beforeSave()
    {
        return true;
    }

    /**
     * After save event
     *
     * @return bool
     */
    protected function afterSave()
    {
        return true;
    }

    /**
     * Saves the current record.
     *
     * @param bool $runValidation Whether to perform validation before saving the record
     * @param array|null $attributes List of attributes that need to be saved
     */
    public function save($runValidation = true, $attributes = null)
    {
         if (!$runValidation || $this->validate($attributes)) {
             if ($this->beforeSave() && $this->checkUnique($attributes)) {
                 $saved = $this->getIsNewRecord() ? $this->insert($attributes) : $this->update($attributes);
                 if ($saved) {
                     $this->setIsNewRecord(false);
                     $this->afterSave();
                 }
                 return $saved;
             }
         } else {
             return false;
         }
    }
    
    /**
     * Inserts a row into the table based on this record attributes.
     *
     * @param array|null $attributes List of attributes that need to be saved
     * @return bool
     */
    protected function insert($attributes = null)
    {
        $db = DB::getInstance();
        $data = $this->toArray($attributes);
        $inserted = $db->insert(
            $this->tableName(),
            $data
        );
        if ($inserted > 0) {
            $this->{$this->primaryKey()} = $db->insertId();
        }
        return $inserted > 0;
    }
    
    /**
     * Check current record attributes to be unique per table
     * 
     * @param array|null $attributes Attributes list to check
     * @return bool
     */
    protected function checkUnique($attributes = null)
    {
        $uniqueFields = $this->getUniqueFields();
        if (is_array($attributes) && count($attributes)) {
            $uniqueFields = array_intersect($uniqueFields, $attributes);
        }
        if (count($uniqueFields)) {
            $sql = sprintf(
                ' (%s = ?) ',
                implode(' = ? OR ', $uniqueFields)
            );
            $params = $this->toArray($uniqueFields);
            if (!$this->getIsNewRecord()) {
                $sql .= sprintf(
                    ' AND %s != ?',
                    $this->primaryKey()
                );
                $params[] = $this->{$this->primaryKey()};
            }
            $models = static::findAll($sql, $params);
            if (count($models)) {
                $this->errors[] = sprintf(
                    'One of this fields is not unique: %s',
                     implode(', ', $uniqueFields)
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Updates the row represented by this record.
     *
     * @param array|null $attributes List of attributes that need to be saved
     * @return bool
     */
    protected function update($attributes = null)
    {
        $db = DB::getInstance();
        $data = $this->toArray($attributes);
        $updated = $db->update(
            $this->tableName(),
            $data,
            $this->primaryKey() . ' = ?',
            [$this->{$this->primaryKey()}]
        );
        return $updated > 0;
    }

    /**
     * Validate property values
     * 
     * @param array|null $attributes List of attributes that need to be checked
     * @return bool
     */
    protected function validate($attributes = null)
    {
        $required = $this->getRequiredFields();
        $types = $this->getFieldTypes();
        $data = $this->toArray($attributes);
        $validator = new Validator();
        $this->errors = [];
        if ($validator->checkRequired($required, $data) && $validator->checkTypes($types, $data)) {
            return true;
        } else {
            $this->errors = array_merge($this->errors, $validator->getErrors());
            return false;
        }
    }

    /**
     * Get errors
     * 
     * @return array List of errors
     */
    public function getErrors()
    {
        return $this->errors;
    }
}