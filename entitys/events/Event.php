<?php
// это просто структура нового события в группе событий сущности
class Event
{
	function __construct
	(	
		public string $action,				// какой метод запускаем
		public array $data = array(),		// вспомогательные даныt где ключ массива это название аргумента в методе класса Event'a
		public bool $from_client = false	// флаг указвающий что команда пришла с клиента
	)
	{
		if(empty($action))
			throw new Error('При добавления собятия поле action не может быть пустым');	
	}
}