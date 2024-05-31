<?php
abstract class Perfomance
{
	private static float $_time;				// время последней записи в лог
	private static array $_abs = array();		// массив значений таймингов 
	
	private static $_log_context;				// открытый фаиловый дескриптор на запись
	
	private function __construct(){}
	public static function set(string $name, float $msec, string $units = 'мс.')
	{
		// после третьего знака после запятой в мс - не считаем в логи (это почти ничто)
		if(!$msec = round($msec, 3)) return;
		
		$microtime = microtime(true);
		if(empty(static::$_time)) static::$_time = $microtime + PERFOMANCE_TIMEOUT;

		if(empty(static::$_abs[$name][$units]))
			static::$_abs[$name][$units] = array();
		
		static::$_abs[$name][$units][] = $msec;
		
		// что бы не спамить на сервер запросами при каждом вызове perfomance - будет собирать их тут и отправлять пачками
		if(static::$_time < $microtime)
		{
			static::$_time = $microtime + PERFOMANCE_TIMEOUT;
			
			$logs = array();
			
			static::$_abs["Sandbox        | Оперативная память (выделенная)"]['Мбайт'][] = round(memory_get_usage(true)/1000000, 3);	
			static::$_abs["Sandbox        | Оперативная память (используемая)"]['Мбайт'][] = round(memory_get_usage()/1000000, 3);		
			
			$count = count(array_filter(World::all(), function($entity){ return $entity->map_id == MAP_ID; }));
			static::$_abs["Sandbox        | Количество объектов текущей карты (тратят CPU + RAM + время кадра)"]['шт.'][] = $count;		
			static::$_abs["Sandbox        | Количество копий объектов со смежных карт (тратят RAM)"]['шт.'][] = World::count() - $count;		
			
			foreach(static::$_abs as $name=>$data)
			{
				foreach($data as $unit=>$msec)
				{				
					if($total = array_sum($msec)/count($msec))
					{
						$logs[$name] = [$total, $unit];
					}
				}
			}
			
			static::$_abs = array();
			if($logs)	
				PHP::perfomance($logs);					
		}	
	}
}