<?php
// не делаем объект итерируемым тк это сильные просадки в скорости (500 000 - 1.000.000 RPS, а тк вызовы коллекций постоянны то это долго)
abstract class AbstractCollection 
{
	protected array $values = array();					// массив объектов EventPHP - комманд пришедших на выполнение в виде [eventClassN=>[action, data], ...]

	function __construct()
	{
		if(!method_exists($this, 'add'))
			throw new Error('Метод add обязатель для реализации в класс '.static::class);
	}
	
	public function remove(string $key): void
	{
		unset($this->values[$key]);
    }
	
	final public function exists(string $key): bool 
	{
		return array_key_exists($key, $this->values);
    }	

	final public function isset(string $key): bool 
	{
		return isset($this->values[$key]);
    }
	
	public function get(string $key): mixed 
	{
		if (!array_key_exists($key, $this->values)) 
		{
			throw new Error('Объект '.$key.' отсутвует в коллекции '.static::class);
		}

		return $this->values[$key];
    }
	
	// todo переименовать на values
	final public function all():array
	{
		return $this->values;			
	}	
	
	final public function keys():array
	{
		return array_keys($this->all());
	}

	final public function count(): int
    {
        return count($this->all());
    }
		
	function __clone():void
	{
		throw new Error('Клонирование коллекций запрещено');
	}
	
	final public function __toString() 
    {
        return implode(',', array_keys($this->all()));
    }
	
	// я не хочу делать виртуальный доступ к ->get если свойство есть тк это чуть медленнее и лишает единообразия доступа 
	public function __get(string $key):mixed
	{
		throw new Error('поле '. $key.' недоступно для чтения в коллекции '.static::class);	
	}	
	
	public function __set(string $key, mixed $value):void
	{
		throw new Error('поле '. $key.' недоступно для изменении в коллекции '.static::class);	
	}		
}