<?php
// Осторожно! родительский класс сущностей. все методы создания сущностей и вспомогательные тут. здесь стараться ничего не менять
// все поля приватные. Ничего не должно меняться внутри этого родительского класса после __construct без попадания в changes
class Players extends EntityAbstract
{
	public float $last_active;								// microtime с последней отправленной от клиента командой	

	// объявим общие обязательные параметры
	public function __construct
	(
		public readonly string $login,							// только для игроков
		public string $ip,
		public ?float $ping = 0,
		...$arg											// все остальное что не нужно или новые поля что забыли задейстсвать (что бы не упал сервер)
	)
	{
		parent::__construct(...$arg);	
		$this->last_active = microtime(true);
	}
	
	// какие колонки выводить в методе toArray и которые можно изменить из вне класса
	public final static function columns():array
	{
		$columns = parent::columns();
		$columns['ip'] = true;
		$columns['login'] = true;
		
		return $columns;	
	}
	
	protected final static function getType():EntityTypeEnum
    {
        return EntityTypeEnum::Players;
    }	
	
	public final function close(string $text = null)
	{	
		// todo сделать формирование пакета 
		if($text)
			$this->send($text);
		
		parent::remove();	
	}
	
	public final function send(array $data)
	{
		if($this->map_id != MAP_ID) 
			throw new Error('Отправка сообщения игрокам в другую локацию не была реализована');

		if(APP_DEBUG)
			$this->log('отправка персонального пакета игроку '.print_r($data, true));

		Channel::player_send($this->key, $data);				
	}
}