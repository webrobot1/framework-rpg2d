<?php

class Players extends EntityAbstract
{	
	public float $last_active;							// microtime с последней активностью	
	private array $_last_save_data = array();			// последние сохраненные даныне что бы сравнивать и не сохранять то что не изменилось

    public function __construct(public readonly string $login, public string $ip, public ?float $ping = 0, ...$arg)
    {	
		parent::__construct(...$arg);
					
		// пометим текущие данные как последние сохраненные
		if(APP_DEBUG)
			$this->log('Поместим текущие данные игрока в массив с которым арвнивается при сохранении данные для сохранения только новых');
		
		if($this->map_id == MAP_ID) 
		{
			$this->_last_save_data = array_intersect_key($arg, static::columns());
			$this->_last_save_data['ip'] = $ip;
			$this->_last_save_data['login'] = $login;
		}

		$this->last_active = microtime(true);
    } 
   	
	// какие колонки выводить в методе toArray и которые можно изменить из вне класса
	public static function columns():array
	{
		$columns = parent::columns();
		$columns['ip'] = true;
		$columns['login'] = true;
		
		return $columns;	
	}
	
	protected static function getType():string
	{
		return EntityTypeEnum::Players->value;
	}
	
	public function close(string $text = null)
	{	
		// todo сделать формирование пакета 
		if($text)
			$this->send($text);
		
		parent::remove();	
	}
	
	public function send(array $data)
	{
		if (PHP_SAPI !== 'cli') {
            exit("Only run in command line mode");
        }	

		if($this->map_id != MAP_ID) 
			throw new Error('Отправка сообщения игрокам в другую локацию не была реализована');

		if(APP_DEBUG)
			$this->log('отправка персонального пакета игроку '.print_r($data, true));

		Channel::player_send($this->key, $data);				
	}
	
	// todo в паралельном потоке делать это и как event
	public function save(?int $new_map = null)
	{
		if($this->map_id != MAP_ID) 
			throw new Error('Сохранение игроков находящихся на другой карте запрещено');
		
		if(APP_DEBUG)
			$this->log('сохранение игрока');
			
		// перед запросом данных на сохранение проверим не ушли ли мы с локации и не нужно ли нам координаты подробвнять (может чуть за пределы вышли, но при этом не достаточно что бы считался как переход)
		// мы это же делаем при рассылки изменений но вот тут надо тоже тк можем в текущем кадре перейти на локацию или выйти чуть за пределы		
		if(!$new_map)
		{
			if(($new_map = $this->checkLeave()) && $new_map == MAP_ID)
				$new_map = null;
		}
		
		$data = $this->toArray();	
		$data['components'] = array_merge($data['components']??[], $this->components->privates());	  
		
		// эти поля нельзя менять
		unset($data['id']);

		unset($data['events'][SystemActionEnum::EVENT_DISCONNECT]);
		unset($data['events'][SystemActionEnum::EVENT_SAVE]);
		
		if($new_map)
		{
			if($new_map == MAP_ID) 
				throw new Error('Нельзя сохранить существо с пометкой о новой карте когда она равна текущей');
		
			$data['map_id'] = $new_map;
			if(APP_DEBUG)
				$this->log('заменяем игроку карту при сохранении');
		}	

		$not_changes = array();
		if(($data != $this->_last_save_data) && ($this->_last_save_data = Data::compare_recursive($this->_last_save_data, $data, $not_changes)) && $data)
		{	
			// пинг игрока нам дает клиент	
			$data['ping'] = $this->ping;
			PHP::save(EntityTypeEnum::from($this->type), $this->id, $data);	
		}

		if(APP_DEBUG && $not_changes)
			$this->log('После уникализации данных на сохранение '.(!$data?'нечего сохранять':'удален пакет'.print_r($not_changes, true)));		
	}
}