<?php
final class SystemActionEnum 
{
	private function __construct(){}
	
    const ACTION_LOAD = 'load';				// ответ из события о пересоединении
    const ACTION_REMOVE = 'remove';			// ответ из события о удалении с карты
    const ACTION_RECONNECT = 'reconnect';	// ответ из события о загрузке мира
	
	const EVENT_SAVE = 'system/save';				// системная команда сохранение
    const EVENT_DISCONNECT = 'system/disconnect';	// системная команда дисконект по таймауту игрока
}