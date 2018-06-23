<?php
namespace CORE\MVC;
use CORE\DB\Query;
use CORE\Date;
class Model
{
	protected static $table;
	protected $data;
    protected static $model;
    protected static $foreignKeys = [];
    private $cache;
    public static function init($id = 0)
    {
        $ins1=static::class;
		$ins=new $ins1;
        $ins->cache = [];
		if($id !== 0)
		{
            if(is_numeric($id))
            {
                $result=Query::table($ins1::$table)->select()->where(['id','=',$id])->limit(0,1)->run();
                if($result->num_rows < 1)
                {
                    throw new ObjectNotFoundException(get_called_class() . " Object Not Found for id $id");
                }
                $ins->data=$result->fetchArray()[0];
            }
            else if(is_array($id))
            {
                $ins->data = $id;
            }
            else
            {
                var_dump($id);
                throw new \InvalidArgumentException("Parmater must be either of type int or object");
            }
		}
    	return $ins;
    }
    public static function getTableName()
    {
        $ins1=static::class;
        return $ins1::$table;
    }
    protected function getColumns():array
    {
        return array_keys(static::$model);
    }
    public static function getColumnNames(): array
    {
        return array_keys(static::$model);
    }
    protected function getDBColumns():array
    {
        $cols=[];
        foreach(static::$model as $key=>$data)
        {
            $cols[$key]=$data['ColumnName']??$key;
        }
        return $cols;
    }
    public static function getAll($limit):array
    {
        $ins=static::class;
        $result=Query::table($ins::$table)->select('ID')->limit($limit)->run();
		$ar=[];
		foreach($result as $data)
            $ar[]=$ins::init($data->id);
		return $ar;
    }
    public static function search():array
    {
        $ins=static::class;
        $result = Query::table($ins::$table)->select('id')->run()->fetchObject();
		$ar=[];
		foreach($result as $data)
        {
            $ar[]=$ins::init($data->id);
        }
		return $ar;
    }
	private function updateData(string $columnName, $columnValue): bool
	{
		$this->data[$columnName]=$columnValue;
    	return true;
	}
    public static function getPrimaryKey()
    {
        $key=null;
        foreach(static::$model as $k=>$data)
        {
            if(($data['Key']??false) === true)
            {
                $key=static::getDBColumn($k);
                break;
            }
        }
        return $k;
    }
    public static function isForeignKey(string $key): bool
    {
        return isset(static::getModelProperties()[$key]['ForeignKey']);
    }
    public static function getForeignKeys()
    {
        if(!isset(static::$foreignKeys[static::getClass()]))
        {
            $keys = static::getModelProperties();
            static::$foreignKeys[static::getClass()] = [];
            foreach($keys as $key => $data)
            {
                if(isset($data['ForeignKey']))
                    static::$foreignKeys[static::getClass()][$key] = $data;
            }
        }
        return static::$foreignKeys[static::getClass()];
    }
    public function validateForeignKey() : bool
    {
        $foreignKeys = static::getForeignKeys();
    }
    public function getForeignKeyValue(string $key)
    {
        $info = static::$model[$key]['ForeignKey'];
        $info = explode(":",$info);
        return $this->{"get".$key}() !== null ? $this->{"get".$key}()->{"get".$info[1]}() : null;
    }
    public function getForeignObject(string $col)
    {
        return static::$model[$key]['ForeignKey']::init($this->{"get".$col}());
    }
    public function createForeignObject(string $col, $value)
    {
        $info = static::$model[$col]['ForeignKey'];
        $info = explode(":",$info);
        return $info[0]::find()->where([$info[1],'=',$value])->single();
    }
    public static function getDBColumn(string $column)
    {
        if(!isset(static::$model[$column]))
            throw new \CORE\MVC\ModelErrorException("Model ".static::getClass()." Property ".$column." Not Found", 2);
        return static::$model[$column]['ColumnName'] ?? $column;
    }
	public function save()
	{
        $cols=$this->getDBColumns();
        $insertCol=[];
        foreach($cols as $key=>$data)
        {
            if($this->getPrimaryKey() !== $data && !$this->isAutoGenerated($key) && $this->{"get".$key}() !== null)
            {
                $insertCol[$data]=$this->{"get".$key}();
                if(self::isForeignKey($key))
                {
                    $insertCol[$data] = $insertCol[$data]->getId();
                }
                else if(get_parent_class($insertCol[$data]) === 'CORE\Enum')
                {
                    $insertCol[$data] = $insertCol[$data] -> value;
                }
                else if(is_object($insertCol[$data]))
                {
                    $insertCol[$data] = (string) $insertCol[$data];
                }
                else if(is_array($insertCol[$data]))
                {
                    $insertCol[$data] = implode(",", $insertCol[$data]);
                }
            }
        }
        if($this->getId() === 0)
        {
            $id=Query::table(static::getTableName())->insert($insertCol)->run();
            $this->setId($id);
        }
        else
        {
            Query::table(static::getTableName())->update($insertCol)->where(['id','=',$this->getId()])->run();
        }
	}
    public function isAutoGenerated(string $key) : bool
    {
        return static::$model[$key]['AutoGenerated'] ?? false;
    }
    public static function getEnum(string $col)
    {
        return  static::$model[$col]['Enum'];
    }
    public static function isEnum(string $col)
    {
        return isset(static::$model[$col]['Enum']);
    }
    public function __call($name,$arguments)
    {
        $type=substr(strtolower($name),0,3);
        $property=substr($name,3,strlen($name));
        $col=substr($name,3,strlen($name));
        if(!in_array($col,$this->getColumns()))
            throw  new PropertyNotFoundException($col.": Object Property Not Found");
        $coltype=static::$model[$col]['Type']??"";
        $col=(static::$model[$col]['ColumnName']??$col);
        if($type == "set")
        {
            switch($coltype)
            {
                case "DateTime":
                    if(!is_object($arguments[0]) || get_class($arguments[0]) !== 'CORE\Date')
                        throw new InvalidArgumentException("Invalid Datatype. The Value must be type of \CORE\Date, use \CORE\Date::now() to get latest date.");

                    $this->updateData($col,($arguments[0])->getDatabaseDate());
                    break;
                case "Number":
                    if(!is_numeric($arguments[0]))
                        throw new InvalidArgumentException("Invalid Datatype. The Value must be type of Number.");
                    $this->updateData($col,floatval($arguments[0]));
                    break;
                case "Int":
                    if(!is_int($arguments[0]))
                        throw new InvalidArgumentException("Invalid Datatype. The Value must be type of int.");
                    $this->updateData($col,intval($arguments[0]));
                    break;
                case 'Enum':
                    $class = static::getModelProperties()[$property]['Enum'];
                    if(!$class::isValid($arguments[0]))
                        throw new InvalidArgumentException("Invalid Enum Value ".$arguments[0]);
                    $this->updateData($col,$arguments[0]);
                    break;
                case 'Model':
                    $rules = static::getForeignKeys()[$property];
                    $data = explode(":", $rules['ForeignKey']);
                    $class = $data[0];
                    if(get_class($arguments[0]) !== trim($class,'\\'))
                    {
                        throw new \CORE\MVC\ModelErrorException("Invalid Object Type " . get_class($arguments[0]) . ". Object must be an instance of ".trim($class,'\\'));
                    }
                    $property1 = $data[1];
                    if(!empty($arguments[0]->{"get".$property1}()))
                    {
                        if(count($class::find()->where([$property1, '=', $arguments[0]->{"get".$property1}()])->toList()) === 0)
                        {
                            throw new \CORE\MVC\ModelErrorException("Invalid Foreign Key ".$class."::$property1 of value ".($arguments[0]->{"get".$property1}()).". It doesn't exists in database.");
                        }
                        $this->updateData($col,$arguments[0]->{"get".$property1}());
                        $this->cache[$property] = $arguments[0];
                    }
                    break;
                case 'Array':
                    if(!is_array($arguments[0]))
                        throw new InvalidArgumentException("Invalid Datatype. The Value must be type of Array.");
                    $this->updateData($col,implode(",", $arguments[0]));
                    break;
                case 'String':
                case 'Name':
                    if(!is_string($arguments[0]))
                        throw new InvalidArgumentException("Invalid Datatype. The Value must be type of string.");
                    $this->updateData($col,$arguments[0]);
                    break;
                default:
                    $this->updateData($col,$arguments[0]);
                    break;
            }
        }
        else if($type === "get")
        {
            switch($coltype)
            {
                case "DateTime":
                    return isset($this->data[$col]) ? (new Date($this->data[$col])) : null;
                    break;
                case "Number":
                    return floatval($this->data[$col] ?? null);
                    break;
                case "Int":
                    return intval($this->data[$col] ?? null);
                    break;
                case 'Enum':
                    $class = static::getModelProperties()[$property]['Enum'];
                    return new $class($this->data[$col] ?? static::getModelProperties()[$property]['Default']);
                    break;
                case "Model":
                    $rules = static::getForeignKeys()[$property];
                    $data = explode(":", $rules['ForeignKey']);
                    $class = $data[0];
                    $property1 = $data[1];

                    if(!isset($this->cache[$property]))
					{
						if(!isset($this->data[$col]))
                        return null;
                        else
                        $this->cache[$property] = $class::find()->where($property1, '=', $this->data[$col])->single();
					}
                    return $this->cache[$property];
                    break;
                case 'Array':
                    return explode(",", $this->data[$col]) ?? null;
                    break;
                default:
                    return $this->data[$col] ?? null;
                    break;
            }
        }
    }
    public static function find() : Query
    {
        return Query::table(static::getTableName(), static::class);
    }
    public static function getClass() : string
    {
        return static::class;
    }
    public static function getModelProperties()
    {
        return static::$model;//static::class
    }
    public static function selectAs(string $key = '')
    {
        $suffix = $property = '';
        $key = explode('.',$key);
        if(count($key) > 1)
        {
            $suffix = $key[0];
            $property = $key[1];
        }
        else if(strlen($key[0])>0)
        {
            $suffix = $key[0];
            $property = '*';
        }
        else
        {
            $suffix = $class;
            $property = '*';
        }
        return new \CORE\DB\Join(static::getClass(), $suffix, $property);
    }
    public function __toString()
    {
        $array=[];
        $cols=$this->getColumns();
        foreach($cols as $data)
        {
            try
            {
                $array[$data]=$this->{"get".$data}() ?? null;
                if(is_object($array[$data]))
                {
                    $array[$data] = (string) $array[$data];
                }
            }
            catch(\ErrorException $e)
            {

            }
            catch(\Exception $e)
            {

            }
        }
       return json_encode($array);
    }
}