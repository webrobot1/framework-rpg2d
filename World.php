<?php
// класс для работы с открытым миром и  добавления в нее объектов
// это статическая коллекция существ
abstract class World extends Channel
{
	use AddMethodsTrait;
	
	private static array $_entitys 		= array();				// коллеция существ
	
	protected static array $entitys_closures;					// код срабатываемый при загрузки сущеностей
	private static Closure $_position_trigger;					// код срабатываемый при смене позиции
		
	private static array $_positions 	= array();				// что бы не перебирать ВСЕ объекты методом filter - сделаем матрицу где ключ - позиция объекта и перебираем только нужные		
	private static array $_remove = array();					// массив существ к удалению (сразу удалять нельзя из масива существ тк надо их изменения собрать еще)
	
	// добавить код который сработает на сущность 
	//	при доблавении или удалении
	//	при смене ее координат - этот метод запускается при старте game Server передавая в песочницу этот код который берет из базы в админ панели записанный
	public final static function init(array $codes, ?Closure $positions)
	{
		if(isset(self::$entitys_closures))
			throw new Error('Инициализация игровой системы уже была произведена');

		if((self::$entitys_closures = $codes) && APP_DEBUG)
		{
			foreach(self::$entitys_closures as $entity_type=>$codes)
			{			
				foreach($codes as $type=>$code)
				{
					PHP::log('Инициализация кода '.$type.' существ типа '.$entity_type);
				}
			}
		}
		
		if($positions)
		{
			if(APP_DEBUG)
				PHP::log('Инициализация кода смены позиции');
					
			self::$_position_trigger = $positions; 
		}
							
		if(APP_DEBUG)
			PHP::log('Инициализация типов и кода добавления/удаления существ');
	}

	// возвращает ключ созданного существа или null если существо создается на другой карте и появится в следующей снхронизации с ней (в следующих картах) - если нужно повесить события передавайте их сразу
	public final static function add(EntityTypeEnum $type, array $data):EntityAbstract|string|null
	{
		if(Block::$objects)
			throw new Error('Стоит запрет на добавление новых существ во избежание зацикливание');
		
		if(empty($data['map_id']))
			throw new Error('При создании сущности не указана карта для которой требуется эту сущность создать (можно создавать и на соседних) в пакете'.print_r($data, true)); 
		
		$class = ucfirst($type->value);	
		if(!empty(self::$entitys_closures[$type->value]['in']))
		{
			try
			{
				// запретим добавления новых объектов тк это вызовет зацикливание если уже не
				$recover = Block::current();
				Block::$objects = true;	
							
				$closure = &self::$entitys_closures[$type->value]['in'];
				if($entity = $closure($data))
				{	
					if($entity === true)
					{
						$entity = (new $class(...$data));
					}
					elseif(!($entity instanceOf $class))
						throw new Error('Существо должно быть экземпляром класса '.$class.' созданное из массива '.print_r($data));
				}
				else
				{
					if($data['map_id']!=MAP_ID)
						throw new Error('Нельзя отменить создание копии сущности другой локации в текущей');
					
					if(APP_DEBUG)
						$entity->log('Отменено создание сущности '.$entity->key.' добавляемую в текущую локацию ');
					
					return null;
				}
			}
			finally
			{
				// вернем все как было
				Block::recover($recover);
			}
		}
		else
		{
			$entity = new $class(...$data);
		}
	
		if($data['map_id']==MAP_ID || !empty($data['permament_update']))
		{	
			if($entity->map_id == MAP_ID)
			{
				// копоненты могут слать игроку send данные (например в calculateTimeoutCache) но в websocket должен сначала прийти пакет сущности игрок что бы ему слать что либо (а то вышлем пакет игроку которого еще и на сцене в клиенте нет) с данными по нему же самому
				if($entity->type == EntityTypeEnum::Players)
				{
					// проверим изменился ли объект что бы его отправить
					parent::send_changes(self::formatChanges($entity->key, $entity->type->value, $entity->map_id, $entity->getChanges()));
				}
				
				// при добавлении на карту (не путать с временем созданием сущности) тригернем компоненты (будто они все изменили значения что бы сработал их код пользовательский). именно тригер а не перезапись одного и того же
				// именно так передаем значение тк другие компоненты могли уже его поменять и мы не сможем сравнить по ним входим ли в игру сейчас
				foreach($entity->components->keys() as $name) $entity->components->trigger($name, $entity->components->get($name));
			}

			self::$_entitys[$entity->key] = $entity;
			self::addPosition($entity->key);

			if(APP_DEBUG)
				$entity->log('добавления сущности '.$entity->key.' в мир '.($entity->map_id != MAP_ID?$entity->map_id.' локации'.($entity->permament_update?' на исполнение':' на текущей локации'):''));
		
			return $entity;
		}
		// если существо для другой локации просто отправим на нее пакет что мы хотим его создать не рассылая ничего
		else
		{
			parent::create_remote_entity($entity);
			$entity->__destruct();
			
			if(APP_DEBUG)
				$entity->log('Добавление '.$entity->key.' на очередь доблавения на локацию '.$entity->map_id);
			
			return $entity->key();
		}	
	}		

	public final static function isRemove(string $key):bool
	{
		return array_key_exists($key, self::$_remove);
	}
	
	public final static function removeEntity(string $key, int $new_map_id = null):void
	{
		if(isset(self::$_entitys[$key]))
        {   
			if(!self::isRemove($key))
			{
				$entity = self::get($key);

				if(!$entity->permament_update && $entity->map_id != MAP_ID)
					throw new Error('нельзя напрямую удалять существ с другой карты');      
				
				if($new_map_id)
				{
					if($new_map_id == MAP_ID)
						throw new Error('нельзя удалить с пометкой о переходе на новую карту если она равна текущей');
					
					if($entity->map_id!=MAP_ID)
						throw new Error('При удалении существа с другой локации нельзя указывать ему новую карту');
				}
				
				if(APP_DEBUG)
					$entity->log('добавление в очередь на удаление сущености '.($entity->map_id!=MAP_ID?' с другой карты':'').($new_map_id?' по причине перехода на новую карту '.$new_map_id:''));
				 
				self::$_remove[$key] = $new_map_id;

				// если пришло пакетом с другой локации не ставим изменением
				if($entity->map_id==MAP_ID)
				{
					if(!empty(self::$entitys_closures[$entity->type->value]['out']))
					{
						$closure = &self::$entitys_closures[$entity->type->value]['out'];
						$closure($entity, $new_map_id);
					}
					
					$entity->setChanges(['action'=>SystemActionEnum::ACTION_REMOVE], EntityChangeTypeEnum::All);
				}
			}
        }
        // todo сделать exception и флаг в entitys  и в websocket на то что бы не отправлять обратно запрос сюда при удалении из entitys сначала
        else
            throw new Error('сущность '.$key.' не может быть удалена по причине отсутствия ее в коллекции');
	}		
	
	public final static function count():int
	{
		return count(self::$_entitys);
	}

	public final static function keys():array
	{
		return array_keys(self::$_entitys);
	}	
	
	public final static function all():array
	{
		return self::$_entitys;
	}

	
	public final static function isset(string $key):bool
	{
		return isset(self::$_entitys[$key]);		
	}

	public final static function get(string $key):EntityAbstract
	{
		if(!isset(self::$_entitys[$key]))
			throw new Error('Сущности '.$key.' не существует');
			
		return self::$_entitys[$key];		
	}
	
	// в матрице существа хранятся в клетках размером с тайл (1х1 в системе координат) хотя ходить могут и на пол клетки и на полторы и тд
	public final static function addPosition(string $key, Position $old_value = null)
	{
		if(!$entity = self::$_entitys[$key])
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
				(($trace[1]['class']!=self::class || $trace[1]['function']!='add') && ($trace[1]['class']!=EntityAbstract::class || $trace[1]['function']!='__set'))
			)
				throw new Error('Запуск кода смены позиции можно лишь ишь при добавлении существа в World или при изменении данных '.print_r($trace, true));
		}
		
		// это должно быть именно тут , не в Position (тк метод update может вызываться принудительно минуя свойство ->position)
		if($old_value)
			self::removePosition($key, $old_value);
		
		self::$_positions[$tile][$key] = $key;

		// это вызовет триггер кода смены позиций 
		if($entity->map_id == MAP_ID && !empty(self::$_position_trigger) && !self::isRemove($key))
		{
			if(APP_DEBUG)
				$entity->log('запустим триггер песочницы изменения позиции');
			
			try
			{
				$recover = Block::current();
				Block::$positions = true;			// запрет менять координаты
				Block::$objects = true;				// запрет добавление объектов
			
				call_user_func(self::$_position_trigger, $entity, $old_value);
			}
			finally
			{
				Block::recover($recover);	
			}		
		}	
	}
	
	private static function removePosition(string $key, Position $old_value)
	{
		if(!$entity = self::$_entitys[$key])
			throw new Error('Сущности '.$key.' не найдено для удаления ее из матрицы позиций');
		
		$tile = $old_value->tile();
		
		if(APP_DEBUG)
			$entity->log('удаление с позиции '.$tile);
		
		if(isset(self::$_positions[$tile][$key]))
		{
			unset(self::$_positions[$tile][$key]);		
			if(empty(self::$_positions[$tile]))
				unset(self::$_positions[$tile]);
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

				if(!empty(self::$_positions[$new_position]))
				{
					foreach(self::$_positions[$new_position] as $key)
					{
						if(!isset(self::$_entitys[$key]))
							throw new Error($key.': Существо отмечено на локации '.$new_position.' , но отсутвует в коллекции');
						
						if($filter)
						{ 
							$return = $filter(self::get($key));
							if(!is_bool($return))
								throw new Error('Функции фильтрации существ на карте должны возвращать значение типа bool (true или false)');

							if(!$return)
								continue;	
						}					
						$array[$key] = self::get($key);
						
						if($count && count($array)==$count)
							return $array;	
					}
				}	
			}
		}		
		return $array;
	}
	
	// включает в себя сбор изменений, их рассылку и удаление сущностей
	protected final static function refresh()
	{
		if(self::count())
		{
			if(self::$_remove)
			{
				if(APP_DEBUG)
					PHP::log('отправка в websocket пакетов удаления и перехода существ с игрового сервера');

				foreach(self::$_remove as $key=>$new_map)
				{
					if($new_map && self::$_entitys[$key]->map_id != MAP_ID)
						throw new Error('Удаление сущности по причине смены карты должно происходить на его родной локации');
					
					if(APP_DEBUG)
					{
						if($new_map)
							self::$_entitys[$key]->log("перемещение на карту ".$new_map);
						else
							self::$_entitys[$key]->log("удаление с карты");
					}
					
					if(self::$_entitys[$key]->map_id == MAP_ID)
					{	
						if($new_map && !(self::$_entitys[$key] instanceOf Players))
						{									
							if(APP_DEBUG)
								self::$_entitys[$key]->log("Отправка пакета о переходе с карты ".MAP_ID." на ".$new_map);
							
							parent::create_remote_entity(World::get($key), $new_map);	
						}
					}
					
					unset(self::$_remove[$key]);
					self::removePosition($key, self::$_entitys[$key]->position);
					
					self::$_entitys[$key]->__destruct();

					unset(self::$_entitys[$key]);
				}	
			}	
		}		
	}

	// подготовим для game server массив изменений существ
	protected final static function collected():array
	{	
		// подготавливаем данные для отправки только если на нашем сервере кто то есть (в тч  не жиилые NPC данные которых меняют соседние локации на которых кто то есть живой)
		$publish = [];
		// первый раз пройдемся что бы полуичить что в игровом мире изменилось
		foreach(self::$_entitys as $key=>$entity)
		{	
			// проверим изменился ли объект что бы его отправить
			if($changes = $entity->getChanges())
			{					
				$publish = array_replace_recursive($publish, self::formatChanges($entity->key, $entity->type->value, $entity->map_id, $changes));	
			}					
		}

		if($publish)	
			parent::send_changes($publish);

		return parent::collected();		
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