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
	
	// обязательно они должны повторять код, тк если тут будет в функции $this  - выдасться ошибка. а в __call - нет 
	public static function __callStatic(string $name, array $arguments) 
	{
		if(isset(static::$_methods[$name]))
			return call_user_func_array(static::$methods[$name], $arguments);
		else
			throw new Error('Метод '.$name.' не существует в классе');
    }	
}