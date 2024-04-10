<?php
// класс для создания матрицы 2д мира
final class Map2D
{
	private static array $_sides;			// информация о смежных и текущей карте	

	private static array $_info;			// информация о картах				
	private static array $_terrain;			// матрица включающая и текущую  и смежные карты со относительными координатами	
	private static array $_current;			// текущей карты (нужно что бы предложить случайную если появляется на недоступной)
	
	private function __construct(){}
	
	public static function init(array $sides, array $maps)
	{
		if(!DEFINED('MAP_ID'))
			throw new Error('не указана текущая карта и сервер карты не запущен');		
		
		if(isset(static::$_sides))
			throw new Error('Матрица карты уже была создана');
				
		if(APP_DEBUG)
			PHP::log('Инициализация для создания матрицу 2D карты при инициализации');
		
		// конкретно для этого фреймворка ничего кроме высоты и ширины карты в пикселях не нужно (хотя там есть в массиве и войства произвольные слоев и тайлов но это все сугубо индивидуально для реализации в конкретной игре)
		foreach($maps as $map_id=>&$map)
		{
			static::$_info[$map_id] = ['width'=>$map['width'], 'height'=>$map['height']];
		}
		
		// сначало текущую карту созддим матрицу областей
		static::$_terrain = static::$_current = static::getTerrain($maps[MAP_ID]);
		
		if(empty(static::$_current))
			throw new Error('на текущей карты нет свободных клеток для движения');
		
		if(APP_DEBUG)
			PHP::log('Собираем координаты текущей и соседних (со смещением в относительно текущей сетку координат) локаций');
		
		$delimetr = Position::DELIMETR;
		if(static::$_sides = $sides)
		{
			// если карта не указана возвращаем центральную со смежными областями
			foreach($sides as $map_id=>$side)
			{
				if($map_id == MAP_ID) continue;
				
				if(APP_DEBUG)
					PHP::log('дополним матрицу игровой карты смежной локацией '.$map_id);
		
				foreach(array_keys(static::getTerrain($maps[$map_id])) as &$position)
				{
					$explode = explode($delimetr, $position);
					
					static::encode2dCoord($explode[0], $explode[1], $map_id, MAP_ID);
					$new_position = $explode[0].$delimetr.$explode[1].$delimetr.$explode[2];
					
					if(isset(static::$_terrain[$new_position]))
						throw new Error('позиция '.$new_position.' ('.$position.') карты '.$map_id.' ('.$side.', '.$maps[$map_id]['width'].'x'.$maps[$map_id]['height'].') повторяется в обзей матрице');	
					
					static::$_terrain[$new_position] = [];
				}
			}	
		}	
		
		if(APP_DEBUG)
			PHP::log('Создадим матрицу с округлением позиции до единицы и указанияем куда из конкретной локации можно попасть  и какая для этого дистанция');
		
		// после того как собрали все воедино пройдемся еще раз что бы создать выходы из созданных локаций  и померить дистанции
		foreach(static::$_terrain as $position=>&$variants)
		{
			$explode = explode($delimetr, $position);

			// в отличие от матрицы World position мира здесь собираем клетки в которые мы можем ходить (тк мы можем ходить и на пол клетки но в матрице World будет она округляться до целой)
			for($forward_x=round(STEP*-1);$forward_x<=round(STEP*1);$forward_x+=round(STEP))
			{
				for($forward_y=round(STEP*-1);$forward_y<=round(STEP*1);$forward_y+=round(STEP))
				{
					if($forward_x == 0 && $forward_y == 0) continue;
					$tile = ($explode[0] + $forward_x).$delimetr.($explode[1] + $forward_y).$delimetr.$explode[2];
					
					// если позиция свободна то из текущей добавим путь в нее и из нее в текущую
					if(isset(static::$_terrain[$tile]))
					{
						// дистанция 
						$variants[$tile] = true;
					}						
				}	
			}	
		}
		if(APP_DEBUG)
			PHP::log('Матрица текущей и смежных карт создана '.print_r(array_keys(static::$_terrain), true));

		DomainLogic::init(static::$_terrain);		
	}
	
	public static function sides():array
	{
		return static::$_sides;
	}
	
	// вернет ширину и высоту карты в тайлах (не пикселях)
	public static function getInfo(int $map_id):array
	{
		if(empty(static::$_info[$map_id]))
			throw new Error('Запрос данных не инициализированной карты '.$map_id);
		
		return static::$_info[$map_id];
	}	

	public static function encode2dCoord(?float &$x, ?float &$y, int $from_map_id, int $to_map_id):void
	{
		if($x===null && $y===null) 
			throw new Error('Не переданы координаты для раскодировки');

		if($from_map_id == $to_map_id)
			throw new Error('Для проверки какие координаты будут на смежно карте карта источника и карта назначения должны быть разными ('.$from_map_id.' передано)');

		if($from_map_id != MAP_ID && $to_map_id!=MAP_ID)
			throw new Error('нельзя пересчитать координаты относительно одной смежной лкоации к другой (хотя бы один из субъектов должна быть текущая карта)');	

		if(!$side_from = @static::sides()[$from_map_id])
			throw new Error('Карта, которая с которой мы хотим получить координаты '.$from_map_id.' отсутствует в текущей области видимости');	

		if(!$side_to = @static::sides()[$to_map_id])
			throw new Error('Карта, относительно которой мы хотим получить координаты '.$to_map_id.' отсутствует в текущей области видимости');

		$old_x = $x;
		$old_y = $y;
		
		if($old_x!==null)
		{	
			// карта для изменения x координат должна НЕ быть ровно сверху или ровно снизу (может по бокам и по диагонали)	
			if($side_to['x']!=$side_from['x'])
			{
				if($to_map_id==MAP_ID)
					$x += $side_from['x'];
				else
					$x -= $side_to['x'];
				
				if($x == $old_x)
					throw new Error('Пересчет координат X для карты '.$to_map_id.' ('.$old_x.') относительно карты '.$from_map_id.' не дало результата');					
			}
			
			// с другой лкоации в координаты нашей
			if($to_map_id == MAP_ID)
			{
				if(ceil($x)>0 && $x<static::getInfo($to_map_id)['width'])
					throw new Error('При пересчете координат ('.$old_x.') с другой локации в текущу существо оказалось в границах координат текущей локации по x '.$x);					
			}	
		}
		if($old_y!==null)
		{	
			if($side_to['y']!=$side_from['y'])
			{
				if($to_map_id==MAP_ID)
					$y += $side_from['y'];
				else
					$y -= $side_to['y'];

				if($y == $old_y)
					throw new Error('Пересчет координат Y для карты '.$to_map_id.' ('.$old_y.') относительно карты '.$from_map_id.' не дало результата');					
			}
				
			// с другой лкоации в координаты нашей					
			if($to_map_id == MAP_ID)
			{
				if($y<0 && floor($y)>static::getInfo($to_map_id)['height'])
					throw new Error('При пересчете координат ('.$old_y.') с другой локации в текущу существо оказалось в границах координат текущей локации по y '.$y);	
			}						
		}
		
		if(APP_DEBUG)	
			PHP::log('Пересчет координат карты '.$from_map_id.' в координаты карты '.$to_map_id.': '.($old_x!==null?'x с '.$old_x.' на '.$x.', ':'').($old_y!==null?'y с '.$old_y.' на '.$y:''));
	}
	
	// возвращает ближайшую целую клетку к поданным координатам
	public static function getTile(string $coord):array
	{
		return static::$_terrain[$coord]??[];	
	}	
	
	// получить какие родные координатs (исключительно используется при добавлении существа на карту)
	public static function getCurrentMapTiles():array
	{
		return static::$_current;	
	}	
	
	// соберем сетку тайловую текущей карты исключим непроходимые области (все объекты)
	private static function getTerrain(array &$map)
	{	
		$objects = array();
		// получим все препятствия
		foreach($map['tileset'] as &$tileset)
		{
			if(!empty($tileset['tile']))
			{
				foreach($tileset['tile'] as &$tile)
				{
					// если есть физика у тайла в наборе
					if(!empty($tile['objects']))
					{
						$objects[$tile['tile_id']] = $tile['objects'];
					} 	
				}	
			}	
		} 			
		
		$polygons = array();
		$coliders = array();
		$num = 0;
	
		$delimetr = Position::DELIMETR;

		// сначала соберем все препятсвия со всех смежных ярусов в слоях
		foreach($map['layer'] as &$layer)
		{
			// не првоеряем какая там ширина высота у объекта - если он в рамках тайла то вся эта клетка непроходима
			if(!empty($layer['tiles']))
			{
				foreach($layer['tiles'] as $tile_num=>&$tile)
				{
					if(!empty($objects[$tile['tile_id']]) || strtolower($layer['name'])=='collision')
					{	
						$x = $tile_num % $map['columns'];
						if($y = intdiv($tile_num , $map['columns']))
							$y *= -1;
						
						$coliders[(int)$layer['offsetz']][$x.$delimetr.$y] = true;	
					}
				}						
			} 
			
			// todo пройти по всем остальным объектам и сделать из всех их область препятсвий
			if(!empty($layer['objects']) && $layer['visible'])
			{
				foreach($layer['objects'] as &$object)
				{
					if(!$object['visible'] || !empty($object['polyline'])) continue;
					
					$polygons[(int)$layer['offsetz']][$num] = new Polygon2D();
					if(!empty($object['polygon']))
					{
						foreach($object['polygon'] as &$point)
						{
							// переведем абсолютные координаты полигона на карте в номера тайла по x и y 
							$polygons[(int)$layer['offsetz']][$num]->addPoint($point['x']/$map['tilewidth'], $point['y']/$map['tileheight']);
						}					
					}
					else
					{	
						if(($object['width']??$map['tilewidth']) == $map['tilewidth'] && ($object['height']??$map['tileheight']) == $map['tileheight'])
							$coliders[(int)$layer['offsetz']][($object['x']/$map['tilewidth']).$delimetr.($object['y']/$map['tileheight'])] = true;
						else
						{
							// все остальыне объекты (круги, tiled и квадраты кроме линий - это полигоны)
							$polygons[(int)$layer['offsetz']][$num]->addPoint($object['x']/$map['tilewidth'], $object['y']/$map['tileheight']);
							$polygons[(int)$layer['offsetz']][$num]->addPoint(($object['x'] + ($object['width']??$map['tilewidth']))/$map['tilewidth'], $object['y']/$map['tileheight']);
							$polygons[(int)$layer['offsetz']][$num]->addPoint(($object['x'] + ($object['width']??$map['tilewidth']))/$map['tilewidth'], ($object['y']-($object['height']??$map['tileheight']))/$map['tileheight']);
							$polygons[(int)$layer['offsetz']][$num]->addPoint($object['x']/$map['tilewidth'], ($object['y']-($object['height']??$map['tileheight']))/$map['tileheight']);
						}
					}

					$num++;
				}
			}
		}	
		
		// пройдемся еще раз уже расставив возможные клетки для проходимости
		// сделаем матрицу карты
		// тк мы работаем в гео координатах съемитируем квадрат карты (tile) в виде 1 единицы широты и долготы 
		// принимаем во внимание что на клиенте идет расчет по x и y, те идя вверх y увиличиваются а вниз уменьшается (тоже и про x - влево уменьшается, вправо увеличивается)
		
		$links = array();
		foreach($map['layer'] as &$layer)
		{
			// может не ыть и слой являться слоем объектов
			if(!empty($layer['tiles']))
			{
				for($y=0; $y<$map['height']; $y++)											// высота карты
				{		
					for($x=0; $x<$map['width']; $x++)										// ширина карты
					{
						$num = $x+$y*$map['width'];
						if($y)
							$posy = $y*-1;
						else
							$posy = $y;
							
						if(!isset($layer['tiles'][$num]) || isset($coliders[(int)$layer['offsetz']][$x.$delimetr.$posy])) continue;
						
						// если эта область проходима можем туда двигаться
						if(!empty($polygons[(int)$layer['offsetz']]))
						{
							foreach($polygons[(int)$layer['offsetz']] as $polygon)
							{
								// если в этом полигоне наша позиция которую обрабатываем тидем на следуюющий тайл - это уже недоступен, нет смысла перебора других объектов
								if($polygon->contains($x, $posy))
									continue(2);										
							}
						}
						$links[$x.$delimetr.$posy.$delimetr.(int)$layer['offsetz']] = [];					
					}				
				}						
			} 	
		}	
		array_multisort(array_keys($links), SORT_NATURAL, $links);
								
		return $links;
	}
}