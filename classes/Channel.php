<?php

// класс для формирования пакета необходимого для websocket сервера (именно для него, не для game server)
abstract class Channel
{
	private static array $_queue = array();
		
	public static function player_remove(string $key, ?string $error = null):void
	{
		static::queue('player_remove', ['key'=>$key, 'error'=>$error]);
	}
	
	public static function player_send(string $key, array $data):void
	{
		static::queue('player_send', ['key'=>$key, 'data'=>$data]);
	}
	
	// создание сущности на лругой лкоации - через код API или при переходе
	public static function create_remote_entity(EntityAbstract $entity, ?int $map_id = null)
	{
		if($entity->type == EntityTypeEnum::Players->value) 
			throw new Error('нельзя создавать игроков по команде на другу локацию');		
		
		// в метод может быть подана другая карта существа, например при переходе
		if(empty($map_id))
			$map_id = $entity->map_id;	
		
		if($map_id == MAP_ID) 
			throw new Error('для команды на создание новй сущности соседнй локации нельзя указвать в параметрах существа центральную лкоацию');
			
		$data = array();
		$data = $entity->toArray();

		if($privates_components = $entity->components->privates())
			$data['components'] = array_replace_recursive($data['components']??[], $privates_components);
		
		static::queue('create_remote_entity', ['map_id'=>$map_id, 'key'=>$entity->key, 'type'=>$entity->type, 'entity'=>$data]);
	}		
	
	// рассылка всем изменений
	public static function send_changes(array $publish):void
	{
		if($publish)
		{
			if(APP_DEBUG)
				PHP::log('отправим с песочницы в WebSocket пакет изменений существ для рассылки '.print_r($publish, true));
				
			static::queue('send_changes', ['publish'=>$publish]);
		}
	}
	
	// шлем пакеты в websocket.
	private static function queue(string $method, array $params = null)
	{	
		if(APP_DEBUG)
			PHP::log('Добавление в очередь на отправку в WebSocket команды '.$method);
		
		if($params)
			static::$_queue[] = [$method, $params];
		else
			static::$_queue[] = [$method];
	}	
	
	// шлем пакеты в websocket.
	// используется общая память тк она больше по размеру отправляемого и хранимого и и быстрее
	public static function collected():array
	{	
		$messages = static::$_queue;
		if(static::$_queue)
		{
			if(APP_DEBUG)
				PHP::log('Возврат в WebSocket '.count(static::$_queue).' команд очереди '.print_r(static::$_queue, true));
			
			static::$_queue = array();
		}
		return $messages;
	}
}