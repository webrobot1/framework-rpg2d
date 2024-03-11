<?php
// класс для работы с открытым миром и  добавления в нее объектов
// это статическая коллекция существ
abstract class World 
{
	use AddMethodsTrait;
	
	private static array $_entitys 		= array();				// коллеция существ
	
	private static array $_codes 		= array();				// код срабатываемый при загрузки сущеностей
	private static Closure $_position_trigger;					// код срабатываемый при смене позиции
		
	private static array $_positions 	= array();				// что бы не перебирать ВСЕ объекты методом filter - сделаем матрицу где ключ - позиция объекта и перебираем только нужные		
	private static array $_remove = array();					// массив существ к удалению (сразу удалять нельзя из масива существ тк надо их изменения собрать еще)
	

	private function __construct(){}

	// добавить код который сработает на сущность 
	//	при доблавении или удалении
	//	при смене ее координат - этот метод запускается при старте game Server передавая в песочницу этот код который берет из базы в админ панели записанный
	public static function init(array $list, array $info)
	{
		if(APP_DEBUG)
			PHP::log('Инициализация кода смены позиций');
			
		if(!empty($info['code']))
		{
			try
			{
				static::$_position_trigger = eval('return static function(EntityAbstract $object, ?Position $old = null):void{
					'.$info['code'].'  
				};');	
			}
			catch(Throwable $ex)
			{
				throw new Error('code(position): Ошибка компиляции кода изменения позиции '.$ex);
			}				
		}
		
		if(APP_DEBUG)
			PHP::log('Инициализация типов и кода добавления/удаления существ');
	
		// Предупреждения в отличие от стандартного поведения в PHP теперь вызывают исключения EventException
		// тк для игры предупреждения не допустимы - они пишутся в лог занимают время за сам факт того что они есть
		// плюс расшифровка их логов если оставить из стандартно для всяких предупреждений из eval затруднительна , а для EventException уже есть решения
		set_error_handler(function($errno, $errstr, $errfile, $errline)
		{
			if($errno != 0)
				throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		});
		
		foreach($list as $entity_type=>$entity)
		{
			foreach(['in', 'out'] as $type)
			{
				// создадим из текстовой версии кода которая нам пришла замыкание которое можно будет вызывать при сменен компонет а
				if(!empty($entity[$type]['code']))
				{
					if(APP_DEBUG)
						PHP::log('Создаем триггер '.$type.' для сущности '.$entity_type);
					
					try
					{
						static::$_codes[$entity_type][$type] = eval('return static function(EntityAbstract $object'.($type=='out'?', ?int $new_map':'').'):void{ 
								'.$entity[$type]['code'].' 
						};');	
					}
					catch(Throwable $ex)
					{
						throw new Error('code('.$entity_type.'/'.$type.'): Ошибка компиляции кода сущности '.$ex);
					}	
				}
			}
		}
	}

	// возвращает ключ созданного существа или null если существо создается на другой карте и появится в следующей снхронизации с ней (в следующих картах) - если нужно повесить события передавайте их сразу
	public static function add(EntityAbstract $entity):string
	{
		if (array_key_exists($entity->key, static::$_entitys)) 
		{
			throw new Error('сущность '.$entity->key.' уже есть в группе');
		}
		
		if(Block::$objects)
			throw new Error('Стоит запрет на добавление новых существ во избежание зацикливание');
		
		if(APP_DEBUG)
			$entity->log('добавления сущности '.$entity->key.' в мир '.($entity->map_id != MAP_ID?$entity->map_id.' локации'.($entity->remote_update?' на исполнение':' на очередь'):''));
		
		if($entity->map_id==MAP_ID || $entity->remote_update)
		{		
			if($entity->map_id == MAP_ID)
			{
				// при добавлении на карту (не путать с временем созданием сущности) тригернем компоненты (будто они все изменили значения что бы сработал их код пользовательский). именно тригер а не перезапись одного и того же
				// именно так передаем значение тк другие компоненты могли уже его поменять и мы не сможем сравнить по ним входим ли в игру сейчас
				foreach($entity->components->keys() as $name) $entity->components->trigger($name, $entity->components->get($name));

				if(!empty(static::$_codes[$entity->type]['in']))
				{
					try
					{
						// запретим добавления новых объектов тк это вызовет зацикливание если уже не
						$recover = Block::current();
						Block::$objects = true;	
									
						$closure = &static::$_codes[$entity->type]['in'];
						$closure($entity);
					}
					finally
					{
						// вернем все как было
						Block::recover($recover);
					}
				}
			}

			static::$_entitys[$entity->key] = $entity;
			static::addPosition($entity->key);
		}
		// если существо для другой локации просто отправим на нее пакет что мы хотим его создать не рассылая ничего
		else
		{
			static::create_remote_entity($entity);
			$entity->__destruct();
		}

		return $entity->key;
	}		

	// создание сущности на лругой лкоации - через код API или при переходе
	private static function create_remote_entity(EntityAbstract $entity, ?int $map_id = null)
	{
		$data = array();
		$data = $entity->toArray();

		if($privates_components = $entity->components->privates())
			$data['components'] = array_replace_recursive($data['components']??[], $privates_components);
	
		Channel::create_remote_entity($entity->key, $data, $map_id);
	}	

	public static function isRemove(string $key):bool
	{
		return array_key_exists($key, static::$_remove);
	}
	
	public static function remove(string $key, int $new_map = null):void
	{
		if(isset(static::$_entitys[$key]))
        {   
			if(!static::isRemove($key))
			{
				$entity = static::get($key);

				if(!$entity->remote_update && $entity->map_id != MAP_ID)
					throw new Error('нельзя напрямую удалять существ с другой карты');      
				
				if($new_map)
				{
					if($new_map == MAP_ID)
						throw new Error('нельзя удалить с пометкой о переходе на новую карту если она равна текущей');
					
					if($entity->map_id!=MAP_ID)
						throw new Error('При удалении существа с другой локации нельзя указывать ему новую карту');
				}
				
				if(APP_DEBUG)
					$entity->log('добавление в очередь на удаление сущености '.($entity->map_id!=MAP_ID?' с другой карты':'').($new_map?' по причине перехода на новую карту '.$new_map:''));
				 
				static::$_remove[$key] = $new_map;

				// если пришло пакетом с другой локации не ставим изменением
				if($entity->map_id==MAP_ID)
				{
					if(!empty(static::$_codes[$entity->type]['out']))
					{
						$closure = &static::$_codes[$entity->type]['out'];
						$closure($entity, $new_map);
					}
					
					$entity->setChanges(['action'=>SystemActionEnum::ACTION_REMOVE], EntityChangeTypeEnum::All);
				}
			}
        }
        // todo сделать exception и флаг в entitys  и в websocket на то что бы не отправлять обратно запрос сюда при удалении из entitys сначала
        else
            throw new Error('сущность '.$key.' не может быть удалена по причине отсутствия ее в коллекции');
	}		
	
	public static function count():int
	{
		return count(static::$_entitys);
	}

	public static function keys():array
	{
		return array_keys(static::$_entitys);
	}	
	
	public static function all():array
	{
		return static::$_entitys;
	}

	
	final public static function isset(string $key):bool
	{
		return isset(static::$_entitys[$key]);		
	}

	public static function get(string $key):EntityAbstract
	{
		if(!isset(static::$_entitys[$key]))
			throw new Error('Сущности '.$key.' не существует');
			
		return static::$_entitys[$key];		
	}
	
	// в матрице существа хранятся в клетках размером с тайл (1х1 в системе координат) хотя ходить могут и на пол клетки и на полторы и тд
	public static function addPosition(string $key, Position $old_value = null)
	{
		if(!$entity = static::$_entitys[$key])
			throw new Error('Сущности '.$key.' не найдено для доблавения ее в матрицу позиций');
		
		$tile = $entity->position->tile();
		
		if(APP_DEBUG)
		{
			$entity->log('добавление на позицию '.$tile);
			
			// метод addPosition может запускаться 	только при доблавлении на карту существа из текущего класса и при смене позиций в EntityAbstract::__set
			// но проверка эта запускается в режиме отладки
			if
			(
				(!$trace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)) 
					|| 
				(($trace[1]['class']!=static::class || $trace[1]['function']!='add') && ($trace[1]['class']!=EntityAbstract::class || $trace[1]['function']!='__set'))
			)
				throw new Error('Запуск кода смены позиции можно лишь ишь при добавлении существа в World или при изменении данных '.print_r($trace, true));
		}
		
		// это должно быть именно тут , не в Position (тк метод update может вызываться принудительно минуя свойство ->position)
		if($old_value)
			static::removePosition($key, $old_value);
		
		static::$_positions[$tile][$key] = $key;

		// это вызовет триггер кода смены позиций 
		if($entity->map_id == MAP_ID && !empty(static::$_position_trigger) && !static::isRemove($key))
		{
			if(APP_DEBUG)
				$entity->log('запустим триггер песочницы изменения позиции');
			
			try
			{
				$recover = Block::current();
				Block::$positions = true;			// запрет менять координаты
				Block::$objects = true;				// запрет добавление объектов
			
				call_user_func(static::$_position_trigger, $entity, $old_value);
			}
			finally
			{
				Block::recover($recover);	
			}		
		}	
	}
	
	private static function removePosition(string $key, Position $old_value)
	{
		if(!$entity = static::$_entitys[$key])
			throw new Error('Сущности '.$key.' не найдено для удаления ее из матрицы позиций');
		
		$tile = $old_value->tile();
		
		if(APP_DEBUG)
			$entity->log('удаление с позиции '.$tile);
		
		if(isset(static::$_positions[$tile][$key]))
		{
			unset(static::$_positions[$tile][$key]);		
			if(empty(static::$_positions[$tile]))
				unset(static::$_positions[$tile]);
		}
	}	

	// кто в округе есть. если хотим получить всех а не первого попавшего то count ставим 0 или null 
	// если нет дистанции то пол шаг с каждой стороны (вместе шаг)
	final static public function filter(Position $position, ?Closure $filter = null, float $distance = 0, ?int $count = 1):array
	{
		if($distance<0) 
			throw new Error("Дистанция должна больше или равна нулю");

		$array = [];	
		for($x = STEP*$distance*-1; $x<=STEP*$distance; $x+=STEP)
		{		
			for($y = STEP*$distance*-1; $y<=STEP*$distance; $y+=STEP)
			{
				if($x == 0 && $y == 0)
					$new_position = $position->tile();
				else
					$new_position = $position->add(new Position($x, $y))->tile();

				if(!empty(static::$_positions[$new_position]))
				{
					foreach(static::$_positions[$new_position] as $key)
					{
						if(!isset(static::$_entitys[$key]))
							throw new Error($key.': Существо отмечено на локации '.$new_position.' , но отсутвует в коллекции');
						
						if($filter)
						{ 
							$return = $filter(static::get($key));
							if(!is_bool($return))
								throw new Error('Функции фильтрации существ на карте должны возвращать значение типа bool (true или false)');

							if(!$return)
								continue;	
						}					
						$array[$key] = static::get($key);
						
						if($count && count($array)==$count)
							return $array;	
					}
				}	
			}
		}		
		return $array;
	}
	
	// включает в себя сбор изменений, их рассылку и удаление сущностей
	public static function refresh()
	{
		if(static::count())
		{
			if(static::$_remove)
			{
				if(APP_DEBUG)
					PHP::log('отправка в websocket пакетов удаления и перехода существ с игрового сервера');

				foreach(static::$_remove as $key=>$new_map)
				{
					if($new_map && static::$_entitys[$key]->map_id != MAP_ID)
						throw new Error('Удаление сущности по причине смены карты должно происходить на его родной локации');
					
					if(APP_DEBUG)
					{
						if($new_map)
							static::$_entitys[$key]->log("перемещение на карту ".$new_map);
						else
							static::$_entitys[$key]->log("удаление с карты");
					}
					
					if(static::$_entitys[$key]->map_id == MAP_ID)
					{	
						if(static::$_entitys[$key]->type == EntityTypeEnum::Players->value)
						{									
							static::$_entitys[$key]->save($new_map);
							if(APP_DEBUG)
								static::$_entitys[$key]->log("сохранен при выходе из игры");
							
							Channel::player_remove($key);							
						}
						elseif($new_map)
						{
							if(APP_DEBUG)
								static::$_entitys[$key]->log("Отправка пакета о переходе с карты ".MAP_ID." на ".$new_map);
							
							static::create_remote_entity(World::get($key), $new_map);	
						}
					}
					
					unset(static::$_remove[$key]);
					static::removePosition($key, static::$_entitys[$key]->position);
					
					static::$_entitys[$key]->__destruct();

					unset(static::$_entitys[$key]);
				}	
			}	
		}		
	}

	// подготовим для game server массив изменений существ
	public static function collected():array
	{	
		// подготавливаем данные для отправки только если на нашем сервере кто то есть (в тч  не жиилые NPC данные которых меняют соседние локации на которых кто то есть живой)
		$publish = [];
		// первый раз пройдемся что бы полуичить что в игровом мире изменилось
		foreach(static::$_entitys as $key=>$entity)
		{	
			// проверим изменился ли объект что бы его отправить
			if($changes = $entity->getChanges())
			{					
				$publish = array_replace_recursive($publish, static::formatChanges($entity->key, $entity->type, $entity->map_id, $changes));	
			}					
		}

		if($publish)	
			Channel::send_changes($publish);

		return Channel::collected();		
	}

	private static function formatChanges(string $key, string $entity_type, int $map_id, array $data):array
	{
		$publish = array();
		foreach($data as $type=>$value)
		{
			if(!empty($changes[$type]['map_id']))
			{
				if($map_id == MAP_ID && $changes[$type]['map_id'] != MAP_ID && !isset($changes[$type]['x']) && !isset($changes[$type]['y']))
					throw new Error('Сменена карта существу, но не сменена позиция его - непонятно куда ставить существо на новой карте');
			}
					
			$publish[$type][$map_id][$entity_type][$key] = $value;
		}		
		return $publish;
	} 
}