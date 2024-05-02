<?php
// Осторожно! родительский класс сущностей. все методы создания сущностей и вспомогательные тут. здесь стараться ничего не менять
// все поля приватные. Ничего не должно меняться внутри этого родительского класса после __construct без попадания в changes
abstract class EntityAbstract 
{
	private array $_changes = [];							// последнии изменения в объектах - рассылается игрокам и вмежным локациям
	public readonly int $id;								// публичное тк ненужно отправлять в клиент и не нужно через магические методы проганять (тк оно и не меняется)
	public readonly string $key;						
	public readonly EntityTypeEnum $type;	
	
	public readonly string $login;							// только для игроков
	public string $ip; 
	public ?float $ping;

	private bool $permament_update = false;	
	public array $buffer = array();							// бывает между событиями нужно переносить какой то буфер который нельзя и не нужно ставить как парметр события
															// в качестве прмиера я кеширую последний обработанный поиск пути существа в событии move/walk/to
		
	# поля нже protected что бы можно было переопделеить классы обрабатывающие их
	protected readonly Position $position;					// возможно к переопределению
	protected readonly Forward $forward;	
	protected readonly Components $components;				// компоненты заполняются после инициализации объекта что бы дернулся их код lua с заполненным _G['objects']							
	protected readonly Events $events;					   	// объект  Event управляющий событиями пришедших от клиента или добавленные вручную создается в режиме сервера работы при добалении в колецию World
														

	// объявим общие обязательные параметры
	public function __construct
	(
		private readonly int $map_id,					// карту поменять нельзя, при смене ее только рассылаются изменения (только при отключение существа и перезаписи в бд можно менять)
		private string $prefab,	

		private float $x,							
		private float $y,							
		private float $z,
		
		private float $forward_x = 0,							
		private float $forward_y = 0,	
		private float $forward_z = 0,	
		
		private int $sort = 0,	
		
		private float $lifeRadius = 0,					// радиус при котором окружающие существа обрабатывают свою механику (только у игроков контснтантно и призывных существ и снарядов). дальше него - туман (и существа замирают)
		
		private ?string $action = null,		
		private ?string $created = null,
	
		?int $id = null,								// может быть null когда новое что то добавляется на сервер	
		
		array $components = array(),														
		array $events = array(),

		bool $permament_update = null,						// если true то этому существу можно обновить данные в текущий момент
		
		string $login = null, 							// только для игроков
		string $ip = null, 
		?float $ping  = null,
		
		...$arg											// все остальное что не нужно или новые поля что забыли задейстсвать (что бы не упал сервер)
	)
	{
		if(!DEFINED('MAP_ID'))
			throw new Error('не определена карта сервера MAP_ID');
		
		if(!empty($arg))
			throw new Error('неизвестные поя создания сущности '.print_r($arg, true));
		
		$this->type = static::getType();
		
		if(empty($id))
		{
			if($this instanceOf Players)
				throw new Error('Нельзя создавать сущность игрока без индентификатора');
			
			$id = hrtime(true);
			
			// до тех пор пока не будет уникальным на карте наш id мы прибавляем 
			while(World::isset(self::getKey($id)))
			{
				$id++;	
			}	
		}
		elseif(World::isset(self::getKey($id)))
		{
			throw new Error('Существо '.self::getKey($id).' уже существует в коллекции мира и не может быть создано снова');
		}
			
		$this->id = $id;
		$this->key = self::getKey($this->id);
	
		if($permament_update)
			$this->setPermamentUpdate(true);
		
		if(!$action)
			$this->action = SystemActionEnum::ACTION_LOAD;
		elseif($action == SystemActionEnum::ACTION_REMOVE)
			throw new Error('существо '.$this->key.' '.($this->map_id != MAP_ID?'с другой локации':'').' не может быть создано с пометкой action что оно удаляется');					
				
		try
		{
			$forward = new Forward($this->forward_x, $this->forward_y, $this->forward_z);			
		}
		catch(Throwable $ex)
		{
			$this->warning('не верно указаны координаты направления ('.$this->forward_x.Position::DELIMETR.$this->forward_y.Position::DELIMETR.$this->forward_z.') у существа '.$this->key.': '.PHP_EOL.$ex->getMessage());
			
			$this->forward_x = 0;							
			$this->forward_y = 1;	
			$this->forward_z = 0;	
		}
		
		if(!isset($this->position))
			$this->position	 = new Position(object: $this);
		
		if(!isset($this->forward))
			$this->forward 	= new Forward(object: $this);
			
			
		// пока все под 2Д
		//(int)  обязательно может вернуть -0
		$tile = (int)round($this->x).Position::DELIMETR.(int)round($this->y).Position::DELIMETR.(int)round($this->z);
		if($this->map_id==MAP_ID)
		{
			// посмотрим случайные позиции с указанной карте или текущей если не указана
			if(!$tiles = Map2D::getCurrentMapTiles())
				throw new Error('Нет ниодной свободной клетки без физики');
			
			if(!isset($tiles[$tile]))
			{
				// todo сделать позицию спавна игрока по умолчанию
				if($new_position = array_keys($tiles)[rand(0, count($tiles)-1)])
				{
					$this->warning('Тайл '.$tile.' не доступен на карте '.$this->map_id.', установим случайный '.$new_position);
				
					$explode = explode(Position::DELIMETR, $new_position);
					$this->x = $explode[0];                                                                                    
					$this->y = $explode[1];                                                                                    
					$this->z = $explode[2];                                                                                    
				}		
			}
		}
		else
		{
			if(empty(Map2D::sides()[$this->map_id]))
				throw new Error('Карта '.$this->map_id.' для создаваемого существа отсутвует не принадлежит центральной ('.MAP_ID.') или смежной из списка '.implode(',', array_keys(Map2D::sides())));
		
			//	проверим локальные позиции свободны ли	
			if(!Map2D::getTile($this->position->tile()))
				throw new Error('При создании копии существа '.$this->key.' с удаленной локации '.$this->map_id.' переданы позиция '.$this->position->tile().'  не сущеcтвующие в матрице для этой локации');
		}	

		if(APP_DEBUG)
		{
			if($this->map_id!=MAP_ID)
				$this->log('создается объект сущности '.(!$permament_update?'c':'для').' другой карты ('.$this->map_id.')');
			else 
				$this->log('создание объекта сущности для текущей карты');
		}
		
		// сначал поместим все публичные изменения на рассылку
		if($this->map_id == MAP_ID)
		{
			if(APP_DEBUG)
				$this->log('Подготовим общие (без свойств и компонентов) данные нового существа как изменения для рассылки всем игрокам и смежным локациями');
			
			$this->setChanges($this->toArray(), EntityChangeTypeEnum::All);
		}					
				
		// события и компоненты дополнят changes внутри себя при инициализации
		if(!isset($this->events))
			$this->events = new Events($this, $events);
		
		// тригер компонентов нового существа сработает только когда существо будет добавляться на сцену (в момент  World::add)
		if(!isset($this->components))
			$this->components = new Components($this, $components);	
	}
	
	// Возвращает обе части для ключа Redis
	public static function getKey(int $id):string
	{
		return static::getType()->value.EntityTypeEnum::SEPARATOR.$id;
	}
	
	abstract protected static function getType():EntityTypeEnum;
	
	public final function remove(?int $new_map_id = null)
	{	
		World::removeEntity($this->key, $new_map_id);
	}
	
	public final function __isset(string $key):bool
	{
		return $this->__get($key)!=null?true:false;
	}

	// магические методы позволят из вне получать данные и меняя их писать что они поменялись в changes
	public final function __get(string $key):mixed
	{
		if(isset(static::columns()[$key]) || (isset(self::position_columns()[$key]) || $key == 'permament_update') && property_exists($this, $key)) 
			return $this->$key;	
		else
			throw new Error('нельзя получить поле '. $key);
	}

	public function setPermamentUpdate(bool $value)
	{
		if(
			APP_DEBUG 
				&&
			// может быть указано либо при создании существа через 
			//	RemoteCommand->
			//  RemoteCommand->World->EntityAbstract->
			//  RemoteCommand->World->closure->EntityAbstract->  
			
			((!$trace = array_column(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 6), 'class', 'class')) || empty($trace[RemoteCommand::class]))
		)
			throw new Error('параметр существа указывающий что существо обновляется пакетом с удаленной локации нельзя менять вручную '.print_r($trace, true));
		
		
		if(MAP_ID == $this->map_id)
			throw new Error('нельзя устанавливать параметр существа указывающий что существо обновляется пакетом с удаленной локации когда его карта текущая');
		
		$this->permament_update = $value;
	}

	// этот метод нужен только для вызова из update_from_remote_location из RemoteCommand с параметром  remote_command	
	public final function __set(string $key, mixed $value):void
	{
		// то только в таймауте тк незачем в приципе там менять свойства сущности
		if(World::isset($this->key) && Block::$object_change && Block::$object_change!=$this->key)
			throw new Error('Стоит запрет на изменение компонентов и свойств '.(is_string(Block::$object_change)?'других существ':'любого существа'));
		
		#Perfomance - исключительно что бы убыстрить доступ к данным (при записи скорость таже, а при чтении х2)
		$map_id = $this->map_id;
		$permament_update = $this->permament_update;
		
		if(!$permament_update && MAP_ID != $map_id) 
			throw new Error('Нельзя напрямую менять параметры ('.$key.') существа с другой локации');		
		
		if((isset(static::columns()[$key]) || isset(self::position_columns()[$key])) && $key!='events' && $key!='components')
		{
			// отправляем в клиент только если изменилось значение или action (он может несколько раз повторятся)
			if((isset(static::columns()[$key]) && ($this->$key != $value || $key=='action')) || (isset(self::position_columns()[$key]) && ($position_array = $value->toArray()) != $this->$key->toArray()))
			{
				if(APP_DEBUG)
					$this->log('изменение поля '.$key.' на '.$value.($permament_update?' в режиме синхронизации локации с '.$map_id:''));	
				
				// изменение координат через привыоение ->position = new Position(...)
				if(isset(self::position_columns()[$key]))
				{
					// пакеты с position или forward не могут приходить из вне и меняться
					if($map_id != MAP_ID)
						throw new Error('Данные поля '.$key.' не могут приходить для существа с другой локации из вне');
					
					if(Block::$positions)
						throw new Error('Стоит запрет на изменение координат во избежание зацикливание');
					
					if($value instanceOf Position)
					{
						// если мы меняем position проверим есть ли песочница при запуске смены координат
						// именно тк что бы посчиталась правильная позиция ровная с учетом положения
						$old_position = $this->$key->round();
					}

					foreach($position_array as $coord=>$coord_value)
					{
						// экономим пакет - не передаем не изменившиеся части координат
						if($this->$coord == $coord_value) continue;
						
						// с forward такой колхоз тк нельзя отдельно менять 
						if(is_a($value, Forward::class))
						{
							# Perfomance - что бы не обращаться  раза к свойству сохраним в виде переменной (так быстрее)
							// Зная что там магический метод вызовем его напрямую
							$coord = 'forward_'.$coord;
						}
						elseif(!($value instanceOf Position))
							throw new Error('Значение свойства '.$key.' не является объектом позиции');
						
						$this->$coord = $coord_value;
						$this->setChanges([$coord=>$coord_value], EntityChangeTypeEnum::All);
					}

					// если существо под удалением ничего уже не меняем ему
					if($value instanceOf Position)
					{
						World::addPosition($this->key, $old_position);
					}
				}
				else
				{
					// работа с бесщовным миром
					switch($key)
					{
						// напрямую смена координат существам с другой лкоации (с текущей так нельзя только через new Position и new Forward)
						case 'x':
						case 'y':
						case 'z':
							if(MAP_ID == $map_id) 
								throw new Error('Нельзя напрямую менять параметры ('.$key.') существу '.$this->key.' с текущей локации (используйте новый экземпляр Position)');
							
							$value = round($value, POSITION_PRECISION);
							
							// Бывает такое в PHP, но на number_format переделывать не хочу тк он в 3 раза медленнее
							if($value == -0)
								$value = 0;
							
							// это должно быть именно тут , не в Position (тк метод update может вызываться принудительно минуя свойство ->position)
							$old_position = $this->position->round();
							
							$this->$key = $value;
							World::addPosition($this->key, $old_position);
							// именно выходим из функции, не break тк мы уже установили координаты , а не установить не можем тут тк addPosition  требует перед ним установку их!
						return;	
						
						// если мы на текущей карте и меняются x и y проверим не ушли ли мы на смежную карту
						case 'forward_x':
						case 'forward_y':
						case 'forward_z':
							if($value<-1 || $value>1)
								throw new Error('Значение поля '.$key.' '.$value.' превысило единицу');
							
							if(MAP_ID == $map_id) 
								throw new Error('Нельзя напрямую менять параметры ('.$key.') существу '.$this->key.' с текущей локации (используйте новый экземпляр Forward)');
							
							$value = round($value, POSITION_PRECISION);
							
							// Бывает такое в PHP
							if($value == -0)
								$value = 0;
						break;
						
						// если напрямую сменилась карта
						// Внимание! после смены карты данные в setChanges не попадают уже тк существо удаляется (но отправятся другой локации те что будут сменены, а она в свою очередь вышлет новый пакет сущности полностью когда дойдет)
						case 'map_id':
							if(APP_DEBUG)
								$this->log('новая карта указана напрямую '.$value);	
						
							// не спешим сставить свойство новой карты тк пакет будет приходить будто команда к другой карте, а разошлем мы ниже
							// телепорт на другую карту
							if($map_id == MAP_ID)
							{
								if($value == MAP_ID)
									throw new Error('нельзя перещаться с текущей карты на текущую');
								
								$this->setChanges([$key=>$value], EntityChangeTypeEnum::All);
								$this->remove($value);	
							}

						// именно выходим из функции, не break!
						return;
					}
			
					$this->$key = $value;
					
					if($map_id == MAP_ID)
						$this->setChanges([$key=>$value], EntityChangeTypeEnum::All);
				}
			}	
		}
		else
			throw new Error($this->key.': нельзя изменить поле '. $key);
	}
	
	// какие колонки выводить в методе toArray и которые можно изменить из вне класса
	// publiс тк для remotу commands требуется
	public static function columns():array
	{
		$columns = array				
		(	  						
			'id'=>true,	
			'prefab'=>true,	
			'action'=>true,
			'sort'=>true,

			'x'=>true,							
			'y'=>true,							
			'z'=>true,
			
			// эти три поля меняются по отдельности только существам с другой локации, для текущих нужно создавать объект IPosition заного 
			'forward_x'=>true,							
			'forward_y'=>true,	
			'forward_z'=>true,	
			
			'lifeRadius'=>true,
			
			'components'=>true,								
			'events'=>true,								
															
			'map_id'=>true,						
			'created'=>true
		);

		return $columns;		
	}
	
	private static function position_columns():array
	{
		return ['position'=>true, 'forward'=>true];
	}
	
	// todo сделать что бы это сразу вносилось в глобальный кеш World для отправки (тк там и есть данные удаления сущнсоти котоыре не снять уже после самого ее удаления тк нет $this)
	public final function setChanges(array $changes, EntityChangeTypeEnum $type)
	{
		if(empty($changes))
			throw new Error('Изменения существа '.$this->key.' не могут быть пустым массивом');
		
		// не меняем action если существу удаляется (тк мы разослать должны это, но удалим только в следующем кадре)
		if(!empty($changes['action']) && World::isRemove($this->key) && $changes['action']!=SystemActionEnum::ACTION_REMOVE)
		{
			unset($changes['action']);
			if(empty($changes))
				return;
		}
				
		$player_change_type = EntityChangeTypeEnum::Players;
		$events4remote_change_type = EntityChangeTypeEnum::Events4Remote;
		
 		if($type==$player_change_type && ($denied = array_intersect_key($changes, static::columns())))
			throw new Error('нельзя отправлять пакет изменений у существа '.$this->key.' типа '.$type->value.' у которого содержаться поля сущености тк рассылка идет всем игрокам '.print_r($denied, true));	
		
		if($type!=$player_change_type && ($denied = array_diff_key($changes, static::columns())))
			throw new Error('нельзя отправлять пакет изменений у существа '.$this->key.' не связанных с полями сущности '.print_r($denied, true));	
		 
		if($type == $events4remote_change_type)
		{
			if(empty($changes['events']) || count($changes)>1)
				throw new Error('Пакет изменений '.$type->value.' у существа '.$this->key.' не может содержать что то кроме пакетов событий'.print_r($changes, true));	
			
			// для неигроков данные приватныех событий NPC пихаем в Private, тк во избежание ошибок мы проверяем в websocket сервере что бы если с текущей лкоации то там были только игроков события
			if($this->map_id == MAP_ID && $this->type != EntityTypeEnum::Players)
				throw new Error($this->key.' не является существом с другой локации и не является игроком для рассылки пакета изменений типа'.$type->value.' '.print_r($changes, true));		
		}
		// для существ с других карт можем менять лишь события Events4Remote
		elseif($this->map_id != MAP_ID)
			throw new Error('Нельзя пометить пакет измнений у существа '.$this->key.' с другой локации иначе чем '.$events4remote_change_type);	
		
		$change_type = $type->value;
		$local_changes = &$this->_changes;
	
		// не используем array_replace  тк данные компонентов и событий могут быть массивы и они должны перезаписаться а не слиться воедино со старыми (если старые есть тк в течении кадра событие или компонент могли меняться по несколько раз)
		if(!empty($local_changes[$change_type]))
		{		
			$finish_events = array();
			$not_changes = array();
			$local_changes[$change_type] = Data::compare_recursive($local_changes[$change_type], $changes, $not_changes, $finish_events);
			
			if(APP_DEBUG && $not_changes)
				$this->log('после уникализации удалены следующие изменения группу рассылки '.$change_type.' '.print_r($not_changes, true));	
			
			// если более 1й группы то в других могут остаться пакеты для завершенного события (они удалялились только в своей группе при вызове update_recursive)
			if($finish_events && count($local_changes)>1)
			{
				foreach($finish_events as $event)
				{
					foreach($local_changes as $type=>&$changes)
					{
						// тут мы уже удалили в Data::compare_recursive
						if($type == $change_type)  continue;
						
						if(isset($changes['events'][$event]))
						{
							// полностью не удаляем тк там time что нужен (оставшееся время ГРУППЫ события)
							unset($changes['events'][$event]['data']);
							unset($changes['events'][$event]['from_client']);
							if(APP_DEBUG)
								$this->log('обнулено из данных группы рассылки '.$type.' событие '.$event.' в связи с обнулением его в группе в '.$change_type);
						}
					}	
				}	
			} 
		}
		else
		{
			// не страшно ели там events с обнуленным actin - сервер еще раз проверит пакет и тут уже будет излишни тратить время на првоерки
			$local_changes[$change_type] = $changes;
		}
		
		// todo сделать уровни логирования тк этот лог очень тормозит особенно при создании существ на 30% 
		if(APP_DEBUG)
		{
			if($changes)
				$this->log('внесены изменения '.$change_type.' группу рассылки '.print_r($changes, true));	
			else
				$this->log('после уникализации изменений группы рассылки '.$change_type.' ничего не осталось уникального');	
		}			
	}

	// todo заменить на spl очередь забирая из нее что бы удалялось
	public function getChanges():array
	{	
		if($changes = $this->_changes)
		{		
			$this->_changes = [];
			$change_type = EntityChangeTypeEnum::All->value;
		
			if($this->map_id == MAP_ID && Map2D::sides() && !World::isRemove($this->key))
			{
				// нет смысла проверять в на empty($changes[$change_type]['map_id'] тк если менялась карта World::isRemove  будет true и сюда уже не попадет 
				if(isset($changes[$change_type]) && (isset($changes[$change_type]['x']) || isset($changes[$change_type]['y'])))
				{
					// если сменилась карта дополучим новые координаты и ее саму
					if($new_map_id = $this->checkLeave())
					{
						if(APP_DEBUG)
							$this->log('запросим дополнительные изменения в связи с '.($new_map_id == MAP_ID?'выходом за границы карты (недостаточного для перехода на другую локацию)':'переходом на новую карту'));
						
						$changes = array_replace_recursive($changes, $this->_changes);
						$this->_changes = [];						
					}
				}							
			}
		}		
		return $changes;
	}
	
	// возвращает какие свойства текущего класса могут быть возвращены в виде массива
	// PS все итерируемые объекты у нас - это коллекции а не массивы (в C# имеет значение) поэтому если пусто подаем null а не пустой массив
	// Никакими другими интераторами обектов не пользуемся тк Очень медленное !
	// todo можно сделать как с приватными копонентами и не перебирать если сохранен статический массив со ссылками (но тогда надо будет загружать разом все события с time = null )
	// Внимание! возвращает се события и компоненты в тч скрытые
	public final function toArray():array
    {
		$return = array();
		foreach(static::columns() as $key=>$bool)
		{
			if(!isset($this->$key)) continue;
			
			switch($key)
			{
				case 'events':
					$return[$key] = null;
					if($this->$key !== null)
					{
						foreach($this->$key->all() as $name=>$value)
						{
							$return[$key][$name] = $value->toArray();
						}
					}	
				break;	
				case 'components':
					$return[$key] = null;
					if($this->$key !== null)
					{
						// не шлем компоненты помеченные в админп анели как скрытые для рассылки другим. хотя в коде lua  доступны все
						foreach(Components::list($this->type) as $name => $component)
						{
							// не шлем компоненты значение которых null и дефолтное значение null (но 0 или пустую строку можем еслиувафгде и равен null)
							if($component['isSend'] && (($value = $this->$key->get($name)) || $component['default']!==null || $value!==null))
								$return[$key][$name] = $value;
						}
					}
				break;
				
				default:
					$return[$key] = $this->$key;
				break;
			}		
		}
		
		if(APP_DEBUG)
			$this->log('запрос на преобразование в массив '.print_r($return, true));	
		
        return $return;   
    }
	
	public function save(?int $new_map_id = null)
	{
		if($this->map_id != MAP_ID) 
			throw new Error('Сохранение существ находящихся на другой карте запрещено');
		
		if(APP_DEBUG)
			$this->log('сохранение существа');	
		
		// перед запросом данных на сохранение проверим не ушли ли мы с локации и не нужно ли нам координаты подробвнять (может чуть за пределы вышли, но при этом не достаточно что бы считался как переход)
		// мы это же делаем при рассылки изменений но вот тут надо тоже тк можем в текущем кадре перейти на локацию или выйти чуть за пределы		
		if(!$new_map_id)
		{
			if(($new_map_id = $this->checkLeave()) && $new_map_id == MAP_ID)
				$new_map_id = null;
		}
		
		if($new_map_id)
		{
			if($new_map_id == MAP_ID) 
				throw new Error('Нельзя сохранить существо с пометкой о новой карте когда она равна текущей');
		
			if(APP_DEBUG)
				$this->log('заменяем игроку карту при сохранении');
		}	

		PHP::save($this->type, $this->key, $new_map_id);	
	}
	
	// проверка на уход с карты осуществляется в самом конце, после получения изменений существа (тк в процессе кадра может меняться и координаты и карта - например существо толкнут за пределы карты карты но вызовется телепор и все в одном событие кадра)
	// +  и долго при каждой смене позиции проверять поэтому тут разово
	// todo можно рекурсивно вызывать ее проверяя и сдвигая на соседние карты существо
	private function checkLeave():?int
	{
		if($this->map_id != MAP_ID)
			throw new Error($this->key.': нельзя проверять на уход с текущей локоации сущест которые принадлежат к другим локациям');	

		if(!$sides = Map2D::sides())
			throw new Error('отсутвуют смежные локации у карты '.MAP_ID.' для пересчета координат');	

		$new_map_id = null;
		$this_x = $this->x;
		$this_y = $this->y;
			
		if(APP_DEBUG)
			PHP::log('проверка координат ('.$this_x.', '.$this_y.') на предмет ухода с локации');	
		
		// это точки существа учитывающая точку текущей карты на плоскости открытого мира учитывая что отсчет идет от левого верхнего угла вниз
		// (int)  обязательен тк может вернуть -0
		$x = (int)round($this_x + $sides[MAP_ID]['x']);	
		$y = (int)round($this_y - $sides[MAP_ID]['y']);	
		
		// если зашли за левый край карты
		if(round($this_x)<0)
		{
			foreach($sides as $map_id=>$coord)
			{
				if($map_id == MAP_ID) continue;
				// находим карту которая левее текущей и ее верхяя (начальная) точка выше (т.е больше в числовом значении по оси y) текущей позия  y существа и ее нижняя точка так же ниже позиции (те захватывает наше существо)
				if($coord['x']==$sides[MAP_ID]['x'] - Map2D::getInfo($map_id)['width'] && $coord['y']>=$y && $coord['y'] - (Map2D::getInfo($map_id)['height']-1)<=$y)
				{
					$new_map_id = $map_id;
					break;
				}
			}
			
			if(empty($new_map_id))
				throw new Error($this->key.': Координаты существа ('.$this_x.', '.$this_y.') вышли по x влево за пределы текущей карты не найдено соответвующей карты '.print_r($sides, true));
		}
		// если за правый (притом если координата равна размеру карты то уже перешли за край тк отсет идет координат от 0 а размер карты от 1. Пример позиция 9 по x при размере карты 10 это и будет крайняя тк от 0 до 9 это 10 единиц)
		elseif(round($this_x)>=Map2D::getInfo(MAP_ID)['width'])
		{
			foreach($sides as $map_id=>$coord)
			{
				if($map_id == MAP_ID) continue;
				if($coord['x']==$sides[MAP_ID]['x'] + Map2D::getInfo(MAP_ID)['width'] && $coord['y']>=$y && $coord['y'] - (Map2D::getInfo($map_id)['height']-1)<=$y)
				{
					$new_map_id = $map_id;
					break;
				}
			}
			
			if(empty($new_map_id))
				throw new Error($this->key.': Координаты существа ('.$this_x.', '.$this_y.') вышли по x вправо за пределы текущей карты не найдено соответвующей карты '.print_r($sides, true));
		}
		// если ушли выше границы текущей карты и начальная x карты которую мы рассматриваем куда перешли лежит ДО текущей, и после включительно (захватывает)
		elseif(round($this_y)>0)
		{
			foreach($sides as $map_id=>$coord)
			{
				if($map_id == MAP_ID) continue;
				if($coord['y']==$sides[MAP_ID]['y'] + (Map2D::getInfo($map_id)['height']) && $coord['x']<=$x && $coord['x'] + (Map2D::getInfo($map_id)['width']-1)<=$x)
				{
					$new_map_id = $map_id;
					break;
				}	
			}

			if(empty($new_map_id))
				throw new Error($this->key.': Координаты существа ('.$this_x.', '.$this_y.') вышли по y вверх за пределы текущей карты но не найдено соответвующей карты '.print_r($sides, true));			
		}
		// ушли вниз
		elseif(round($this_y)<=Map2D::getInfo(MAP_ID)['height']*-1)
		{
			foreach($sides as $map_id=>$coord)
			{
				if($map_id == MAP_ID) continue;
				if($coord['y']==$sides[MAP_ID]['y'] + Map2D::getInfo(MAP_ID)['height'] && $coord['x']<=$x && $coord['x'] + (Map2D::getInfo($map_id)['width']-1)<=$x)
				{
					$new_map_id = $map_id;
					break;
				}
			}
			
			if(empty($new_map_id))
				throw new Error($this->key.': Координаты существа ('.$this_x.', '.$this_y.') вышли по y вниз за пределы текущей карты но не найдено соответвующей карты '.print_r($sides, true));	
		}
					
		if($new_map_id)
		{
			Map2D::encode2dCoord($this_x, $this_y, MAP_ID, $new_map_id);
			
			if(APP_DEBUG)
				$this->log('ушел на карту '.$new_map_id.' ('.$this_x.', '.$this_y.') по результатам проверки координат');
			
			$this->__set('map_id', $new_map_id);	
		}	
		
		if($this_x<0)
		{			
			if(ceil($this_x) == 0)
			{
				if(!$new_map_id)
					$new_map_id = MAP_ID;
			
				if(APP_DEBUG)
					$this->log('скорректируем координаты x ('.$this_x.') на 0 тк они выходят за границу карты');
			
				$this_x = 0;
			}
			else
				throw new Error($this->key.': при проверке координат '.($new_map_id?'и переходе на карту'.$new_map_id:'').' x ('.$this_x.') стало меньше 0 что не возможно для родительской локации тк отсчет идет от 0 и не может быть меньше '.($new_map_id?'в том числе на новой карте':'без смены карты'));
		}
		
		if($this_y>0)
		{
			if(floor($this_y) == 0)
			{				
				if(!$new_map_id)
					$new_map_id = MAP_ID;
			
				if(APP_DEBUG)
					$this->log('скорректируем координаты y ('.$this_y.') на 0 тк они выходят за границу карты');
				
				$this_y = 0;	
			}
			else
				throw new Error($this->key.': при проверке координат '.($new_map_id?'и переходе на карту'.$new_map_id:'').' y ('.$this_y.') стало больше 0 что не возможно для родительской локации тк отсчет идет от 0 и не может быть больше (выше края карты) '.($new_map_id?'в том числе на новой карте':'без смены карты'));
		}		
					
		// тригер изменения позиции не вызовется уже тк существо уже помечено на удаление при смене map_id а только запишуться setChanges в EntityAbsstract
		if($new_map_id)
			$this->__set('position', new Position($this_x, $this_y, $this->z));	
				
		return $new_map_id;		
	}
	
	// функция вызывающаяся при вызове НЕ статичного ->log nr тут нам нужен this->map
	public function log(string|array $comment):void
	{
		$comment = $this->key.($this->map_id != MAP_ID?' (с локации '.$this->map_id.')':'').': '.(is_array($comment)?print_r($comment, true):$comment);
		PHP::log($comment);
	}	

	// функция вызывающаяся при вызове НЕ статичного ->log nr тут нам нужен this->map
	public function warning(string|array $comment):void
	{
		$comment = $this->key.($this->map_id != MAP_ID?' (с локации '.$this->map_id.')':'').': '.(is_array($comment)?print_r($comment, true):$comment);
		PHP::warning($comment);
	}
	
	public final function __clone():void
	{
		throw new Error('Клонирование объекта существа запрещено');
	}
	
	// если этого не делать будет утечка памяти при удалении существа с карты тк ссылка останется тут	
	public final function __destruct()
	{	
		if(!empty($this->forward))
			$this->forward->__destruct();		
		
		if(!empty($this->position))
			$this->position->__destruct();
		
		if(!empty($this->events))
			$this->events->__destruct();
		
		if(!empty($this->components))
			$this->components->__destruct();			
	}
}