<?php
// структура пакета WebSocket сервера  
final class RemotePacket
{
	function __construct(public string $method, public array $params = array())
	{

	}
}


// функции запуска по команде с websocket сервера
// в самом проекте она не инициализируется, но в game server в StartupModel есть скомпилированная функция которая запускаясь вызвает этот класс первым делом сувая в него что пришло от игроков (а после уже вызывая Frame tick) 
abstract class RemoteCommand extends World
{	
	protected static function run(array $data)
	{
		if(!$data)
			throw new Error("нельзя запустить обработку комманад если пакет пустой");
			
		if(APP_DEBUG)
			PHP::log("Пришли пакеты из WebSocket: ".print_r($data, true));	
				
		foreach($data as $packet)
		{	
			if(!is_array($packet))
				throw new Error("пакет не является массивом данных ".print_r($packet, true));
			
			if(!is_string($packet[0]))
				throw new Error("Со стороны GameServer из Websocket пришел неопознанный пакет ".print_r($packet, true));
			
			$command = new RemotePacket($packet[0], $packet[1]??[]);
						
			if(!method_exists(self::class, $command->method))
				throw new Error('Отсутвует метод игрового сервера '.$command->method);
			
			if(PERFOMANCE_TIMEOUT)
				$start = hrtime(true);
		
			$method = $command->method;
			if($command->params)
				self::$method(...$command->params);
			else
				self::$method();
			
			// посчитаем среднее время чистой работы события в мс.
			if(PERFOMANCE_TIMEOUT)
				Perfomance::set('Sandbox        | выполнение команды '.$packet[0], (hrtime(true) - $start)/1e+6);		
		}
	}
	
	// удаление по отключение от websocket сервера игрока (только для текущей локации игрока соответсвенно)
	private static function player_remove(string $key)
	{	
		if(APP_DEBUG)
			PHP::log('удаление игрока '.$key.' по команде с websocket');
	
		if(!parent::isRemove($key) && parent::isset($key))
		{
			parent::removeEntity($key);
			
			// вообще нет смысла по ней слать какие то пакеты тк websocket уже у себя удалил игрока и всем разослал (и игрокам и смежным лкоациям)
			// бывает так что игрок добавился в том же кажре что и отрубил соединение тогда вернулся бы в websocket и пакет существа (со всеми его полями) и с пометкой что оно удаляется
			// это все предусмотрено в websocket что бы не создавать ошибки и не слать странные пакеты другим
			// так что обнулим его последние изменения что бы не слались никому не зибивая канал (в тч его action=remove тк уже этот пакет был разослаш в websocket)
			parent::get($key)->getChanges();
		}
	}	
	
	private static function player_add(string $key, string $ip, array $data)
	{	
		// если переход на карте был слишком ьыстрым
		if(parent::isset($key))
		{
			if(($entity = parent::get($key)) && $entity->map_id != $data['map_id'])
			{
				if(APP_DEBUG)
					$entity->log('подключился к новой карте быстрее чем она обработала команду на удаление от старой локации игрока');
					
				if(!parent::isRemove($key))
				{
					// что бы удалить существо с другой карты напрямую нужно поставить флаг системный (тк это не стандартная ситуация)
					$entity->setPermamentUpdate(true);
					$entity->remove();
				}
				
				parent::refresh();
			}
			else
			{
				if(APP_DEBUG)
					parent::get($key)->log('повторно вошел по другим ip '.$ip.' по команде от websocket');

				parent::get($key)->ip = $ip;
			}			
		}
		else
		{
			if(APP_DEBUG)
				PHP::log($key.' входит в игру из websocket');	
			
			$data['ip'] = $ip;
			parent::add(EntityTypeEnum::Players, $data);					
		}
	}
	
	private static function update_from_remote_location(int $remote_map_id, array $data):void
	{
		if($remote_map_id == MAP_ID)
			throw new Error('отправитель пакета обновления существ на локации не могут принадлежать текущему серверу');
		
		if(APP_DEBUG)
			PHP::log('обновление сущнотей локации '.$remote_map_id.' по команде с websocket');
		
		// перед внесением изменений с соседней лкоации разошлем все что перед этим ожидалось обработать 
		// нужно для того что ранее могла прийти команда на удаления (например player_remove) или добавления существа, мы должны обновить данные игрового мира (refresh) что бы продолжить дальше
		parent::refresh();	
		
		foreach($data as $map_id=>&$world)
		{	
			// если нам пришел пакет что существо стало иметь новую карту с которой мы не связаны
			if($map_id!=$remote_map_id && $map_id!=MAP_ID)
				throw new Error('с локации '.$remote_map_id.' пришел пакет об обновлених карты ('.$map_id.') и не ее и ни с пакетом обновления для текущей ('.MAP_ID.') карты');	
								
			foreach($world as $entity_type=>$entitys)
			{	
				if(!$type = EntityTypeEnum::tryFrom($entity_type))
					throw new Error('С локации '.$remote_map_id.' пришел пакет с неизвестным типом личности '.print_r($world, true));		
				
				foreach($entitys as $key=>$entity)
				{
					if(APP_DEBUG)
						PHP::log($key.': с локации '.$remote_map_id.' пришел запрос на '.(!parent::isset($key)?'создание':'изменение').' '.($map_id==MAP_ID?' сущности в текущей':'копии ее сущности (синхронизация)').' ');

					if(!empty($entity['permament_update']))
						throw new Error('запрещено принудительно высылать с соседней лкоации поле permament_update для существ '.print_r($entity, true));

					// новая сущность если - создаем ее копию на нашем игровом (не важно она создалась в удаленном игровом мире или эток оманда создать в нашем)
					// может быть такое что сущности нет но это пакеты которые пришли по существу которое уже удалено - тогда проверим что было id как обязательный атрибут создания новгого существа
					if(!parent::isset($key))
					{
						if(isset($entity['id']))
						{
							if(!empty($entity['map_id']) && $entity['map_id'] != MAP_ID)
								$entity['permament_update'] = true;
							
							$object = parent::add($type, $entity);
							
							if(!empty($entity['permament_update']))
								$object->setPermamentUpdate(false);
						}
						// может быть так что существо удалено или ушло на другую карту , а по нему в догонку пришли пакеты на изменение событий
						elseif(array_intersect_key($entity, ['events'=>true]))
							PHP::log('пришел пакет обновения события к существу которое успело быть удалено на игровом сервере '.print_r($entity, true));
						else
							throw new Error('существо '.$key.' отсутвует, а пакет не содержит сведений для создания его на сервере и не содержит событий '.print_r($entity, true));
					}
					//  пришел пакет на обновление сущесутвующей сущности
					else
					{
						if($map_id==MAP_ID && ($diff = array_diff_key($entity, ['events'=>true])))
							throw new Error('Попытка соседней локации сменить что то кроме событий у существующей сущности пакетом '.print_r($diff, true));
						
						$object = parent::get($key);
						if($map_id!=MAP_ID)
							$object->setPermamentUpdate(true);
						
						if(isset($entity['action']) && $entity['action'] == SystemActionEnum::ACTION_REMOVE)
						{
							$object->remove();
						}
						else
						{	
							// если нам пришел пакет что сущность удалена  - то удалим из коллекции на игровом сервере (он нам после отдаст результат что бы и в нем удалилось)
							if(!empty($entity['events']))
							{
								$object->events->__construct($object, $entity['events']);
								unset($entity['events']);
							}										
							
							if(!empty($entity['components']))
							{
								$object->components->__construct($object, $entity['components']);
								unset($entity['components']);
							}
							
							if($entity)
							{
								foreach(array_keys($object::columns()) as $column)
								{
									if(array_key_exists($column, $entity))
									{
										$object->__set($column, $entity[$column]);
										unset($entity[$column]);
									}	
								}
							}
		
							if($entity)
								throw new Error('неизвестные поля в пакете с соседней локации для изменения сущности '.print_r($entity, true));
						}
						
						if($map_id!=MAP_ID)			
							$object->setPermamentUpdate(false);
					}
				}
			}
		}
	}
	
	// todo можно реализовать полуение от игрока кода eval с выполнением его , а не только отправку событий
	private static function player_command(string $key, array $data)
	{		
		// может не быть ткт игрок мог уйти на другую лкоацию
		if(parent::isset($key))
		{
			$player = parent::get($key);
			try
			{
				$group = $data['group'];
				$action = $data['action']??'index';
				
				unset($data['group']);
				unset($data['action']);
		
				if(!$group)
					throw new Error('нельзя добавить событие команды игрока с пустым полем group_name');		
				
				if(!$action)
					throw new Error('нельзя добавить событие команды игрока с пустым полем action');
									
				// команду можно принять только если сейчас не выполняется ничего или текущая команда сгенерирована сервером (что бы мы могли ее сбросить)
				if(empty($player->events->get($group)->action) || !$player->events->get($group)->from_client)
				{
					$access = true;
				}
				else
					$access = false;		
						
				$player->last_active = microtime(true);
				$remain = $player->events->get($group)->remainTime();
				
				// пинг пришел от клиента
				if(array_key_exists('ping', $data))
				{
					$player->ping = $data['ping'];
					unset($data['ping']);
				} 			
				$command = $group.'/'.$action;
				
				if(!$access)
				{
					if($remain > 0 && APP_DEBUG)
						$player->warning('Команда '.$command.' пришла слишком быстро: '.$remain.' до таймаута'.(!empty($player->events->get($group)->action)?' и уж имеется в очереди новый action':''));
				}
				else
				{		
					// если команда пришла до того как команда завершилась будем считать интерполяцией (те если уже все давно готово то интерполяция плохая и будем считать что команда НЕ непрерывная)
					if(abs($remain) < $player->events->get($group)->timeout())
					{
						if($remain<0)
						{ 
							if(APP_DEBUG)
								$player->warning('Плохая интерполяция события непрерывных событий '.$command.': событие уже готово для новых комманд '.abs($remain).' сек.');
						}
						else
						{
							if(APP_DEBUG)
								Perfomance::set('Интерполяция   | своевременность поступления комманд к готовности события '.$group.' %', round($remain/$player->events->get($group)->timeout()*100));		
						}
					}
					
					if(APP_DEBUG)
						$player->log('добавляем событие '.$command.' игрока по команде с клиента');			
					
					// только публичные event можно вызвать из api (но есть еще protected и приватные которые накидваются в коде)
					// если мы не хотим ломан прицип ооп надо продумать другой спосок указания защитных методов которые нельзя вызвать напрямую
					if(!empty(Events::list()[$group]['methods'][$action]['isPublic']))
						$player->events->add($group, $action, $data, from_client: true);
					else
						throw new Error('не найдена публичная команда '.$command);
				}	
			}				
			catch(Exception $ex)
			{
				// не рушим сервер просто отключим игрока
				$player->warning('Ошибка команды игрока '.serialize($data).', сервер продолжает работу '.$ex);
				$player->send(['error'=>$ex->getMessage()]);
				$player->remove();						
			}
		}
		else
			PHP::warning($key." отсутвует на игровом сервере для приема комманд ".print_r($data, true));
	}	
	
	private static function location_clear(int $map_id):void
	{
		if(APP_DEBUG)
			PHP::log('очистка сущностей локации '.$map_id.' по команде с websocket');
		
		if($map_id == MAP_ID)
			throw new Error('Очищать от существ можно только смежные локации, данны которых копируются в центральном мире');
		
		foreach(parent::all() as $key=>$entity)
		{
			if($entity->map_id == $map_id)
			{
				$entity->setPermamentUpdate(true);
				$entity->remove();
				$entity->setPermamentUpdate(false);
			}
		}
	}
}