<?php
// Это кадр игрового сервера в котором он обрабатывает все события сущностей
// события добавляютяс через админ панель - это прицип проекта. что бы не колхозить что то тут о чем еще нужно будет будущим программистам догадаться, так что не рекомендуется что то менять

abstract class Frame 
{
	private static array $_events_sandboxes = array();	// массив песочниц событий луа и js 	

	public static function tick():void
	{	
		// массив живущих сущностей (тут и с текущей и с соседней локации)
		$live_entitys = array();
		
		// TOOD использовать приоритетную очередь добавляя существ с life radius наверх что бы не делать лишний тот цикл в в цикле ниже сразу собирать первых в live_entitys
		foreach(World::all() as $entity_key=>$object)
		{
			if($object->lifeRadius)
				$live_entitys[$entity_key] = $object;
		}
		
		if($live_entitys) 
		{
			// массив сущностей с текущей локации для обработки 
			$entitys = array();
			
			// помимо существ с lifeRadius (которые по любому должны быть обработаны) будут обработы и те , что в зоне видимости их (вспоминается коты Шреденгера)
			foreach(World::all() as $entity_key=>$object)
			{
				// существ с другой карты обрабатывает другая карта
				if($object->map_id == MAP_ID)
				{
					// если существует - то точно его жизнь надо обновлять, если нет - то смотрим радиус тех у кого есть, может мы попадаем под их действие и тоже себя обновим
					if(!$object->lifeRadius)
					{					
						foreach($live_entitys as $live)
						{
							if(abs($live->position->x - $object->position->x) >= $live->lifeRadius || abs($live->position->y - $object->position->y) >= $live->lifeRadius) 
								continue; 
							else
							{
								// сгруппирруем в массив с ключами сущностями на обработку
								// Внимание ! не собирать тут события тк ниже при обработке может что то доабвится или удалится
								$entitys[$entity_key] = $object;
				
								break;
							}					
						}
					}
					else 
						$entitys[$entity_key] = $object;
				}
			}
	
			// теперь пройдемся по группе событий в порядке сортировки
			// Внимание!  Именно так , потому что в процессе могут появляться другие события или какие то становиться обнуленными или измененными и таймаут через код быть сброшен
			foreach(Events::list() as $group_name=>$group)
			{		
				// + теперь нам нужна группировка существ по событиям конкретным что бы так же массово обновить
				foreach($entitys as $entity_key=>$entity)
				{

					// обязательно проверка на isset а то создадим событие 
					// если у существа не пришло время конкретно этого события - пропускаем существо
					if(!$entity->events->isset($group_name) || (!$event = $entity->events->get($group_name)) || (!$action = $event->action) || $event->remainTime>0)
						continue;		

					// обязательно сохранить тк $event  после finish обнулиться  
					$data = $event->data;
					$from_client = $event->from_client;
					
					// событие либо будет удалено либо поставлено по новой если оно постоянное. установим сразу тут что бы не держать блокировку команд
					$event->finish();

					// tido разрешить менять значения компонентов событиям только конкретной сущности (тк существам с соседних локаций в приципе нельзя так менять их, так и пусть же всем нельзя будет)
					// внутри могут быть события по работе с бд и предусмотрим что они могут работать асинхронно
					if(!$event_sandbox = &Events::list()[$group_name]['methods'][$action]['closure'])
						throw new Error('для группы событий '.$group_name.', события '.$action.' не указан ее код в админ панели');
					
					$start = hrtime(true);
					
					try
					{			
						$recover = Block::current();
						// можно менять компоненты иди свойств только текущего существа
						Block::$object_change = Block::$components	= $entity->key;																															
						$event_sandbox($entity, $data, $from_client);												
					}
					catch(Exception $ex)
					{
						// исключения типа Exception у игроков с текущей карты не рушим сервер, а отключам игроков
						if($entity->map_id == MAP_ID && $entity->type == EntityTypeEnum::Players->value)
						{
							$entity->warning('Ошибка выполнения события '.$group_name.'/'.$action.', сервер продолжает работу '.$ex);
							$entity->send(['error'=>$ex->getMessage()]);
							World::remove($entity->key);						
						}
						else
							trigger_error($ex, E_USER_ERROR);
					}
					finally
					{
						Block::recover($recover);		
					}	
					
					// посчитаем среднее время чистой работы события в мс.
					if(PERFOMANCE_TIMEOUT)
						Perfomance::set('Sandbox      | событие '.$group_name.'/'.$action, (hrtime(true) - $start)/1e+6);					
				}				
			}					
		}			
	}	
}