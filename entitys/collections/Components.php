<?php
class Components extends AbstractCollection
{
	// справочник компонентов и их кода
	private static array $_list;
	private array $_privates;						    // массив названий и значений по ссылки компонентов которые в админке указаны как защищенные от рассылки другим (нужно лишь для игроков)
	
	function __construct(protected EntityAbstract $object, array $components = array())
	{
		parent::__construct();
		
		#Perfomance - экономит доли миллисекунды за счет отуствия постоянного обращение к свойствам объекта (везде пихать нет смысла, а только там где более 1 раза идет обращение)
		$remote_update = $this->object->remote_update;
		$object_type = $this->object->type;
			
		if(!$remote_update && World::isset($this->object->key))
			throw new Error('нельзя переписать уже существующие компоненты принудительно существу '.$this->object->key.' с текущей локации');			
		
		if(APP_DEBUG)
			$this->object->log('запрос на '.($remote_update?'обновление пакетом с ссоедней локации':'создание').' компонентвов сущности');
		
		if(!isset(static::$_list))
			throw new Error('Комнотенты не были проинициализированы через Components::init(...) для передачи списка доступных свойств');			
		
		if(!empty(static::$_list[$object_type]))
		{
			if($remote_update && !$components)
				throw new Error('нельзя переписать существам с другой лкоации компоненты пустым набором');
				
			// заполним отсутвующие компоненты значениями по умолчанию
			// сначала ТОЛЬКО добавляем компоненты со значениями которые пришли из вне или по умолчанию (в любом случае повесятся все значения)
			foreach(static::$_list[$object_type] as $name=>$component)
			{	
				if($remote_update && !array_key_exists($name, $components)) continue;
				
				$value = (isset($components[$name])?$components[$name]:$component['default']);
				$this->add($name, $value);	
			}		
		}
		elseif($components)
			throw new Error('для сущности '.$object_type.' не предусмотрено никаких компонентов указанны в инициализации Components::init(...), однако пришли значения ('.json_encode($components).')');
	}
	
	// компонентов может быть очень много и не у всех есть дефольные значения - такие компоненты НЕ присваюиваются существам, но они могут быть присвоены во время игры если эти компоненты доступны типу сущности
	public function get(string $key): mixed 
	{
		if (!array_key_exists($key, $this->values)) 
		{
			#Perfomance - экономит доли миллисекунды за счет отуствия постоянного обращение к свойствам объекта
			$object_type = $this->object->type;
			
			if(empty(static::$_list[$object_type][$key]))
				throw new Error('Компонент '.$key.' не разрешен для сущности '.$this->object->key.' с типом '.$object_type);
			
			// там всегда вернеться null тк вне кода этого приложения все что кроме null будет присвоено существу на этапе загрузки игрового пространства
			return static::$_list[$object_type][$key]['default'];
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
			if(!isset($component['entitys'][$this->object->type]))
				throw new Error('компонент '.$key.' не разрешен для сущности '.$object_key.' с типом '.$this->object->type);	
		}
		// может так быть: мы на соседнюю локацию шлем увеличение текущих hp (лечение), а существо уже убьют пока идет пакет и будет увеличение HP мертвому существу (как приме). 
		// Поэтому не давать менять существам на соседних локациях напрямую компоненты. кроме как при создании нового существа
		elseif($map_id!=MAP_ID && !$this->object->remote_update) 
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
				// мы не можем вызвать иначе тк для тригера нужен объект в тч components заполненный который создасться после создания объекта
				if(World::isset($object_key))
				{
					$new_value = $this->trigger($key, $value);
					
					// tclb мы привели к null значение а оно было не null тогда не сохранем
					if($new_value!==null || $new_value === $value)
						$value = $this->values[$key] = $new_value;	
				}
				else
					$this->values[$key] = $value;	

				$data = ['components'=>[$key=>$value]];		
			
				if($component['isSend'])
					$this->object->setChanges($data);
				else
					$this->object->setChanges($data, EntityChangeTypeEnum::Privates);
			}
			else
				$this->values[$key] = $value;	
			
			if(APP_DEBUG)
				$this->object->log('новое значение компонента '.$key.' ('.$component['type'].') сущности '.$object_key.' = '.print_r($value, true));
		}
	}

	private static function valid_type(ComponentTypeEnum $type, string|float|array $default_value):void
	{
		if($type == ComponentTypeEnum::Json)
		{
			if(!is_array($default_value))
				throw new Error('Только массив допустим для присвоения свойства с типом json');	
		}
		elseif($type == ComponentTypeEnum::Number)
		{
			if(!is_numeric($default_value))
				throw new Error('значение по умолчанию компонента должно быть числом тк указан соответсвующий тип');
		}			
		elseif($type == ComponentTypeEnum::String)
		{
			if(!is_string($default_value))
				throw new Error('только строка разрешена для значения копонента');	
		}					
	}
	
	// просто запустить триггер при смене значения или как только сущность появляется на сцене (World->add)
	public function trigger(string $key, $value):mixed
	{
		if($this->object->map_id != MAP_ID)
			throw new Error('Нельзя создавать триггер на изменении компонента существа с другой локации ('.$this->object->map_id.') , где он должен выполнятся');
		
		// при добавлении в объекты и если изменилось значение - вызовем тригер 
		if($closure = &static::list()[$key]['closure'])
		{	
			if(APP_DEBUG && ((!$trace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)) || $trace[1]['function']!='add' || ($trace[1]['class']!=World::class && ($trace[1]['class']!=static::class || !World::isset($this->object->key)))))
				throw new Error('Запуск кода смены компонента можно лишь при добавлении существа в World или при изменении данных уже добавленного '.print_r($trace, true));
			
			if(APP_DEBUG)
				$this->object->log('запустим триггер песочницы компонента '.$key);
			
			try{			
				$recover = Block::current();
				Block::$components = true;																	// запретим менять компоненты , но разрешим вешать события на тригеры значений компонентов
				$value = $closure($this->object, $value);													// передадим старое значение. если захотим вернем его (да можно предавать по ссылки но тока в песочнице php, а есть еще и lua и js)
			}
			finally
			{
				Block::recover($recover);																					// после вернем как было (тк может мы из какого то друого места пришли где были запреты уже, например из зигрузки на карту сущности)	
			}
		}	
		
		return $value;
	}
	
	final public function privates():array
	{
		if(!isset($this->_privates))
		{
			$this->_privates = array();
		    foreach(static::$_list[$this->object->type] as $key=>$value)
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
	
	public static function init(array $components)
	{
		if(APP_DEBUG)
			PHP::log('Инициализация компонентов');
		
		foreach($components as $name=>&$component)
		{
			if(!isset($component['entitys']))
				throw new Error('У компонента '.$name.' отуствует параметр entitys о принадлежности копонента к сущностям');
				
			// создадим из текстовой версии кода которая нам пришла замыкание которое можно будет вызывать при сменен компонет а
			if(!empty($component['code']))
			{
				if(APP_DEBUG)
					PHP::log('Создаем триггер компонента '.$name);
				
				try
				{
					// не меняйте текстовку ошибки - она парсится в мастер процессе песочницы которая запустила этот процесс
					$component['closure'] = eval('return static function(EntityAbstract $object, $value):mixed{
						'.$component['code'].' 
						return $value;
					};');
				}
				catch(Throwable $ex)
				{
					throw new Error('code('.$name.'): Ошибка компиляции кода изменения компонента: '.$ex);
				}					
			}
			else
				$component['closure'] = null;
			
			static::$_list[ComponentListEntityTypeEnum::All->value][$name] = $component;

			foreach($component['entitys'] as $entity_name=>$value)
			{
				if(!EntityTypeEnum::tryFrom($entity_name))
					throw new Error('Неизвестная сущность '.$entity_name);
				
				static::$_list[$entity_name][$name] = &static::$_list[ComponentListEntityTypeEnum::All->value][$name];
			}
		}
	}
	
	// если этого не делать будет утечка памяти при удалении существа с карты тк ссылка останется тут
	function __destruct()
	{		
		unset($this->object);			
	}
}