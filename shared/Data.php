<?php
// класс для работы с данными оптарвляемыми между sandbox и websocket и игровыми клиентами
final class Data 
{	
	private static $_components_max_compare_level;				//  максимальнй уровень вложенности json компонентов если указан то после него массивы не проверются если не совпадают а новые данные переписывают текущии (в том числе пустым значением)
  
	private function __construct(){}
	
	public static function init(array $components)
	{
		if(isset(static::$_components_max_compare_level))
			throw new Error('Класс Data уже был проинициалазирован');
		
		static::$_components_max_compare_level = array_filter($components);
	}
	
	# Метод не только рекурсивно дообогатит один массив другим , но и вернет разницу элементов которые не изменились
	# так же метод содержит ряд проверок и манипулирует данными (например удалит данные события data если action стал пустым)
	
	
	// $current_data  текущий массив изменений с которым сравниваем new_data и который дообогащаем им и возвращаем из метода
	// $new_data - данные которые мы хотим дообогатить $current_data. Будут удалены данные которые есть и не изменились в $current_data  
	// $not_changes содердит те данные что были в $new_data  но имеются и в $current_data мы не заменили
	// на 30% быстрее array_replace_recursive
    public static function compare_recursive(array $current_data, array &$new_data, array &$not_changes = array(), array &$finish_events = array()):array
    {
		if(!$new_data)
			throw new Error('Постое значение массива сновых значений недопустимо');
		
		// события нужно переирать всегла если они есть что бы собрать массив $finish_events
		if(!$current_data && empty($new_data['events']))
		{
			$current_data = $new_data;
		}
		// может быть такое что был events->action='' и пришел такой же, но надо проверить что бы внести в $finish_events
		elseif($current_data == $new_data && empty($new_data['events']))
		{
			$not_changes = $new_data;
			$new_data = [];
		}
		else
		{	
			// эти два поля что бы собрать какие значения не поменялись
			$not_changes = $new_data;

			// строковое представление уровня вложенности массива
			$compare_key = array();

			$level = 0;
			$new_arrays = [$level=>&$new_data];
			$current_arrays = [$level=>&$current_data];
			$changes_arrays = [$level=>&$not_changes];
			
			while(true)
			{
				$key = key($new_arrays[$level]);
				
				// достигнут конец массива
				if($key=== null)
				{
					if($level == 0) 
						break;
					else
					{
						$prev_key = array_pop($compare_key);
						
						// если при обработке значений вложенного массива все данные оказались новыми то удалим его ключ 
						if(empty($new_arrays[$level]))
							unset($new_arrays[$level-1][$prev_key]);
						elseif(empty($changes_arrays[$level]))
							unset($changes_arrays[$level-1][$prev_key]);
							
						$level--;	
						continue;
					}
				}
								
				$content = current($new_arrays[$level]);
				
				// для websocket сервера который делит эту библиотеку с Sandbox
				// ниже не ставить тк независимо от того был ли такой пакет в current_arrays или нет remain надо изменить
				if($compare_key && $compare_key[0]=='events' && $level==1 && isset($content['action']) && empty($content['action']))
				{
					// если пришла команда об обнулении события то старые данные если таковые есть надо обнулить
					// завершенные события существа (для websocket сервера нужно что бы убрать из своего кеша)
					$finish_events[] = $key;
					
					unset($current_arrays[$level][$key]['from_client']);	
					unset($current_arrays[$level][$key]['data']);
					
					if(isset($content['data']))
						throw new Error('Нельзя пометить событие как завершенное и указать для него новые данные');
					
					if(isset($content['from_client']))
						throw new Error('Нельзя пометить событие как завершенное и указать для него флаг об запущенности с клиентской части или серверной');
				}
				
				// если и старые даные не пустые
				// ну или это массив events уровня До обоаботки кокнретного события
				// action в сущности и может совпадать (он постоянно переписывается)
				// action в events и может совпадать 
				if(!isset($current_arrays[$level][$key]) || $current_arrays[$level][$key] != $content || (!$level && ($key=='action' || $key=='events')) || ($compare_key && $compare_key[0]=='events' && ($level<2 || ($level==2 && $key=='action'))))
				{	
					// и мы не в data поле событий events 
					// и не выше значения поля кпомонента в массиве static::$_components_max_compare_level
					// иначе массив переписывается целиком
					if(
						is_array($content)
							&&
						$content
							&&	
						(
							!$compare_key
								||
							(		
								($compare_key[0]!='events' || $level!=2 || $key!='data')		// поле data которое придет если оно не равно полностью ерезапишет что было
									&&
								($compare_key[0]!='components' || (!empty($current_arrays[$level][$key]) && ((!$max_level = (static::$_components_max_compare_level[($compare_key[1]??$key)]??null)) || $max_level > $level)))
							)
						)
					)
					{
						if($compare_key && $compare_key[0]=='components')
							echo '!!'.$level.'-'.implode($compare_key).'-'.$max_level.PHP_EOL;	
					
						$new_arrays[($level+1)] = &$new_arrays[$level][$key];
						$current_arrays[($level+1)] = &$current_arrays[$level][$key];
						$changes_arrays[($level+1)] = &$changes_arrays[$level][$key];

						// что бы при возврате назад на этот уровень начать уже со следующего элемента
						next($new_arrays[$level]);
						
						$compare_key[] = $key;
						$level++;
						continue;
					}	

					
					// тут сравниваем значение строк и цифр
					// + если это полностью новый массив которого нет в текущих
					$current_arrays[$level][$key] = $content;
					
					// тк поле поменялось удалим из массива
					unset($changes_arrays[$level][$key]);
					
					if(empty($changes_arrays[$level]))
						$changes_arrays[$level] = null;
					
					next($new_arrays[$level]);						
				}
				else
				{
					// нове значение содержит одинаковые данные удалим его из массива $new_data
					unset($new_arrays[$level][$key]);
					
					// тут next не делаем тк нас автоматом перелючает на следующий элемент при unset
				}
			}
		}
		
		return $current_data;
	}
}
