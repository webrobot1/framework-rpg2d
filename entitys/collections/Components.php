<?php
class Components extends AbstractCollection
{	
	private static array $_list;						// справочник компонентов и их кода
	private static array $_components_closures;			// тригеры изменения компонента
	
	private array $_privates;							// массив названий и значений по ссылки компонентов которые в админке указаны как защищенные от рассылки другим (нужно лишь для игроков)
	
	function __construct(protected EntityAbstract $object, array $components = array())
	{
		parent::__construct();

		#Perfomance - экономит доли миллисекунды за счет отуствия постоянного обращение к свойствам объекта (везде пихать нет смысла, а только там где более 1 раза идет обращение)
		$permament_update = $this->object->permament_update;
		$object_type = $this->object->type->value;
			
		if(!$permament_update && World::isset($this->object->key))
			throw new Error('нельзя переписать уже существующие компоненты принудительно существу '.$this->object->key.' с текущей локации');			
		
		if(APP_DEBUG)
			$this->object->log('запрос на '.($permament_update?'обновление пакетом с ссоедней локации':'создание').' компонентвов сущности');
		
		if(!isset(static::$_list))
			throw new Error('Комнотенты не были проинициализированы через Components::init(...) для передачи списка доступных свойств');			
		
		if(!empty(static::$_list[$object_type]))
		{
			if($permament_update && !$components)
				throw new Error('нельзя переписать существам с другой локации компоненты пустым набором когда для указанного типа есть список возможных');
			
			// заполним отсутвующие компоненты значениями по умолчанию
            // сначала ТОЛЬКО добавляем компоненты со значениями которые пришли из вне или по умолчанию (в любом случае повесятся все значения)
            foreach(static::$_list[$object_type] as $name=>$component)
            {    
                $value = (isset($components[$name])?$components[$name]:$component['default']);
                $this->add($name, $value);    
            }  
		}
		elseif($components)
			throw new Error('для сущности '.$object_type.' не предусмотрено никаких компонентов указанны в инициализации Components::init(...), однако пришли значения ('.json_encode($components).')');
	}
	
	public static function init(array $components, array $components_closures)
	{
		if(isset(static::$_list))
			throw new Error('Инициализация кода компонентов уже была произведена');
		
		static::$_list = array();
		static::$_components_closures = $components_closures;
		
		if(APP_DEBUG)
			PHP::log('Инициализация компонентов');
		
		foreach($components as $name=>&$component)
		{
			if(!isset($component['entitys']))
				throw new Error('У компонента '.$name.' отуствует параметр entitys о принадлежности копонента к сущностям');
						
			static::$_list[ComponentListEntityTypeEnum::All->value][$name] = $component;
			foreach($component['entitys'] as $entity_name=>$value)
			{
				if(!EntityTypeEnum::tryFrom($entity_name))
					throw new Error('Неизвестная сущность '.$entity_name);
				
				static::$_list[$entity_name][$name] = &static::$_list[ComponentListEntityTypeEnum::All->value][$name];
			}
		}
	}
	
	// компонентов может быть очень много и не у всех есть дефольные значения - такие компоненты НЕ присваюиваются существам, но они могут быть присвоены во время игры если эти компоненты доступны типу сущности
	public function get(string $key): mixed 
	{
		if (!array_key_exists($key, $this->values)) 
		{
			#Perfomance - экономит доли миллисекунды за счет отуствия постоянного обращение к свойствам объекта
			$object_type = $this->object->type->value;
			
			if(isset(static::$_list[ComponentListEntityTypeEnum::All][$key]) && empty(static::$_list[$object_type][$key]))
				throw new Error('Компонент '.$key.' существует, но не разрешен для сущности '.$this->object->key.' с типом '.$object_type.print_r(static::$_list, true));
			
			throw new Error('Обращение к неизвестному копоненту '.$key.' у существа '.$this->object->key);
		}
		else	
			return $this->values[$key];
    }
	
	public function add(string $key, $value):void
	{
		#Perfomance - экономит доли миллисекунды за счет отуствия постоянного обращение к свойствам объекта
		$object_key = $this->object->key;
		$map_id = $this->object->map_id;
			
		if(World::isset($object_key) && Block::$components && Block::$components!=$object_key)
			throw new Error('Стоит запрет на изменение компонентов '.(is_string(Block::$components)?'других существ':'любого существа').' воизбежание зациклевания');
		
		if(!$component = @static::$_list[ComponentListEntityTypeEnum::All->value][$key])
			throw new Error('компонент '.$key.' не найден');
		
		if (!$this->exists($key)) 
		{
			if(!isset($component['entitys'][$this->object->type->value]))
				throw new Error('компонент '.$key.' не разрешен для сущности '.$object_key.' с типом '.$this->object->type->value);	
		}
		// может так быть: мы на соседнюю локацию шлем увеличение текущих hp (лечение), а существо уже убьют пока идет пакет и будет увеличение HP мертвому существу (как приме). 
		// Поэтому не давать менять существам на соседних локациях напрямую компоненты. кроме как при создании нового существа
		elseif($map_id!=MAP_ID && !$this->object->permament_update) 
			throw new Error('Нельзя менять компоненты созданного существ на другой локации (только при создании или на текущей. в остальном используйте события в которых могут менять в прцоессе выполнения на локации существа)');
		
		static::valid_type(ComponentTypeEnum::from($component['type']), $value);
		
		// при загрузки и если изменилось значение - пометим что на рассылку всем игроакм данных и вызовем код если он есть
		// повторная смена занчение на то же самое не вызовет тригер (это важно тк может быть зацикленность)
		if(!$this->exists($key) || $this->values[$key]!=$value)
		{
			// для существ с другой локации тригер не создаем (он уже вызвался у родной локации) и не рассылаем изменения (тк сами мы их поменять не можем а обновление по существу уже были всем разосланы еще в websocket)
			if($map_id == MAP_ID)
			{	
				// если объект есть на сцене
				// мы не можем вызвать иначе тк для тригера нужен объект в тч component заполненный который создасться после создания объекта
				if(World::isset($object_key))
				{
					$this->trigger($key, $value);					
				}
				else
					$this->values[$key] = $value;
			}
			else
				$this->values[$key] = $value;	
			
			if(APP_DEBUG)
				$this->object->log('новое значение компонента '.$key.' ('.$component['type'].') сущности '.$object_key.' = '.print_r($value, true));
		}
	}

	private static function valid_type(ComponentTypeEnum $type, string|float|array $value):void
	{
		if($type == ComponentTypeEnum::Json)
		{
			if(!is_array($value))
				throw new Error('Только массив допустим для присвоения свойства с типом json');	
		}
		elseif($type == ComponentTypeEnum::Number)
		{
			if(!is_numeric($value))
				throw new Error('значение по умолчанию компонента должно быть числом тк указан соответсвующий тип');
		}			
		elseif($type == ComponentTypeEnum::String)
		{
			if(!is_string($value))
				throw new Error('только строка разрешена для значения копонента');	
		}					
	}
	
	// просто запустить триггер при смене значения или как только сущность появляется на сцене (World->add)
	public function trigger(string $key, $value):void
	{
		if($this->object->map_id != MAP_ID)
			throw new Error('Нельзя создавать триггер на изменении компонента существа с другой локации ('.$this->object->map_id.') , где он должен выполнятся');
		
		$data = null;
		
		// при добавлении в объекты и если изменилось значение - вызовем тригер 
		if($closure = &static::$_components_closures[$key]??null)
		{	
			if
			(
				APP_DEBUG 
				&& 
				(
					(!$trace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)) 
						|| 
					(
						!($trace[1]['class']==static::class && $trace[1]['function']=='add')
							&&
						!($trace[1]['class']==World::class && !World::isset($this->object->key))						
						&&
						!($trace[1]['class']==RemoteCommand::class && $trace[1]['function']=='player_add')
					)
				)
			)
				throw new Error('Запуск кода смены компонента можно лишь при добавлении существа в World (авторизации с другого устройства) или при изменении данных уже добавленного '.print_r($trace, true));
			
			if(APP_DEBUG)
				$this->object->log('запустим триггер песочницы компонента '.$key);
			
			try
			{			
				$recover = Block::current();
				Block::$components = true;																	// запретим менять компоненты , но разрешим вешать события на тригеры значений компонентов
				$new_value = $closure($this->object, $value);												// передадим старое значение. если захотим вернем его (да можно предавать по ссылки но тока в песочнице php, а есть еще и lua и js)
			
				// если мы привели к null значение а оно было не null тогда не сохранем и не рассылаем (предполагаем что уже разослали в событие или просто нечего менять)
				if($new_value!==null || $new_value === $value)
				{
					$this->values[$key] = $new_value;
					$data = ['components'=>[$key=>$new_value]];		
				}
			}
			finally
			{
				Block::recover($recover);																	// после вернем как было (тк может мы из какого то друого места пришли где были запреты уже, например из зигрузки на карту сущности)	
			}
		}	
		else
		{
			$data = ['components'=>[$key=>$value]];	
			$this->values[$key] = $value;	
		}
		
		if($data)
		{
			if(static::$_list[ComponentListEntityTypeEnum::All->value][$key]['isSend'])
				$this->object->setChanges($data, EntityChangeTypeEnum::All);
			else
				$this->object->setChanges($data, EntityChangeTypeEnum::Privates);
		}		
	}
	
	final public function privates():array
	{
		if(!isset($this->_privates))
		{
			$this->_privates = array();
		    foreach(static::$_list[$this->object->type->value] as $key=>$value)
			{
				// отдается в виде ссылки тк число компонентов не меняется и массив всегда будет содержать актуальные значения
				if(!$value['isSend']) $this->_privates[$key] = &$this->values[$key];
			}	
		}	

		return $this->_privates;
	}

	public function remove($key): void
	{
		throw new Error('запрещено удалять компоненты');
    }
		
	public static function list(ComponentListEntityTypeEnum|EntityTypeEnum $type = ComponentListEntityTypeEnum::All): array
	{
		if(!isset(static::$_list))
			throw new Error('События не были проинициализированы через Components::init(...) для передачи списка');				
		
		return static::$_list[$type->value];
    }
	
	// если этого не делать будет утечка памяти при удалении существа с карты тк ссылка останется тут
	function __destruct()
	{		
		unset($this->object);			
	}
}