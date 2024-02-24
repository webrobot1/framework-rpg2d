<?php
// функция блокирует изменения данных в коллекциях в зависимости от того какой пользоватлеьский код выполняется (что бы из него не вызвать зацикленность) - аналог Семефора
// Внимание ! во избежания состояния гонки НЕ менять эти араметры ТОЛЬКО в коде выполняющимся в рамках обновления мира Time process (те НЕ во время загрузки игрока,приема сообщений от игрока и серверов, рассылки)
// внимание ! в конце кадра все должно быть разрешено
abstract class Block
{	
	public static bool $timeout      			= false;	// если true нельзя запрашивать расчет таймаутов
	public static bool $events      			= false;	// если true нельзя добавлять новые события (то может быть зациклевание кода)
	public static bool $objects     			= false;	// если true нельзя добавлять новых существ 
	public static bool $positions     			= false;	// если true нельзя менять позиции
	public static string|bool $components   	= false;	// если ид сущность нельзя менять компоненты кроме этого существа
	public static string|bool $object_change 	= false;	// если true нельзя менять свойства сущности

	// сбрсоит значения блокировок на default , сохранит текущие для восстановления если понадобится
	// параметр Recover ипользуется только в EventGroup методе timeout и при добавлении существ
	public static function current():Recover
	{
		return new Recover(static::$timeout, static::$events, static::$objects, static::$positions, static::$components, static::$object_change);
	}		
	
	// восстанвоить выбранные свойства
	public static function recover(Recover $recover):void
	{
		static::$timeout 		= $recover->timeout;
		static::$events 		= $recover->events;
		static::$objects 		= $recover->objects;
		static::$positions 		= $recover->positions;
		static::$components		= $recover->components;
		static::$object_change	= $recover->object_change;
	}	
}

class Recover
{
	function __construct
	(
		public readonly bool $timeout,	
		public readonly bool $events,	
		public readonly bool $objects,	
		public readonly bool $positions,	
		public readonly string|bool $components,
		public readonly string|bool $object_change
	){}	
}
