<?php
class Position extends IPosition
{	
	private static Closure $_position_trigger;
			
	// добавить код который сработает на сущность при смене ее координат - этот метод запускается при старте game Server передавая в песочницу этот код который берет из базы в админ панели записанный
	public static function init(array $info)
	{
		if(APP_DEBUG)
			PHP::log('Инициализация кода смены позиций');
			
		if(!empty($info['code']))
		{
			try
			{
				static::$_position_trigger = eval('return static function(EntityAbstract $object, array $old):void{
					'.$info['code'].'  
				};');	
			}
			catch(Throwable $ex)
			{
				throw new Error('code(position): Ошибка компиляции кода изменения позиции '.$ex);
			}				
		}
	}	
	
	// округляет то единицы ( актуально в тайловых картах )
	public function round(): Position
    {
		$position = $this->toArray();

		// если объект с другой карты его фактические координаты могут кругляться на его родной карты иначе чем тут когда число находиться посредине
		if(!empty($this->object) && $this->object->map_id != MAP_ID)
		{
			// на родной лкоации он всегда более нуля вклчюительно
			$x = $this->object->x;
			
			// если объект слева в положительных (родных координатах) а тут в отрицательных (справа от его локации значит наша смежная стоит локация в бесшовном мире где мы обрабатываем его копию)
			if($position['x']<0)
			{
				if($perseption = ($x  - (int)$x))
				{
					// если далее пол клекти то на его родной локации (в положительных координатах) он будет ПРАВЕЕ (координата x  долдны быть ближе к нулю округляться, те в большую сторону в рамках координат но в меньшую как размер числа от нуля)
					// round не используем что бы всегда округлять в меньшую даже если лежим на ровно 0.5
					if($perseption>=0.5)
					{
						$x = ceil($position['x']);
					}
					else
						$x = floor($position['x']);
				}
				else
					$x = $position['x'];	
			}
			else
				$x = round($position['x'], 0);
		
			// на родной локации он всегда меньше нуля включительно
			$y = $this->object->y;
			
			// если это смежная с нашей сверху карта 
			if($position['y']>0)
			{
				if($perseption = ($y - (int)$y))
				{
					// всегда с отрицательным числом 
					// если меньше и равен 0.5 то округляется всегда вверх будет персонаж ВЫШЕ визуально
					if($perseption<=-0.5)
					{
						$y = floor($position['y']);
					}
					else
						$y = ceil($position['y']);
				}
				else
					$y = $position['y'];	
			}
			else
				$y = round($position['y'], 0);

			return new Position($x, $y, round($position['z'], 0));
		}
		else
		{
			return new Position(round($position['x'], 0), round($position['y'], 0), round($position['z'], 0));
		}
	}
	
	public function distance(Position $vector):float
	{
		$position = $this->toArray();	
		$vector = $vector->toArray();	
		return abs($position['x'] - $vector['x']) + abs($position['y'] - $vector['y']) + abs($position['z'] - $vector['z']);
	}	
	
	// на шаг двинуться ы указанном направлении
	public function next(Forward $forward):Position
	{
		$forward = new Position(STEP*$forward->x, STEP*$forward->y);
		$position = $this->add($forward);	

		return $position;
	}	
	
	// проложит луч и вернемт информацию есть ли препятсвия по пути луча/ по умолчанию препятсвия - только непроходимые области на карте (части карты)
	public function raycast(Position $to, callable $filter = null): bool
    {
		if($this->distance($to)>0)
		{
			if(empty($filter))
			{
				$filter = function (Position $new_position):bool
				{
					if(!empty(Map2D::getTile($new_position->tile())))
						return true;
					else
						return false;	
				};
			}
		
			$new_position = $this;
			$forward = $this->forward($to);

			while(($new_position = $new_position->next($forward)) && $new_position->tile() != $to->tile())
			{	
				$result = $filter($new_position);
				
				if(!is_bool($result)) throw new Error('Функция обратного вызова raycast  должна возвращать тип занчения bool');
				
				// как только не найденная локация возвращаем false (не проходимость)
				if(!$result) return false;
			}
		}
		return true;
	}	
	
	// получить направление движения к цели зная начальную ($this) и конечною (аргумент $position)  позицию
	public function forward(Position $position):Forward
	{
		// если меньше шага на нормализацию могут быть очень маленькие цифры что после округлений и делений могут дать Forward больше единицы
		if($this->distance($position)<STEP)
		{
			$round1 = $position->round();
			$round2 = $this->round();
			
			if($round1 == $round2)
				throw new Error('нельзя вычислить направление движение от цели если при округлении позиции равны (находятся на одной точке)');
			
			return $round1->subtract($round2)->normilize();
		}
		// что бы не ходить квадратами для случаев где более одного шага оставим нормализацию от реалных не округленных координат
		else
			return $position->subtract($this)->normilize();
	}
	
	public function normilize():Forward
	{
		$length = $this->length();
		$position = new Position($length, $length, $length);

		if(APP_DEBUG)
			PHP::log('Нормалицация вектора: '.(string)$this.' / длинну вектора '.$length);

		$normalize = $this->divide($position);
		return new Forward($normalize->__get('x'), $normalize->__get('y'), $normalize->__get('z'));
	}
	
	// возвращает строку для поиска в terrarian ячейки массива с указанными координатами
	public function tile(): string
    {
		return $this->round()->__toString();	
	}	
	
	public function __set(string $key, float $value):void
	{
		if(!empty($this->object))
		{		
			if(Block::$positions)
				throw new Error('Стоит запрет на изменение координат во избежание зацикливание');		
		
			// если мы меняем position проверим есть ли песочница при запуске смены координат
			if($this->object->map_id == MAP_ID && !empty(static::$_position_trigger))
			{
				$old_value = [$key=>$this->__get($key)];
			}
			
			parent::__set($key, $value);

			if(!empty(static::$_position_trigger) && $this->object->map_id == MAP_ID)
			{
				$this->trigger($old_value);
			}
		}
		else
			parent::__set($key, $value);
	}
	
	// просто запустить триггер при смене значения или как только сущность появляется на сцене (World->add)
	public function trigger(array $old_value = array())
	{
		if($this->object->map_id != MAP_ID)
			throw new Error('Нельзя создавать триггер запуска кода изменении позиций существа с другой локации ('.$this->object->map_id.') , где он должен выполнятся');

		if
		(
			(!$trace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)) 
				|| 
			(($trace[1]['class']!=World::class || $trace[1]['function']!='add') && ($trace[1]['class']!=static::class || $trace[1]['function']!='__set'))
		)
			throw new Error('Запуск кода смены позиции можно лишь ишь при добавлении существа в World или при изменении данных '.print_r($trace, true));
			
		// при добавлении в объекты и если изменилось значение - вызовем тригер 
		if(!empty(static::$_position_trigger))
		{	
			if(!World::isset($this->object->key))
				throw new Error('Сущность '.$this->object->key.' не была добавлена в мировое пространтство для вызова песочниц триггера компонентов');
			
			if(APP_DEBUG)
				$this->object->log('запустим триггер песочницы изменения позиции');
			
			try
			{
				$recover = Block::current();
				Block::$positions = true;			// запрет менять координаты
				Block::$objects = true;				// запрет добавление объектов
			
				call_user_func(static::$_position_trigger, $this->object, $old_value);
			}
			finally
			{
				Block::recover($recover);	
			}
		}	
	}
}