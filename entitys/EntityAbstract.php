<?php
// Осторожно! родительский класс сущностей. все методы создания сущностей и вспомогательные тут. здесь стараться ничего не менять
// все поля приватные. Ничего не должно меняться внутри этого родительского класса после __construct без попадания в changes
abstract class EntityAbstract 
{	
	private array $_changes = [];							// последнии изменения в объектах - рассылается игрокам и вмежным локациям

	public readonly int $id;								// публичное тк ненужно отправлять в клиент и не нужно через магические методы проганять (тк оно и не меняется)
	public readonly string $key;						
	public readonly string $type;						
	private readonly int $map_id;							// карту поменять нельзя, при смене ее только рассылаются изменения (только при отключение существа и перезаписи в бд можно менять)

	// бывает между событиями нужно переносить какой то буфер который нельзя и не нужно ставить как парметр события (например замыкания создавать) и надо что бы они удалялись с удалением существа.
	// в качестве прмиера когда это нужно смоттрите код события fight/bolt  где я кеширую созданные через eval замыкания (тк каждый раз создавать их долго) или move/walk/to  где поиск пути кеширую
	public array $buffer = array();							

	// только для чтения что бы не переопределять их а только менять свойства
	private readonly Position $position;
	private readonly Forward $forward;
	
	private readonly Components $components;							// компоненты заполняются после инициализации объекта что бы дернулся их код lua с заполненным _G['objects']							
	private readonly Events $events;					   				// объект  Event управляющий событиями пришедших от клиента или добавленные вручную создается в режиме сервера работы при добалении в колецию World
	private bool $remote_update = false;
	
	// объявим общие обязательные параметры
	public function __construct
	(
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
	
		?int $map_id = null,							// может быть null когда карта удаляется с сервера
		?int $id = null,								// может быть null когда новое что то добавляется на сервер	
		
		array $components = array(),														
		array $events = array(),

		bool $remote_update = null,					// если true то этому существу можно обновить данные в текущий момент	
		...$arg											// все остальное что не нужно или новые поля что забыли задейстсвать (что бы не упал сервер)
	)
	{
		if (PHP_SAPI !== 'cli') 
			throw new Error("Запуск возможен только в режиме CLI сервера");	
		
		if(Block::$objects)
			throw new Error('Стоит запрет на добавление новых существ во избежание зацикливание');		
		
		if(!DEFINED('MAP_ID'))
			throw new Error('не определена карта сервера MAP_ID');
		
		if(!empty($arg))
			throw new Error('неизвестные поя создания сущности '.print_r($arg, true));
		
		if(empty($id))
		{
			$id = hrtime(true);
			
			// до тех пор пока не будет уникальным на карте наш id мы прибавляем 
			while(World::isset(static::getKey($id)))
			{
				$id++;	
			}	
		}
		elseif(World::isset(static::getKey($id)))
		{
			throw new Error('Существо '.static::getKey($id).' уже существует в коллекции мира и не может быть создано снова');
		}
			
		$this->id = $id;
		$this->key = static::getKey($this->id);
		$this->type = static::getType();
		
		if(!$map_id)
		{
			$map_id = $this->map_id = MAP_ID;	
			if(APP_DEBUG)
				$this->log('установлена карта текущего сервера');
		}
		else
			$this->map_id = $map_id;
		
		if($remote_update)
		{
			if((!$trace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 3)) || empty($trace[2]) || ($trace[2]['class'] != RemoteCommand::class))
				throw new Error('параметр существа указывающий что существо обновляется пакетом с удаленной локации нельзя указать вручную при создании существа '.print_r($trace, true));
			
			$this->remote_update = $remote_update;
		}
		
		if(!$action)
			$this->action = SystemActionEnum::ACTION_LOAD;
		elseif($action == SystemActionEnum::ACTION_REMOVE)
			throw new Error('существо '.$this->key.' '.($this->map_id != MAP_ID?'с другой локации':'').' не может быть создано с пометкой action что оно удаляется');					
				

		// логирование выше не ставить !  Тк карта должна быть обявлена до первого лока
		if(APP_DEBUG)
			$this->log('создание объекта новой сущности');
		
		// посмотрим случайные позиции с указанной карте или текущей если не указана
		if(!$tiles = Map2D::getCurrentMapTiles())
			throw new Error('Нет ниодной свободной клетки без физики');
		
		try
		{
			$forward = new Forward($this->forward_x, $this->forward_y, $this->forward_z);			
		}
		catch(Throwable $ex)
		{
			$this->warning('не верно указаны координаты направления ('.$this->forward_x.Position::DELIMETR.$this->forward_y.Position::DELIMETR.$this->forward_z.') у существа '.$this->key.': '.$ex);
			
			$this->forward_x = 0;							
			$this->forward_y = 1;	
			$this->forward_z = 0;	
		}
		
		$this->forward 	= new Forward(object: $this);
				
		// пока все под 2Д
		$tile = round($this->x).Position::DELIMETR.round($this->y).Position::DELIMETR.round($this->z);
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
					$this->log('Тайл '.$tile.' не доступен на карте '.$this->map_id.', установим случайный '.$new_position);
				
					$explode = explode(Position::DELIMETR, $new_position);
					$this->x = $explode[0];                                                                                    
					$this->y = $explode[1];                                                                                    
					$this->z = $explode[2];                                                                                    
				}		
			}
		}
	

		$this->position	 = new Position(object: $this);	

		//	проверим локальные позиции свободны ли	
		if($this->map_id!=MAP_ID && !Map2D::getTile($this->position->tile()))
		{
			throw new Error('При создании копии существа '.$this->key.' с удаленной локации '.$this->map_id.' переданы позиция '.$this->position->tile().'  не сущеcтвующие в матрице для этой локации');
		}
		
		if($this->map_id!=MAP_ID && empty(Map2D::sides()[$this->map_id]))
			throw new Error('Карта '.$this->map_id.' для создаваемого существа отсутвует не принадлежит центральной ('.MAP_ID.') или смежной из списка '.implode(',', array_keys(Map2D::sides())));	
		
		if(APP_DEBUG && $this->map_id!=MAP_ID)
			$this->log('сущность создается с другой карты ('.$this->map_id.')');

		// сначал поместим все публичные изменения на рассылку
		if($this->map_id == MAP_ID)
		{
			if(APP_DEBUG)
				$this->log('Подготовим общие (без свойств и компонентов) данные нового существа как изменения для рассылки всем игрокам и смежным локациями');
			
			$this->setChanges($this->toArray());
		}			
		
		// события и компоненты дополнят changes внутри себя при инициализации
		$this->events = new Events($this, $events);
		$this->components = new Components($this, $components);			
	}	
		
	// какие колонки выводить в методе toArray и которые можно изменить из вне класса
	public static function columns():array
	{
		return array				
		(	  						
			'id'=>true,	
			'prefab'=>true,	
			'action'=>true,
			'sort'=>true,

			'x'=>true,							
			'y'=>true,							
			'z'=>true,
			
			'forward_x'=>true,							
			'forward_y'=>true,	
			'forward_z'=>true,	
			
			'lifeRadius'=>true,
			
			'components'=>true,								
			'events'=>true,								
															
			'map_id'=>true,						
			'created'=>true
		);	
	}
	
	private function position_columns():array
	{
		return ['position'=>true, 'forward'=>true];
	}
	
	// магические методы позволят из вне получать данные и меняя их писать что они поменялись в changes
	final public function __get(string $key):mixed
	{
		if((isset(static::columns()[$key]) || isset($this->position_columns()[$key]) || $key == 'remote_update') && property_exists($this, $key)) 
			return $this->$key;	
		else
			throw new Error('нельзя получить поле '. $key);
	}
	
	
	final public function __isset(string $key):bool
	{
		return $this->__get($key)!=null?true:false;
	}

	public function remove()
	{	
		World::remove($this->key);
	}
		
	// этот метод нужен только для вызова из update_from_remote_location из RemoteCommand с параметром  remote_command	
	final public function __set(string $key, mixed $value):void
	{
		// то только в таймауте тк незачем в приципе там менять свойства сущности
		if(World::isset($this->key) && Block::$object_change && Block::$object_change!=$this->key)
			throw new Error('Стоит запрет на изменение компонентов и свойств '.(is_string(Block::$object_change)?'других существ':'любого существа'));
		
		#Perfomance - исключительно что бы убыстрить доступ к данным (при записи скорость таже, а при чтении х2)
		$map_id = $this->map_id;
		$remote_update = &$this->remote_update;
		
		if($key == 'remote_update')
		{
			if((!$trace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)) || $trace[1]['class'] != RemoteCommand::class)
				throw new Error('параметр существа указывающий что существо обновляется пакетом с удаленной локации нельзя менять вручную '.print_r($trace, true));
		    
			if(MAP_ID == $map_id)
                throw new Error('нельзя устанавливать параметр существа указывающий что существо обновляется пакетом с удаленной локации когда его карта текущая');
			
			$remote_update = $value;
			return;
		}
		
		if(!$remote_update && MAP_ID != $map_id) 
			throw new Error('Нельзя напрямую менять параметры ('.$key.') существа с другой локации');		
		
		$new_map_id = null;
		if((isset(static::columns()[$key]) || isset($this->position_columns()[$key])) && $key!='events' && $key!='components')
		{
			// отправляем в клиент только если изменилось значение или action (он может несколько раз повторятся)
			if($this->$key != $value || $key=='action')
			{
				if(APP_DEBUG)
					$this->log('изменение поля '.$key.' на '.$value.($remote_update?' в режиме синхронизации локации с '.$map_id:''));	
				
				if(isset($this->position_columns()[$key]))
				{
					foreach(['x', 'y', 'z'] as $position)
					{
						// с forward такой колхоз тк нельзя отдельно менять 
						if(is_a($value , Forward::class))
						{
							if(!is_a($value , Forward::class))
								throw new Error('Значение свойства '.$key.' не является объектом направления движения');
							
							$this->__set('forward_'.$position, $value->$position);
						}
						else
						{
							if(!is_a($value , Position::class))
								throw new Error('Значение свойства '.$key.' не является объектом позиции');
							
							$this->$key->__set($position, $value->__get($position));		
						}
					}	
				}
				else
				{
					// работа с бесщовным миром
					switch($key)
					{
						// если мы на текущей карте и меняются x и y проверим не ушли ли мы на смежную карту
						case 'x':
						case 'y':
						case 'z':
								// это должно быть именно тут , не в Position (тк метод update может вызываться принудительно минуя свойство ->position)
								World::removePosition($this->key);
									$this->$key = $value;
									
									// если пакет с удаленного мира не рассылаем никаких изменений
									if($map_id == MAP_ID)
										$this->setChanges([$key=>$value]);
									
								World::addPosition($this->key);
								
							// именно выходим из функции, не break!
						return;					
						
						// если мы на текущей карте и меняются x и y проверим не ушли ли мы на смежную карту
						case 'forward_x':
						case 'forward_y':
						case 'forward_z':
							if($value<-1 || $value>1)
								throw new Error('Значение поля '.$key.' '.$value.' превысило единицу');
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
								
								$this->setChanges([$key=>$value]);
								World::remove($this->key, $value);	
							}

						// именно выходим из функции, не break!
						return;
					}
			
					$this->$key = $value;
					
					if($map_id == MAP_ID)
						$this->setChanges([$key=>$value]);
				}
			}	
		}
		else
			throw new Error('нельзя изменить поле '. $key);
	}
	
	// todo сделать что бы это сразу вносилось в глобальный кеш World для отправки (тк там и есть данные удаления сущнсоти котоыре не снять уже после самого ее удаления тк нет $this)
	public function setChanges(array $changes, EntityChangeTypeEnum $type = EntityChangeTypeEnum::All)
	{
		if(empty($changes))
			throw new Error('Изменения существа '.$this->key.' не могут быть пустым массивом');
		
		if($type!=EntityChangeTypeEnum::Players && ($denied = array_diff_key($changes, static::columns())))
			throw new Error('нельзя отправлять пакет изменений не связанных с полями сущности '.print_r($denied, true));	
		
		if($this->map_id != MAP_ID)
		{
			if($type == EntityChangeTypeEnum::All)
				$type =	EntityChangeTypeEnum::Events4Remote;
			elseif($type != EntityChangeTypeEnum::Events4Remote)
				throw new Error('Нельзя пометить пакет измнений существ с другой локации иначе чем '.EntityChangeTypeEnum::Events4Remote->value);		
		}
		
		if($type == EntityChangeTypeEnum::Events4Remote)
		{
			if(empty($changes['events']) || count($changes)>1)
				throw new Error('Пакет изменений '.$type->value.' не может содержать что то кроме пакетов событий');	
			
			// для неигроков данные приватныех событий NPC пихаем в Private, тк во избежание ошибок мы проверяем в websocket сервере что бы если с текущей лкоации то там были только игроков события
			if($this->map_id == MAP_ID && $this->type != EntityTypeEnum::Players->value)
				$type = EntityChangeTypeEnum::Privates;			
		}
		
		$change_type = $type->value;
		if(APP_DEBUG)
			$this->log('внесены изменения ('.$change_type.' рассылка) '.print_r($changes, true));	
		
		$local_changes = &$this->_changes;
		
		// не используем array_replace  тк данные компонентов и событий могут быть массивы и они должны перезаписаться а не слиться воедино со старыми (если старые есть тк в течении кадра событие или компонент могли меняться по несколько раз)
		if(!empty($local_changes[$change_type]))
		{
			if($local_changes[$change_type]!=$changes)
			{
				foreach($changes as $key=>$value)
				{
					if(is_array($value) && isset($local_changes[$change_type][$key]) && $local_changes[$change_type][$key]!=$value)
					{
						foreach($value as $key2=>$value2)
						{	
							if(is_array($value2) && isset($local_changes[$change_type][$key][$key2]) && $local_changes[$change_type][$key][$key2]!=$value2)
							{
								// не обязатнльно, тк websocket перед отправклй пролверит тоже самое (но что бы не слать лишние пакеты данных можно и тут сделать заодно понять как работает websocket в части удаления данных)
								if($key == 'events' && isset($value2['action']) && empty($value2['action']))
								{
									foreach(array_keys($local_changes) as $change_type2)
									{
										unset($local_changes[$change_type2]['events'][$key2]['from_client']);	
										unset($local_changes[$change_type2]['events'][$key2]['data']);
										
										if(isset($value2['data']))
											throw new Error('Нельзя пометить событие как завершенное и указать для него новые данные');
										
										if(isset($value2['from_client']))
											throw new Error('Нельзя пометить событие как завершенное и указать для него флаг об хапущенности с клиентской части или серверной');

										if(empty($local_changes[$change_type2]['events'][$key2]))
											unset($local_changes[$change_type2]['events'][$key2]);	
										if(empty($local_changes[$change_type2]['events']))
											unset($local_changes[$change_type2]['events']);										
										if(empty($local_changes[$change_type2]))
											unset($local_changes[$change_type2]);										
									}
								}
								
								foreach($value2 as $key3=>$value3)
								{	
									$local_changes[$change_type][$key][$key2][$key3] = $value3;
								}
							}
							else
								$local_changes[$change_type][$key][$key2] = $value2;
						}
					}
					else
						$local_changes[$change_type][$key] = $value;	
				}
			}
		}
		else
		{
			// не страшно ели там events с обнуленным actin - сервер еще раз проверит пакет и тут уже будет излишни тратить время на првоерки
			$local_changes[$change_type] = $changes;
		}
	}	
	
	// todo заменить на spl очередь забирая из нее что бы удалялось
	final public function getChanges():array
	{	
		if($changes = $this->_changes)
		{		
			if(APP_DEBUG)
				$this->log('запрошены текущие изменения '.print_r($changes, true));	
		
			$this->_changes = array();	
			$change_type = EntityChangeTypeEnum::All->value;
		
			if($this->map_id == MAP_ID && Map2D::sides() && !World::isRemove($this->key))
			{
				if(isset($changes[$change_type]) && (isset($changes[$change_type]['x']) || isset($changes[$change_type]['y'])) && (empty($changes[$change_type]['map_id']) || $changes[$change_type]['map_id'] == MAP_ID))
				{
					// если сменилась карта дополучим новые координаты и ее саму
					if($map_id = $this->checkLeave())
					{
						if(APP_DEBUG)
							$this->log('запросим дополнительные изменения в связи с '.($map_id == MAP_ID?'выходом за границы карты (недостаточного для перехода на другую локацию)':'переходом на новую карту'));
						
						$changes = array_replace_recursive($changes, $this->_changes);	
						$this->_changes = array();							
					}
				}							
			}
		}
			
		return $changes;
	}
	
	// если мы вышли за пределы текущей карты сервера - нам пересчитывает коордираны на новой и передаст их с новой картой 
	// todo можно рекурсивно вызывать ее проверяя и сдвигая на соседние карты существо
	protected final function checkLeave():?int
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
		$x = round($this_x + $sides[MAP_ID]['x']);	
		$y = round($this_y - $sides[MAP_ID]['y']);	
		
		// если зашли за левый край карты
		if(round($this_x)<0)
		{
			foreach($sides as $map_id=>$coord)
			{
				if($map_id == MAP_ID) continue;
				// находим карту которая левее текущей и ее верхяя (начальная) точка выше (т.е больше в числовом значении по оси y) текущей позия  y существа и ее нижняя точка так же ниже позиции (те захватывает наше существо)
				if($coord['x']==$sides[MAP_ID]['x']- Map2D::getInfo($map_id)['width'] && $coord['y']>=$y && $coord['y'] - (Map2D::getInfo($map_id)['height']-1)<=$y)
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
				if($coord['x']==$sides[MAP_ID]['x']+Map2D::getInfo(MAP_ID)['width'] && $coord['y']>=$y && $coord['y'] - (Map2D::getInfo($map_id)['height']-1)<=$y)
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
				if($coord['y']==$sides[MAP_ID]['y']+Map2D::getInfo(MAP_ID)['height'] && $coord['x']<=$x && $coord['x'] + (Map2D::getInfo($map_id)['width']-1)<=$x)
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
			$this->__set('x', $this_x);
			$this->__set('y', $this_y);			
		}

		if($this_x<0)
		{			
			if(ceil($this_x) == 0)
			{
				if(!$new_map_id)
					$new_map_id = MAP_ID;
			
				if(APP_DEBUG)
					$this->log('скорректируем координаты x ('.$this_x.') на 0 тк они выходят за границу карты');
			
				$this->__set('x', 0);
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
				
				$this->__set('y', 0);		
			}
			else
				throw new Error($this->key.': при проверке координат '.($new_map_id?'и переходе на карту'.$new_map_id:'').' y ('.$this_y.') стало больше 0 что не возможно для родительской локации тк отсчет идет от 0 и не может быть больше (выше края карты) '.($new_map_id?'в том числе на новой карте':'без смены карты'));
		}	
		
		return $new_map_id;		
	}
	

	abstract protected static function getType():string;	

	// Возвращает обе части для ключа Redis
	private static function getKey(int $id):string
	{
		return static::getType().EntityTypeEnum::SEPARATOR.$id;
	}		

	// возвращает какие свойства текущего класса могут быть возвращены в виде массива
	// PS все итерируемые объекты у нас - это коллекции а не массивы (в C# имеет значение) поэтому если пусто подаем null а не пустой массив
	// Никакими другими интераторами обектов не пользуемся тк Очень медленное !
	// todo можно сделать как с приватными копонентами и не перебирать если сохранен статический массив со ссылками (но тогда надо будет загружать разом все события с time = null )
	// Внимание! возвращает се события и компоненты в тч скрытые
	public function toArray():array
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
						foreach(Components::list(EntityTypeEnum::from($this->type)) as $name => $component)
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
	
				
	// функция вызывающаяся при вызове НЕ статичного ->log nr тут нам нужен this->map
	final public function log(string|array $comment):void
	{
		$comment = $this->key.($this->map_id != MAP_ID?' (с локации '.$this->map_id.')':'').': '.(is_array($comment)?print_r($comment, true):$comment);
		PHP::log($comment);
	}	

	// функция вызывающаяся при вызове НЕ статичного ->log nr тут нам нужен this->map
	final public function warning(string|array $comment):void
	{
		$comment = $this->key.($this->map_id != MAP_ID?' (с локации '.$this->map_id.')':'').': '.(is_array($comment)?print_r($comment, true):$comment);
		PHP::warning($comment);
	}
	
	// если этого не делать будет утечка памяти при удалении существа с карты тк ссылка останется тут	
	function __destruct()
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