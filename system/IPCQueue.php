<?php
final class IPCQueue
{	
	private readonly SysvMessageQueue $_queue;		// группа сообщения  можно филтровать для получения исключительно по ней сообщений (медленнаа на 30% чем просто сообщения)
	public readonly int $max_message_size;

	function __construct(public int $id, int $max_message_size)
	{	
		// тк мы добавляем нулевой байт \0 длинна максимальная должна быть на 1 меньше
		$this->max_message_size = $max_message_size-1;
		
		if(!msg_queue_exists($this->id))
			throw new Error("не существует очередь ".$this->id);	
		
		if(!$this->_queue = msg_get_queue($this->id, 0770))
			throw new Error("не удалось создать очередь сообщений memory для ".$this->id);	
	}
	
	public function send(string|int|float|bool|array $message):void
	{
		if(is_array($message))
			$message = serialize($message);
		
		$parts = [];
		if(($size = strlen($message)) && $size>$this->max_message_size)
		{
			$count_parts = ceil($size/$this->max_message_size);
			
			if(APP_DEBUG)
				PHP::log('Cлишком длинное сообщение для отправки из песочницы в общую память ('.$size.' из '.$this->max_message_size.' байт), разбиваем на '.$count_parts);		
			
			for($i=0; $i<$count_parts; $i++)
			{
				$parts[] = substr($message, $i*$this->max_message_size, $this->max_message_size);
			}
		}
		else
			$parts[] = $message;
		
		foreach($parts as $num=>$part)
		{		
			$error_code = 0;	
			
			if($num==count($parts)-1)
				$part .= chr(7);
			
			if(!msg_send($this->_queue, 1, $part, false, true, $error_code))
				throw new Error('Ошибка ('.$error_code.') при отправке '.($num+1).'й части сообщения ('.strlen($part).'/'.$size.', '.count($parts).' частей) в общую память: '.$this->error($error_code));
					
		}
	}		
	
	// никаких таймаутов - слишком много жрется процессор
	public function recive():mixed
	{
		$message = null;
		$start = hrtime(true);
		
		while(true)
		{
			$part = null;
			$error_code = 0;
			$message_type = 0;
			
			if(!$recive = msg_receive($this->_queue, 1, $message_type, $this->max_message_size+1, $part, false, 0, $error_code))
				throw new Error('Ошибка ('.$error_code.') при получении сообщений общей памяти в песочнице PHP : '.$this->error($error_code));
			
			if($message === null)
				$message = $part;
			else
				$message .= $part;
			
			if(substr_compare($part, chr(7), -1, 1)===0) break;
		}
		
		$message = trim($message);
		$message = unserialize($message);

		return $message;
	} 

	private function error(int $code):?string
	{	
		switch($code)
		{
			case MSG_ENOMSG:			// ENOMSG, нет сообщений 
				return null;
			break;
			
			case 11:					//EAGAIN
				$message = 'Сообщение не помещается в очередь - попробуйте отправить позже';
			break;			

			case 13:					//EACCES
				$message = 'Вызывающий процесс не имеет прав записи в очередь';
			break;						
			
			case 14:					//EFAULT
				$message = 'Память с адресом, указанным msgp, недоступна';
			break;			

			case 43:					//EIDRM
				$message = 'Очередь сообщений была удалена из системы';
			break;			

			case 4:						//EINTR
				$message = 'Процесс ждал свободного места в очереди и получил сигнал, который он должен обработать';
			break;			

			case 22:					//EINVAL
				$message = 'не корректные аргументы или отсутвует очередь сообщений';
			break;					
			
			case 12:					//ENOMEM
				$message = 'В системе недостаточно памяти для копирования содержимого буфера msgbuf.';
			break;			

			case 7:					   //E2BIG
				$message = 'Длина текста получаемого сообщения больше, чем msgsz, а в поле msgflg не установлен флаг MSG_NOERROR';
			break;		

			case 2:					   //ENOENT
				$message = 'не найден фаил или директория';
			break;
			
			default:
				$message = 'необработанная ошибка';
			break;	
		}
		
		return $message;
	}
	
	private function __clone() 
	{
		
	}
	
	public function __debugInfo() 
	{
		return [];
	}
		
	public static function __set_state($properties):object
    {
        return new StdClass;
    }
	
	public function __serialize(): array
	{
		return [];
	}
}
