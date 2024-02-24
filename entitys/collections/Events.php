<?php

// это так называемая фабрика событий определенной сущности World - имеет методы по добавление удалени и полному очищению всех событий. так же вызвает все события обхекта
class Events extends AbstractCollection
{		
	private static array $_list;

	// remote_command = true означает что событие для существ с другой локации мы будем отправлять во вне и не нужно рассылать игрокам
	function __construct(protected EntityAbstract $object, array $events = array())
	{	
		parent::__construct();

		if(APP_DEBUG)
			$object->log('запрос на '.($this->object->remote_update?'обновление пакетом с соседней локации':'создание').' событий сущности');
		
		// если от другой локации пришли данные то ненадо ее же оповещадь собирая для этого пакет 
		foreach($events as $group_name=>$group)
		{	
			if(isset($group['action']) || isset($group['data']) || isset($group['from_client']))
			{ 
				if(isset($group['action']) && empty($group['action']))
				{
					if(isset($group['data']) || isset($group['from_client']))
						throw new Error('Пакет обнуления события содержит дополнительные данные как для создания нового '.print_r($events, true));
					
					$this->get($group_name)->remove();
					
					unset($group['action']);
				}					
				else
				{
					if(empty($group['action']) && (!$action = $this->get($group_name)->action))
						throw new Error('Нельзя обновить обнвоить событие данными если оно не имело активного action и в пакете отсутвует тот который нужно установить '.print_r($events, true));
					
					$this->add
					(
						$group_name
						, 
						($group['action']??$action)
						, 
						($group['data']??[])
						, 
						($group['from_client']??($this->get($group_name)->from_client??false))
					);

					unset($group['action']);
					unset($group['data']);
					unset($group['from_client']);
				}
			}
			
			// это может быть не событие а просто обновление time
			if(!empty($group['time']))
			{
				$seconds = round($group['time'] - microtime(true));

				if($seconds<0)
					$seconds = 0;
				
				$this->get($group_name)->resetTime($seconds);		
				unset($group['time']);
			}
			
			// я не стал делать отдельный тип рассылки который приватный для игроков но не рассылается локациям....так что это единсченный случай когда игроку присылаются приватный пакет и приходит смежной локации
			if(!empty($group['timeout']))
				unset($group['timeout']);
			
			if($group)
				 throw new Error('После обработки события '.$group_name.' остались данные которые невозможно обработать '.print_r($group, true));            
		}	
	}

	// добавления нового события в список на следующий кадр
    // Внимание ! Этот метод может быть вызван от команы игрока в другом потоке! И до его завершения может начаться обработка событий! Добавляйте _values в самом конце! И проектируйте так что бы ничего не сломалось!
    public function add(string $group_name, string $action = 'index', array $data = array(), bool $from_client = false):void
	{
		if(!$group_name)
			throw new Error('нельзя добавить в коллекцию событие с пустым полем group_name');		
		
		if(!$action)
			throw new Error('нельзя добавить в коллекцию событие с пустым полем action');
		
		if(empty($this->values[$group_name]))
		{
			$this->create($group_name);
		}			
		$this->values[$group_name]->update($action, $data, $from_client);		
	}	

	// удалить из очереди Event
    public function remove(mixed $group_name): void 
	{
		// при этом соавтляем сам $this->values  тк там хранится таймаут и следующий запуск
		if(isset($this->values[$group_name]))
		{
			$this->values[$group_name]->remove();			
		}
    }

	// если у сущности события еще нет то создадим его - это случается когда мы из одного события запрашиаем данные таймаута другого которое еще не создавалось через add
    public function get(string $group_name): EventGroup
	{
		if(empty($this->values[$group_name]))
		{
			$this->create($group_name);
		}
		
		return $this->values[$group_name];
    }			
		
	// создать группу событий на сущности
	private function create($group_name):void
	{
		if(!empty($this->values[$group_name]))
		{
			throw new Error('группа события '.$group_name.' уже создано');
		}
			
		// todo првоерять из базы список доступных событий для аккаунта и привязывать класс Lua скриптов
		if(!$event = Events::list()[$group_name])
		{
			throw new Error('группы событий '.$group_name.' не существует для создании в коллекции сущности '.$this->object->key);
		}
		
		$this->values[$group_name] = new EventGroup($this->object, $group_name);				
	}
		
	public static function list(): array
	{
		if(!isset(static::$_list))
			throw new Error('События не были проинициализированы через Event::init(...) для передачи списка');			
		
		return static::$_list;
    }
	
	public static function init(array $list)
	{
		if(APP_DEBUG)
			PHP::log('Инициализация событий и групп');
		
		foreach($list as $group_name=>&$group)
		{
			if(!empty($group['code']))
			{
				if(APP_DEBUG)
					PHP::log('Инициализация функции таймаута группы событий '.$group_name);
				try
				{
					// не меняйте текстовку ошибки - она парсится в мастер процессе песочницы которая запустила этот процесс
					// todo пользователей не отключать по ошибке
					$group['closure'] = eval('return static function(EntityAbstract $object, string $action):float{ 
							'.$group['code'].' 
					};');	
				}
				catch(Throwable $ex)
				{
					throw new Error('code('.$group_name.'): Ошибка компиляции таймаут кода группы события '.$ex);
				}				
			}
			
			foreach($group['methods'] as $event_name=>&$event)
			{
				if(APP_DEBUG)
					PHP::log('Инициализация функции группы '.$group_name.' события '.$event_name);
				
				// создадим из текстовой версии кода которая нам пришла замыкание которое можно будет вызывать при сменен компонет а
				try
				{
					// не меняйте текстовку ошибки - она парсится в мастер процессе песочницы которая запустила этот процесс
					// todo отказаться от extraxt - долгая операция
					$event['closure'] = eval('return function(EntityAbstract $object, array $data, bool $from_client):void{				
							'.$event['code'].' 
					};');	
				}
				catch(Throwable $ex)
				{
					throw new Error('code('.$group_name.'/'.$event_name.'): Ошибка компиляции кода события '.$ex);
				}
			}
		}
		
		static::$_list = $list;
	}
	
	function __destruct()
	{	
		foreach($this->all() as $event_group)
			$event_group->__destruct();
			
		unset($this->object);			
	}
}