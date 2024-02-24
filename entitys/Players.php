<?php

class Players extends EntityAbstract
{	
	public float $last_active;							// microtime с последней активностью	
	private array $_last_save_data = array();			// последние сохраненные даныне что бы сравнивать и не сохранять то что не изменилось
	
	private static Closure $_compare;					// функция сравнения массивов при сохранении
	
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
		
		if(empty(static::$_compare))
			static::$_compare = function (array $keys, $new_value, $old_value)
			{
				// компонент типа массив  и или событие  - сохранятеся целиком (тк в компоненте могло что то быть удалено, а события для сохранения через mass insert нам нужны все поля, даже те что не менялись тк у игрового сервера нет копий что бы дополнять)
				// возможно с swoole асинхронныи корутинами я смогу вставлять через multi query insert on duplicate update асинхронно без mass insert только изменившиеся поля
				if(!empty($keys[1]) && ($keys[0] == 'components' || $keys[0] == 'events'))
				{	
					return true;
				}
			};
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
		if(($data != $this->_last_save_data) && ($this->_last_save_data = static::array_replace_recursive($this->_last_save_data, $data, $not_changes)) && $data)
		{	
			// пинг игрока нам дает клиент	
			$data['ping'] = $this->ping;
			PHP::save($this->type, $this->id, $data);	
		}

		if(APP_DEBUG && $not_changes)
			$this->log('После уникализации данных на сохранение '.(!$data?'нечего сохранять':'удален пакет'.print_r($not_changes, true)));		
	}

	// дабы не сохранять в бд не изменившиеся значения
	// $not_changes  массив данных которые не изменились и были удалены из $new_data
	// $callback функция указвает нужно ли массивы с вложенностью аргумента $compare_key если он изменились перезаписать новым значением целиком (возврат true) или менять только отдельные эллементы изменившиеся
	private static function array_replace_recursive(array $old_data, array &$new_data, array &$not_changes = array(), array $compare_key = array()):array
	{
		$aReturn = array();
		foreach ($new_data as $mKey => &$new_value) 
		{
			$new_compare_key = $compare_key;
			$new_compare_key[] = $mKey;

			// если есть старый ключ или callback функция вернула false
			if (array_key_exists($mKey, $old_data) && $old_data[$mKey] != $new_value && !empty($old_data[$mKey])) 			
			{
				if (is_array($new_value) && $new_value && !call_user_func(static::$_compare, $new_compare_key, $new_value, $old_data[$mKey])) 																	
				{
					$not_changes[$mKey] = array();
					$old_data[$mKey] = static::array_replace_recursive($old_data[$mKey], $new_value, $not_changes[$mKey], $new_compare_key);
					
					if(empty($not_changes[$mKey]))
						unset($not_changes[$mKey]);
					elseif(empty($new_value))
						unset($new_data[$mKey]);
				} 
				else 
				{
					$old_data[$mKey] = $new_value;
				}
			} 
			// иначе новое значение запишется без првоерки
			else
			{ 
				// если даныне теже самые удалим из массива . это может быть полезно что в массиве новых данных остались именно новые
				if(array_key_exists($mKey, $old_data) && $old_data[$mKey] == $new_value)
				{
					$not_changes[$mKey] = $new_value;
					unset($new_data[$mKey]);
				}
				else
					$old_data[$mKey] = $new_value;	
			}
		}
		
		return $old_data;
	} 
}