<?php

// структура пакета WebSocket сервера  
final class RemotePacket
{
	function __construct(public string $method, public array $params = array())
	{
		
		
	}
}