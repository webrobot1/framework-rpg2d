<?php
trait AddMethodsTrait
{
	private static $_methods = array();
	
	// добавляет в приложение новые метод (может быть как аннимной функцией так и методом из другого класса)
	// пример static::addMethod('last', \Closure::fromCallable([static::$_containers[static::app()], 'last']));
	public static function addMethod(string $name, $callable):void
	{
		if(isset(static::$_methods[$name])) 
			throw new Error('Метод '.$name.' уже был добавлен в пространство класса');
		
		static::$_methods[$name] = $callable;		
    }
	
	final public function __call($name, $args) 
	{
		return static::__callStatic($name, $args);
    }		
	
	// обязательно они должны повторять код, тк если тут будет в функции $this  - выдасться ошибка. а в __call - нет 
	final public static function __callStatic($name, $args) 
	{
		if(isset(static::$_methods[$name]))
			return call_user_func_array(static::$methods[$name], $args);
		else
			throw new Error('Метод '.$name.' не существует в классе');
    }	
}