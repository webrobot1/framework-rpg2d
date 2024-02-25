<?php
// это класс групп событий конкретной сущности и им на этой сущности можно манипулировать (добавить новое событие например или убрать уже существующее)
// там где setChanges если это не существо с текущей карты - мы всегда высылаем пакеты (тк они пойдут для другого сервера)
class EventGroup
{	
	private const MIN_EVENT_TIME_SENDING =  0.1;				// до скольки секунд оставшееся время до следующего события считать значительным что бы передавать на соседнюю локацию (и без этого там счетчики есть и будут использованы старые данные что событие готово)
	private const MAX_CHANGE_TIMEOUT =  0.010;					// до скольки секунд изменение между прошлым таймаутом и текущим считать не значительным и не передавать на клиент для уменьшения пакета
	
	private ?Event $event = null;								// событие в очереди на обработку
	private static array $_timeouts_sandboxes = array();		// массив песчониц таймаутов групп событий луа и js (статический тк они для всех групп одноименных одинаковы)
	
	private float $_time;										// до какого времени таймаут (распространяется не на определенный action а на событие, а если нало то нужно разные actiob выносить в отдельные группу ), учтено время работы события и время до момента обработник объекта
	private array $_timeout_cache = array();					// кеш последних таймаутов. что бы не спамить одними и теми же таймаутами

	function __construct(protected EntityAbstract $object, public readonly string $name)
	{	
		#Perfomance - прямая ссылка на свойство убыстрит получение данных X2 (за счет отсутствия постоянного обращение к объекту)
		$object_type = $this->object->type;
		
		if(!empty(Events::list()[$this->name]['entitys']) && !isset(Events::list()[$this->name]['entitys'][$object_type]))
			throw new Error('событие '.$this->name.' не разрешено для '.$object_type);
	
		if(APP_DEBUG)
			$this->log('создание группы событий');
		
		// если событие новое накинем пару мс что бы NPC не одновременно отрабатвали
		if($object_type != EntityTypeEnum::Players->value && $this->object->map_id == MAP_ID)
		{
			$pause = (rand(1, World::count())/100);
			if(APP_DEBUG)
				$this->log('смещение первого запуска на +'.$pause.' секунды для распределения нагрузки');
			
			$this->resetTime($pause);
		}
		else
			$this->resetTime();
	}

	// метод обнуляет текущее событие (происходит когда оно попадает на исполнение по времени таймаута)
	public function finish():void
	{	
		// TODO сделать првоерку запуска этого метода из Frame
		
		if(Block::$events) 
			throw new Error('сейчас запрещено завершать события');	
		
		if($this->object->map_id != MAP_ID)
			throw new Error('Событие может быть завершено (finish)  только существам с текущей локации (с удаленной возможно лишь принудительное удаление remove)');
		
		//именно ДО событий тк событие может изменить текущй таймаут
		// с этого момента все последующие запросы таймаута в других событиях будет из кеша до домента новой готовности события	
		$this->calculateTimeoutCache();
		$this->resetTimeout();
		
		// если событине не постоянное то обнулим событие (а если посточнное выполнится с теми же данными)
		if(!empty($this->event) && empty(Events::list()[$this->name]['methods'][$this->event->action]['isPersist']))
			$this->remove();
		elseif($this->remainTime()>=static::MIN_EVENT_TIME_SENDING && APP_DEBUG)
		{
			$this->log('завершение (finish) без удаления из списка к выполнению после таймаута');	
		}
	}	
	
	// remote - значит запрос на данное действие пришел с другой локации (в таком случае мы вносим изменения но рассылаем только игрокам что бы не послылать команды обратно)	
	public function remove()
	{		
		if(Block::$events) 
			throw new Error('сейчас запрещено удалять события');	
		
		if($this->event)
		{
			if(APP_DEBUG)
				$this->log('удаления (remove)');
		
			#Perfomance - экономит доли миллисекунды за счет отуствия постоянного обращение к свойствам объекта (везде пихать нет смысла, а только там где более 1 раза идет обращение)
			$is_another_map = ($this->object->map_id!=MAP_ID?$this->object->map_id:null);
			$remote_update = $this->object->remote_update;
		
			// если пытаемся событие удалить существу с другого сервера то только отправляем пакет выше, но фактически не меняем
			if(!$is_another_map || $remote_update)
				$this->event = null;
			
			$data = ['events'=>[$this->name=>['action'=>'']]];
			if(!$is_another_map || !$remote_update)
			{			
				if(Events::list()[$this->name]['sending'] > EventSendingEnum::NONE)
					$this->object->setChanges($data);
				else
				{
					if(!$is_another_map  && $this->object->type == EntityTypeEnum::Players->value)
						$this->object->setChanges($data, EntityChangeTypeEnum::Privates);
					else
						$this->object->setChanges($data, EntityChangeTypeEnum::Events4Remote);	
				}
			}		
		}		
	}
	
	// from_client - команд пришла от игрока и должна ставится в очередь не завиимо идет ли паралельно какой то процесс заблокироващий добавление событий (например таймаут паралельно просчитывается в другом потоке программы)
	public function update(string $action, array $data, bool $from_client = false):static
	{
		if(APP_DEBUG && debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] != Events::class)
            throw new Error('Добавление возможно лишь через '.Events::class);
		
		if(!$action)
			throw new Error('При добавления собятия в список его группы поле action не может быть пустым');
		
		if(Block::$events) 
			throw new Error('сейчас запрещено добавлять события');	
				
		if(empty(Events::list()[$this->name]['methods'][$action]))
			throw new Error('метод '.$action.' не доступен в классе '.$this->name);		
		
		#Perfomance - это экономия долей миллисекунды но тем не менее экономия за счет отсутвия обращения к обхектам
		$is_event_exists = !empty($this->event);
		$is_another_map = ($this->object->map_id!=MAP_ID?$this->object->map_id:null);
		$remote_update = $this->object->remote_update;
		
		
		### Внимание! ниже уже Exception при которых сервер не падает а отключатеся только клиент ###
		$new_data = array();
		if(!empty(Events::list()[$this->name]['methods'][$action]['params']))
		{
			foreach(Events::list()[$this->name]['methods'][$action]['params'] as $key=>$value)
			{
				if(in_array($key, ['object', 'from_client', '_data']))
					throw new Exception('нельзя паредать параметр '.$key.' событию '.$this->name.'/'.$action.' тк это системная переменная доступная в кода события');
				
				if(array_key_exists($key, $data))
				{
					$this->explore_params($data[$key]);
					$new_data[$key] = $data[$key];
					unset($data[$key]);
				}
				// data от существ сс другой локации может быть пустой или не полной (тк то что не меняется не пресылается и может по новой создано событие просто с теми же данными data)
				elseif($is_event_exists && $is_another_map && array_key_exists($key, $this->event->data))
					$new_data[$key] = $this->event->data[$key];
				// параметр с необязаельным значением (у них есть значение по умолчанию в тч и пустые строки)  тоже вписываем что бы было однозначно - не обзательный параметр пустой или новый	
				elseif($value!==null)
					$new_data[$key] = $value;
				else
					throw new Exception('параметр '.$key.' не имеет значения по умолчанию и отсутствует для события '.$this->name.'/'.$action.' у существа '.$this->object->key.' с '.($is_another_map?$is_another_map:'текущей').' локации  пакета '.print_r($data, true));								
			}
		}
		
		// если поданы данные которых быть не должно
		if($data)
			throw new Exception('неизвестные параметры события '.$this->name.'/'.$action.' ('.implode(',', array_keys(Events::list()[$this->name]['methods'][$action]['params'])).') '.implode(',', array_keys($data)));
			
		// если мы через код дтбляем событие то ничего не создаме а просто оптравится пакет изменений (но из другого сервера может прийти пакет что их существу с другой карты чью копия у нас тут м хотим установить новое событие)
		if(!$is_another_map || $remote_update)
		{
			$this->event = new Event($action, $new_data, $from_client);	
			if(APP_DEBUG)
				$this->log('новое событие '.($from_client?'от клиента':'системой'), dump: $new_data);	
		}
		else
		{
			$data = ['action'=>$action, 'data'=>$new_data];
			if(APP_DEBUG)
				$this->log('обновление данными удаленную локацию', dump: $data);
		}
					
		// если событие рассылаем или если запрос на создание события другой локации
		if(!$is_another_map || !$remote_update)
		{
			// как минимум action отправим
			if(!$is_another_map)
			{
				$data = $this->toArray();
				
				// и time не отправляем тк тут он не менялся а там где менялся - там и был отправлен
				unset($data['time']);
			}
			
			if(Events::list()[$this->name]['sending']>EventSendingEnum::NONE)
			{	
				// данные сбытия рвссылаются если оно есть текущее
				if($data['action'])
				{
					if(Events::list()[$this->name]['sending'] < EventSendingEnum::DATA)
					{	
						$this->object->setChanges(['events'=>[$this->name=>['data'=>$data['data']]]], EntityChangeTypeEnum::Privates);
						unset($data['data']);						
					}
					
					// рассылается только игроку создавшему событие (что бы сбрасывать событие раньше таймаута) на текущей карте
					// и только если создало событие сервер тк когда сами создаем мы помечаем это (съэкономим на этом чуть данных пакета пересылаемого)
					if(isset($data['from_client']))
					{
						if(empty($data['from_client']) && $this->object->type == EntityTypeEnum::Players->value)
							$this->object->setChanges(['events'=>[$this->name=>['from_client'=>$from_client]]], EntityChangeTypeEnum::Privates);				
					
						// другим игрокам незачем знать у кого какие события созданы сервером или игроком самим.
						unset($data['from_client']);
					}					
				}	
				
				$this->object->setChanges(['events'=>[$this->name=>$data]]);	
			}
			else
			{
				// как минимум action шлется лично игроку всегда 
				if(!$is_another_map && $this->object->type == EntityTypeEnum::Players->value)
				{
					$event = ['action'=>$data['action']];
					if(isset($data['from_client']) && empty($data['from_client']))
						$event = ['from_client'=>$data['from_client']];
					
					$this->object->setChanges(['events'=>[$this->name=>$event]], EntityChangeTypeEnum::Privates);
				}					
				else
					// мы разошлем локациям что бы у них был актуальный кеш данных
					$this->object->setChanges(['events'=>[$this->name=>$data]], EntityChangeTypeEnum::Events4Remote);		
			}
		}
		
		return $this;		
	}
	
	// выведет ошибку если в параметрах что то кроме массива или скалярных данных
	private function explore_params(mixed &$value):void
	{
		if(is_array($value))
		{
			foreach($value as $el)
			{
				$this->explore_params($el);
			}
		}
		else
		{
			// приведем строки - числа (из LUA) в тип число
			if(is_numeric($value)) 
				$value = (float)$value;
			elseif($value!==null && !is_string($value))
				throw new Error('Только скалярные значения, null и массивы с указанными типами могут содержаться в параметрах (передано '.gettype($value).') события '.$this->name);
		}
	}
	
	// сбрасывает таймаут события на текущий момент
	public function resetTime(float $seconds = 0):float
	{		
		if($seconds<0)
			throw new Error('добавленные секундые на который сбвсавыется таймаут от текущего времени должно быть положительным числом или нулем, '.$seconds.' указано');

		#Perfomance - это экономия долей миллисекунды но тем не менее экономия за счет отсутвия обращения к объекту
		$remote_update = $this->object->remote_update;
		
		if(isset($this->_time) && !$remote_update)
			$remain = $this->remainTime();
		
		$seconds = round($seconds, 3);
		$this->_time = microtime(true) + $seconds;

		// этот флаг приходит когда мы кадр обрабатываем (клиенты и так знают что время таймаута к концу подходи, ненужно лишний раз об этом уведомлять)
		// здесь будет укащано когда будет доступнен следующий вызов события. 
		
		// что бы не спамить памекатми и логами на события чьи паузы и так малы между событиями
		if(!isset($remain) || $remain>=static::MIN_EVENT_TIME_SENDING)	
		{
			if(APP_DEBUG)
				$this->log('сдвиг '.($this->event?'текущего':'следующего').' времени события (+'.$seconds.' секунд)');		
				
			if($this->object->map_id == MAP_ID || !$remote_update)
			{
				if(Events::list()[$this->name]['sending']>EventSendingEnum::NONE)    
				{	
					$this->object->setChanges(['events'=>[$this->name=>['time'=>$this->_time]]]);
				}
				// todo сделать option в select option = -1 , что не передавать даже время следующео вызова (написать в настройках группы события на странице что.. Если у вас в коде не используется првоерка таймаута этого событие и оно ничтожно мало то не передавать новое время события за увеличением пекета) соседним лкоациям и убрать этот колхоз (именно флаг checkbox, не часть select)
				else
					$this->object->setChanges(['events'=>[$this->name=>['time'=>$this->_time]]], EntityChangeTypeEnum::Events4Remote);	
			}
		}
		
		return $this->_time;
	}	
	
	public function resetTimeout():float
	{	
		return $this->resetTime($this->timeout());
	}	
		
	// вернет кешированный таймаут с последнего обновления
	// это полезно если мы уже запросили таймаут события для существ (выполнив код в песочнице) и что бы в рамках одного кадра более не запрашивать (снова не выполнять код в песочнице) - ведь и другие события могут запросить других событий таймаут  
	private function timeout():float
	{			
		if(Block::$timeout) 
			throw new Error('сейчас запрещено вызывать запросы таймаута во избежания зацикливания функции его расчета вызванной ранее');
				
		if(!isset($this->_timeout_cache[$this->name])) 
		{
			$this->calculateTimeoutCache();
		}
		
		return $this->_timeout_cache[$this->name];	
	}				
	
	private function calculateTimeoutCache()
	{
		// кода для таймауте не всегда должен быть в админ панели
		$default = 1/FPS;
		
		if(Block::$timeout) 
			throw new Error('сейчас запрещено вызывать функции расчета таймаута во избежания зацикливания с ранее вызванной');
		
		if($closure = &Events::list()[$this->name]['closure'])
		{ 
			// сохраним значения перед вызовом (тк таймаут всегда можно вызвать и какой то флаг мог быть true)	
			$recover = Block::current();
			
			// запретим добавлять новые евенты и менять свойства и компоненты в коде предназначенный только для возврата цифры  - таймаута			
			Block::$timeout = Block::$events = Block::$objects = Block::$components = Block::$object_change = true;
			
			try
			{
				if((!$value = $closure($this->object, isset($this->event)?$this->event->action:'')) || $value<$default)
				{
					$value = $default;
				}				
			}
			finally
			{
				// вернем все как было до таймаута  тк мы можем запрашивать его внутри  события
				Block::recover($recover);	
			}	
		}
		else 
			$value = $default;
		
		$value = round($value, 3);
		
		if($this->object->map_id == MAP_ID)
		{
			// отправка на клиент актуального таймаута
			if(!isset($this->_timeout_cache[$this->name]) || abs($this->_timeout_cache[$this->name] - $value) >= static::MAX_CHANGE_TIMEOUT)
			{  
				$data = ['events'=>[$this->name=>['timeout'=>$value]]];
				
				// соседни локациям и sandbox это не нужно - у него все есть данные что бы расчитать самому. код то един. поэтому Players 
				if(Events::list()[$this->name]['sending']>EventSendingEnum::NONE)
					 $this->object->setChanges($data, EntityChangeTypeEnum::Players);
				else
				{
					// если сработало публичное событие игрока то вышлем таймаут только этому игроку
					// вот тут момент что локациям все же шлется...но вводить еще один тип рассылок тяжело ради одного пункта - там куда это приходит я просто удалю
					
					if($this->object->type == EntityTypeEnum::Players->value && !empty($this->event->action) && !empty(Events::list()[$this->name]['methods'][$this->event->action]['isPublic']))
						$this->object->setChanges($data, EntityChangeTypeEnum::Privates);
				}
			}
		}
		
		// кеш хранится до пока событие не произойдет и непересчитает его
		$this->_timeout_cache[$this->name] = $value;
	}
	
	private function remainTime():float
	{	
		$remain = round($this->_time - microtime(true), 3);
		return $remain<0?0:$remain;
	}	

	final public function __get(string $key):mixed
	{
		switch($key)
		{
			case 'action':
				return !empty($this->event)?$this->event->action:'';
			break;			
			case 'from_client':
				return !empty($this->event)?$this->event->from_client:null;
			break;			
			case 'data':
				return !empty($this->event)?$this->event->data:[];
			break;			
			case 'time':
				return $this->_time;
			break;		
			case 'timeout':
				return $this->timeout();
			break;		
			case 'remainTime':
				return $this->remainTime();
			break;
			default:
				throw new Error('Нельзя получить поле события '.$key);
			break;
		}
	}	
	
	final public function __isset(string $key):bool
	{
		return $this->__get($key)!=null?true:false;
	}
	
	final public function __set(string $key, mixed $value):void
	{
		throw new Error('Запрещено напрямую менять параметр '.$key);
	}
	
	// Внимание! возвращает все события в тч скрытые
	// нет смысла при каждом добавлении уже существующего события слать таймаут (при вызове функции таймаута если что то сменилось мы отправим на клиент) - а если событие не существует - выше уже отправили полные даныне
	public function toArray()
	{	
		$return = array();
		
		// передаем в виде целого числа
		$return['time'] = $this->_time;
		
		if($return['action'] = $this->__get('action'))
		{
			// если у события нет вспомогательных данных не отправляем их. Если и были старые данные от при пустом занчении события они обнуляться
			$return['data'] = $this->__get('data');
			
			// если событие нельзя вызвать вручную то и нет смысла слать этот флаг
			if($this->object->type == EntityTypeEnum::Players->value && $this->object->map_id==MAP_ID && !empty(Events::list()[$this->name]['methods'][$this->event->action]['isPublic']))
				$return['from_client'] = $this->event->from_client;
		}
		
		return $return;
	}
	
	private function log(string $text, array $dump = array())
	{
		$this->object->log
		(
			$this->name.(!empty($this->event)?'/'.$this->event->action:'').': '.$text.($this->object->remote_update?' (синхронизация от удаленной локации '.$this->object->map_id.') ':'').($dump?' '.print_r($dump, true):'')
		);
	}
	
	// если этого не делать будет утечка памяти при удалении существа с карты тк ссылка останется тут
	function __destruct()
	{		
		unset($this->event);			
		unset($this->object);			
	}
}