<?php
final class PHP extends RemoteCommand
{
	private static array $_closures;							// здесь содержаться замыкания кода и их персональные семафоры (что бы разные выполнения кода могли работать паралельно пока одно ждет результата)
	private static int $_pid;					   				// теущий pid процесса				   					
	private static ?int $_start_time;							// время прошедшее с момента как главный процесс передал задачу и мы прочитали ее даныне (по сути стар очередного выполнения кода)

	private static int $_frame = 0;								// текущий фрейм.
	
	private static IPCQueue $_local_queue;									
	private static IPCQueue $_remote_queue;									
			
	private function __construct(){}
	
	public static function listen()
	{	
		if(isset(static::$_local_queue))
			throw new Error('Инициализация системы произведена');

		if(empty($_ENV['max_message_size'])) 
			throw new Error("Не указан максимальный размер очереди сообщений песочницы".print_r($_ENV, true));			
				
		if(empty($_ENV['local_queue']))
			throw new Error("Не указан индентификатор лкоального потока песочницы ".print_r($_ENV, true));			
		
		if(!static::$_local_queue = new IPCQueue($_ENV['local_queue'], $_ENV['max_message_size'])) 
			throw new Error("Ошибка создания опамяти обмена данными песочницы PHP");		
		
		if(APP_DEBUG)
			static::log('отправляем команду о завершении инициализации...');
			
		// отправим в очередь первую команду как сигнал что процесс поднят
		static::send('finish');

		$_SERVER = $_ENV = null;
	
		// этот блок кода ждет пока не появится новый код для компляции или исполнения, после чего снова блокируется
		while(true) 
		{
			//if(APP_DEBUG)
			//	static::log('Ждем новых сообщений от игрового сервера...');
		
			// эта строка блокирует скрипт до получения сообщений так что использования процессора не идет
			if($data = static::$_local_queue->recive())
			{
				if(static::$_frame==FPS)
					static::$_frame = 1;
				else
					static::$_frame++;
				
				static::$_start_time = hrtime(true);
					
					switch($data[0])
					{
						case 'tick':
							parent::refresh();
		
							# запустим выполнение команд отправленных игроками (кроме тех что удалены окончательно) или смежными локациями (они могут вешать событий существам с текущей)
							if(!empty($data[1]))
								parent::run($data[1]);

							Frame::tick();

							# сбор всех изменений существ за этот кадр и возврат из в виде одного пакета обратно в Игровой сервер (после он отправит их в WebSocket
							$return = parent::collected();
						break;
						case 'perfomance':
							if($data[1][0]<$data[1][1]) 
								$return = [str_pad($data[1][2], $data[1][1]-$data[1][0], "a"), static::$_start_time]; 
							else 
								$return = [substr($data[1][2], $data[1][1]), static::$_start_time];
						break;
						default:
							throw new Error('После разблокировки общего канала песочницы PHP память не содержит запроса основного процесса создавшего песочницу (для компиляции или выполнения кода) '.print_r($data, true));
						break;			
					}
					
				# сбор всех изменений существ за этот кадр и возврат из в виде одного пакета обратно в Игровой сервер (после он отправит их в WebSocket
				static::send('finish', $return);
				static::$_start_time = null;
			}
			else
				throw new Error('Попытка чтения из очереди общей памяти комманд от игрового сервера не удалась (таймаут)');
		}
	}

	// нам не нужен назад finish (нет функций выполняющихся на сервере которые мы хотим поулчить какой то возврат)
	private static function send(string $name, array $arguments = null):void
	{	
		if(empty(static::$_remote_queue))
		{
			if(empty($_ENV['max_message_size'])) 
				throw new Error("Не указан максимальный размер очереди сообщений песочницы ".print_r($_ENV, true));			
			
			if(empty($_ENV['remote_queue']))
				throw new Error("Не указан индентификатор удаленного потока песочницы ".print_r($_ENV, true));
			
			if(!static::$_remote_queue = new IPCQueue($_ENV['remote_queue'], $_ENV['max_message_size'])) 
				throw new Error("Ошибка создания опамяти обмена данными песочницы gameServer");	
			
			unset($_ENV['remote_queue']);
				
			if(APP_DEBUG)
				static::log('инициализация песочницы PHP завершена');	
		}	
		
		static::$_remote_queue->send([$name, $arguments]);
	}	
		
	public static function perfomance(array $perfomances):void
	{
		static::__callStatic('perfomance', [$perfomances]);	
	}		
	
	public static function save(EntityTypeEnum $type, string $key, ?int $new_map_id=null):void
	{
		static::__callStatic('save', [$type->value, $key, $new_map_id]);	
	}	
	
	// сбрасывание в буфер данных будет включаться в общий лог
	// todo проверить на безопасность
	public static function log($comment):void
	{
		echo static::prepare_log($comment).PHP_EOL;			
	}

	// выведет в журнал предупреждений (что же касается ошибок используйте throw конструкцию)
	public static function warning($comment):void
	{
		static::__callStatic('warning', [static::prepare_log($comment)]);	
	}	
	
	// запросить из процесса создавшего песочницу данных (пока это лишь функция warning и perfomance и save) 		
	public static function __callStatic(string $name, array $arguments):void
	{
		if(!DEFINED('FUNCTIONS'))
			throw new Error('Не указана константа FUNCTIONS со списком удаленных функций что может вызывать пространство песочницы, что инициализируется при ее создании');
			
		if(!in_array($name, FUNCTIONS))
			throw new Error('отсутвует функция игрового сервера '.$name.' для запроса ее результата из PHP песочницы');
		
		static::send($name, $arguments);	
	}

	private static function prepare_log($comment):string
	{
		$datetime = substr_replace((new DateTime())->format("Y-m-d H:i:s:u"), ':', 23, 0);	
		return $datetime.' '.
				'| '.str_pad(static::$_frame, 5, ' ').
				'| sandbox php '.
				'| '.str_pad((isset(static::$_start_time)?round((hrtime(true) - static::$_start_time)/1e+6, 3):'-'), 6, ' ').' '.
				'| '.(!is_scalar($comment) && !($comment instanceof Stringable)?PHP_EOL.print_r($comment,true):ltrim($comment));
		
	}
}
