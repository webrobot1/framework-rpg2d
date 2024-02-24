<?php

abstract class Perfomance
{
	private static float $_time;				// время последней записи в лог
	private static array $_abs = array();		// массив значений таймингов 
	
	private static $_log_context;				// открытый фаиловый дескриптор на запись
	
	private function __construct(){}

	// здесь не закрвается файловый контекст так что можно писать в фаил и текущем потоке
	// todo замерить запись в фаил тут
	public static function set(string $name, float $msec)
	{
		// после третьего знака после запятой в мс - не считаем в логи (это почти ничто)
		if(!$msec = round($msec, 3)) return;
		
		$microtime = microtime(true);
		if(empty(static::$_time)) static::$_time = $microtime + PERFOMANCE_TIMEOUT;

		if(empty(static::$_abs[$name]))
			static::$_abs[$name] = array();
		
		static::$_abs[$name][] = $msec;
		
		if(static::$_time < $microtime)
		{
			static::$_time = $microtime + PERFOMANCE_TIMEOUT;
			
			$logs = array();
			
			static::$_abs["Sandbox      | оперативная память (выделенная) Мбайт"][] = round(memory_get_usage(true)/1000000, 3);	
			static::$_abs["Sandbox      | оперативная память (используемая) Мбайт"][] = round(memory_get_usage()/1000000, 3);		
			
			$count = count(array_filter(World::all(), function($entity){ return $entity->map_id == MAP_ID; }));
			static::$_abs["Sandbox      | количество объектов текущей карты (тратят CPU + RAM + время кадра)"][] = $count;		
			static::$_abs["Sandbox      | количество копий объектов со смежных карт (тратят RAM)"][] = World::count() - $count;		
						
			foreach(static::$_abs as $name=>$msec)
			{
				if($total = array_sum($msec)/count($msec))
				{
					$len = mb_strlen(trim($name));
					$logs[$name] = trim($name).($len<100?str_repeat(" ", 100-$len):'').": ".str_pad(round($total, 3), 10, ' ').(count($msec)>1?' ('.min($msec).' - '.max($msec).')':'');
				}
			}
			
			static::$_abs = array();
			if($logs)
			{
				ksort($logs);				
				$start = hrtime(true);
				PHP::perfomance(implode(PHP_EOL, $logs));
	
				static::$_abs["Sandbox      | отправка в WebSocket всех perfomance мс."][] = round((hrtime(true) - $start)/1e+6, 3);					
			}	
		}	
	}
}